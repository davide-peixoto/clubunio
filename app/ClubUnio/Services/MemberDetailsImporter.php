<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MemberDetailsImporter
{
    public function run(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }
        $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'member-details-v1')->exists()) {
                return;
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $tenant) {
                throw new \LogicException('Foundation required');
            }
            foreach (['countries', 'genders', 'billing_recipients', 'families', 'family_members', 'document_types', 'member_documents'] as $table) {
                if ($db->table('clubora.'.$table)->where('tenant_id', $tenant)->exists()) {
                    throw new \LogicException('Target already populated');
                }
            }
            $countries = [];
            foreach ($db->table('legacy_prod.Countries')->get() as $r) {
                $countries[] = [
                    'tenant_id' => $tenant, 'code' => $r->ISO2, 'iso3' => $r->ISO3, 'name' => $r->NameDE, 'sort_order' => $r->SortOrder ?? 0];
            }
            $db->table('clubora.countries')->insert($countries);
            foreach (['M' => 'Männlich', 'F' => 'Weiblich', 'N' => 'Neutral'] as $code => $name) {
                $db->table('clubora.genders')->insert(compact('code', 'name') + ['tenant_id' => $tenant]);
            }
            foreach (['S' => ['Mitglied', 'email'], 'M' => ['Mutter', 'mother_email'], 'V' => ['Vater', 'father_email']] as $code => [$name,$field]) {
                $db->table('clubora.billing_recipients')->insert(['tenant_id' => $tenant, 'code' => $code, 'name' => $name, 'email_field' => $field]);
            }
            $db->table('clubora.member_statuses')->where('tenant_id', $tenant)->where('legacy_id', 1)->update(['ends_membership' => true]);
            $db->table('clubora.member_statuses')->where('tenant_id', $tenant)->where('legacy_id', 2)->update(['restores_membership' => true]);
            $country = $db->table('clubora.countries')->where('tenant_id', $tenant)->pluck('id', 'code');
            $familyCountries = $country->merge($db->table('clubora.countries')->where('tenant_id', $tenant)->pluck('id', 'name'));
            $nationality = $db->table('clubora.countries')->where('tenant_id', $tenant)->pluck('id', 'iso3');
            $gender = $db->table('clubora.genders')->where('tenant_id', $tenant)->pluck('id', 'code');
            $billing = $db->table('clubora.billing_recipients')->where('tenant_id', $tenant)->pluck('id', 'code');
            $resolve = static function ($map, $key) {
                if ($key === null || trim((string) $key) === '') {
                    return null;
                }
                if (! isset($map[trim((string) $key)])) {
                    throw new \LogicException('Unresolved reference code: '.trim((string) $key));
                }

                return $map[trim((string) $key)];
            };
            // Import only newly introduced columns; preserve earlier ClubUnio edits.
            $db->statement('CREATE TEMP TABLE member_details_import (legacy_id bigint PRIMARY KEY, data jsonb) ON COMMIT DROP');
            $rows = [];
            $strings = ['street' => 'Strasse', 'house_number' => 'HausNr', 'postal_code' => 'PLZ', 'phone_private' => 'TelefonPrivat',
                'phone_business' => 'TelefonGeschaeft', 'mobile' => 'Mobile', 'website' => 'Homepage', 'pass_number' => 'Passnummer',
                'mother_name' => 'Mutter', 'mother_email' => 'EmailMutter', 'mother_mobile' => 'MobileMutter',
                'father_name' => 'Vater', 'father_email' => 'EmailVater', 'father_mobile' => 'MobileVater',
                'legacy_photo' => 'FOTO1', 'legacy_photo2' => 'FOTO2'];
            foreach ($db->table('legacy_prod.Mitglieder')->whereNull('sdeletedAt')->get() as $r) {
                $data = [];
                foreach ($strings as $key => $source) {
                    $data[$key] = $r->$source === null ? null : (string) $r->$source;
                }
                $data += ['country_id' => $resolve($country, $r->Land), 'nationality_id' => $resolve($nationality, $r->Nationalitaet),
                    'gender_id' => $resolve($gender, $r->Geschlecht), 'billing_recipient_id' => $resolve($billing, $r->RechnungAn),
                    'exit_date' => $r->Austtritdatum, 'bexio_contact_id' => $r->bexioContactId,
                    'ahv_encrypted' => trim((string) $r->AHVNummer) === '' ? null : Crypt::encryptString($r->AHVNummer)];
                foreach (['honorary' => 'Ehrenmitglied', 'free_membership' => 'Freimitglied', 'supporter' => 'Supporter', 'sponsor' => 'Sponsor', 'spots' => 'Spots'] as $key => $source) {
                    $value = mb_strtolower(trim((string) $r->$source));
                    if (! in_array($value, ['ja', 'nein', ''], true)) {
                        throw new \LogicException('Unknown member flag');
                    }
                    $data[$key] = $value === 'ja';
                }
                $rows[] = ['legacy_id' => $r->MitgliederID, 'data' => json_encode($data, JSON_THROW_ON_ERROR)];
            }
            foreach (array_chunk($rows, 150) as $batch) {
                $db->table('pg_temp.member_details_import')->insert($batch);
            }
            $sets = [];
            foreach (array_keys($strings) as $key) {
                $sets[] = "$key=x.data->>'$key'";
            }
            foreach (['country_id', 'nationality_id', 'gender_id', 'billing_recipient_id', 'bexio_contact_id'] as $key) {
                $sets[] = "$key=(x.data->>'$key')::bigint";
            }
            foreach (['honorary', 'free_membership', 'supporter', 'sponsor', 'spots'] as $key) {
                $sets[] = "$key=(x.data->>'$key')::boolean";
            }
            $sets[] = "exit_date=(x.data->>'exit_date')::date";
            $sets[] = "ahv_encrypted=x.data->>'ahv_encrypted'";
            $db->update('UPDATE clubora.members m SET '.implode(',', $sets).' FROM pg_temp.member_details_import x WHERE m.tenant_id=? AND m.legacy_id=x.legacy_id', [$tenant]);
            foreach ($db->table('legacy_prod.Familie')->get() as $r) {
                $db->table('clubora.families')->insert([
                    'tenant_id' => $tenant, 'legacy_id' => $r->FamilieID, 'name' => $r->FamilieName ?? 'Familie '.$r->FamilieID,
                    'street' => $r->FamilieStrasse, 'postal_code' => $r->FamiliePLZ, 'city' => $r->FamilieOrt,
                    'country_id' => $resolve($familyCountries, $r->FamilieLand), 'active' => $r->sdeletedAt === null]);
            }
            $families = $db->table('clubora.families')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $members = $db->table('clubora.members')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            foreach ($db->table('legacy_prod.FamilieMitglieder')->get() as $r) {
                $db->table('clubora.family_members')->insert([
                    'tenant_id' => $tenant, 'family_id' => $resolve($families, $r->FamilieID), 'member_id' => $resolve($members, $r->FamilieMID),
                    'discount' => $r->FamilieRabatt ?? 0, 'notes' => $r->Bemerkungen, 'active' => $r->sdeletedAt === null]);
            }
            foreach ($db->table('legacy_prod.DokumentTyp')->get() as $r) {
                $db->table('clubora.document_types')->insert([
                    'tenant_id' => $tenant, 'legacy_id' => $r->DokumentTypID, 'code' => $r->Code, 'name' => $r->Bezeichnung, 'active' => $r->Aktiv]);
            }
            $types = $db->table('clubora.document_types')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            foreach ($db->table('legacy_prod.MitgliederAnhaenge')->get() as $r) {
                $db->table('clubora.member_documents')->insert([
                    'tenant_id' => $tenant, 'legacy_id' => $r->AnhangID, 'member_id' => $resolve($members, $r->MitgliederID),
                    'document_type_id' => $resolve($types, $r->DokumentTypID), 'filename' => $r->FileName,
                    'original_filename' => $r->OriginalFileName, 'storage_path' => $r->StoragePath, 'file_size' => $r->FileSize,
                    'content_type' => $r->ContentType, 'source_created_at' => $r->CreatedAt, 'active' => $r->sdeletedAt === null]);
            }
            $db->table('clubora.imports')->insert(['name' => 'member-details-v1']);
        });
    }
}
