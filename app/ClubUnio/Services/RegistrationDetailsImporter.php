<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class RegistrationDetailsImporter
{
    public function run(): int
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }

        return $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'registration-details-v2')->exists()) {
                return 0;
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->sole()->id;
            $count = 0;
            $maps = [];
            foreach (['country_id' => ['countries', 'code'], 'nationality_id' => ['countries', 'iso3'],
                'gender_id' => ['genders', 'code'], 'billing_recipient_id' => ['billing_recipients', 'code']] as $field => [$table, $key]) {
                $maps[$field] = $db->table('clubora.'.$table)->where('tenant_id', $tenant)->pluck('id', $key);
            }
            foreach ($db->table('legacy_prod.MitgliederAnmeldungen')->whereNull('sdeletedAt')->get() as $source) {
                $target = $db->table('clubora.registrations')->where('tenant_id', $tenant)->where('legacy_id', $source->AnmeldungID)->sole();
                $data = [];
                foreach (['street' => 'Strasse', 'house_number' => 'HausNr', 'postal_code' => 'PLZ', 'mobile' => 'Mobile',
                    'phone_private' => 'TelefonPrivat', 'mother_name' => 'Mutter', 'mother_email' => 'EmailMutter',
                    'mother_mobile' => 'MobileMutter', 'father_name' => 'Vater', 'father_email' => 'EmailVater',
                    'father_mobile' => 'MobileVater', 'notes' => 'Bemerkung', 'ahv' => 'AHVNummer',
                    'birth_place' => 'Geburtsort', 'previous_club' => 'VorvereinName',
                    'previous_club_country_type' => 'VorvereinLandTyp', 'has_previous_club' => 'HatVorverein'] as $field => $column) {
                    $data[$field] = trim((string) ($source->$column ?? '')) ?: null;
                }
                foreach (['country_id' => 'Land', 'nationality_id' => 'Nationalitaet', 'gender_id' => 'Geschlecht',
                    'billing_recipient_id' => 'RechnungAn'] as $field => $column) {
                    $code = trim((string) ($source->$column ?? ''));
                    if ($code !== '' && ! isset($maps[$field][$code])) {
                        throw new \LogicException('Unresolved registration lookup: '.$field.' (registration '.$source->AnmeldungID.')');
                    }
                    $data[$field] = $code === '' ? null : $maps[$field][$code];
                }
                if ($target->applicant_encrypted !== null) {
                    $existing = json_decode(Crypt::decryptString($target->applicant_encrypted), true, 512, JSON_THROW_ON_ERROR);
                    // v1 omitted these source fields. Preserve every previously editable value.
                    foreach (['notes', 'birth_place', 'previous_club', 'previous_club_country_type', 'has_previous_club'] as $key) {
                        $existing[$key] ??= $data[$key];
                    }
                    $data = $existing;
                }
                $db->table('clubora.registrations')->where('tenant_id', $tenant)->where('id', $target->id)
                    ->update(['applicant_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'details_complete' => true]);
                foreach (['portrait' => 'PassfotoUpload', 'identity' => 'AusweisUpload'] as $kind => $column) {
                    foreach (array_unique(array_filter(array_map('trim', explode(',', (string) ($source->$column ?? ''))))) as $file) {
                        if (str_starts_with($file, 'upload/anmeldungen/')) {
                            $file = substr($file, strlen('upload/anmeldungen/'));
                        }
                        if ($file === 'vorlage/leeres_portrait.jpeg') {
                            continue;
                        }
                        app(AzureDocumentReader::class)->blobPath('upload/anmeldungen/', $file);
                        $db->table('clubora.registration_documents')->insertOrIgnore([
                            'tenant_id' => $tenant, 'registration_id' => $target->id, 'kind' => $kind,
                            'filename' => $file, 'storage_path' => 'upload/anmeldungen/',
                        ]);
                    }
                }
                $count++;
            }
            $db->table('clubora.imports')->insert(['name' => 'registration-details-v2']);

            return $count;
        });
    }
}
