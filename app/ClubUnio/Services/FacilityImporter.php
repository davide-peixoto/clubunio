<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;

class FacilityImporter
{
    public function run(): array
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }

        return $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'facilities-v1')->exists()) {
                return ['already_imported' => true];
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $tenant) {
                throw new \LogicException('Missing tenant');
            }
            foreach (['competitions', 'signage_notices', 'field_availabilities', 'facility_statuses'] as $table) {
                if ($db->table('clubora.'.$table)->where('tenant_id', $tenant)->exists()) {
                    throw new \LogicException('Facility target is not empty');
                }
            }
            $options = $db->table('clubora.training_options')->where('tenant_id', $tenant)->get()->keyBy(fn ($o) => $o->category.':'.$o->code);
            $lookup = function ($category, $code) use ($options) {
                if ($code === null || trim((string) $code) === '') {
                    return null;
                }
                $option = $options->get($category.':'.trim((string) $code));
                if (! $option) {
                    throw new \LogicException('Unmapped facility option: '.$category);
                }

                return $option->id;
            };
            $status = [];
            foreach (['O' => ['Offen', false], 'G' => ['Gesperrt', true]] as $code => [$name, $blocked]) {
                $status[$code] = $db->table('clubora.facility_statuses')->insertGetId(['tenant_id' => $tenant, 'code' => $code, 'name' => $name, 'blocks_training' => $blocked]);
            }
            $ini = $db->table('legacy_prod.iniParameter')->where('iniID', 1)->first();
            foreach (['H', 'K', 'T'] as $index => $field) {
                $code = $ini->{'StatusPlatz'.($index + 1)} ?? null;
                if (! isset($status[$code])) {
                    throw new \LogicException('Unknown field status');
                }
                $db->table('clubora.field_availabilities')->insert(['tenant_id' => $tenant, 'field_id' => $lookup('field', $field), 'status_id' => $status[$code]]);
            }
            $count = 0;
            foreach ($db->table('legacy_prod.Wettkaempfe')->orderBy('SpielD')->get() as $row) {
                $field = match (trim((string) $row->Spielfeld)) {
                    'Hauptplatz', 'Hauptfeld' => 'H', 'Kunstrasen' => 'K', 'Trainingsplatz', 'Trainingsfeld' => 'T', '' => null, default => throw new \LogicException('Unmapped competition field')
                };
                $time = trim((string) $row->Zeit);
                if ($time !== '' && preg_match('/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?$/', $time, $m)) {
                    $time = sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
                }
                if ($time !== '' && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time)) {
                    throw new \LogicException('Invalid competition time');
                }
                $db->table('clubora.competitions')->insert(['tenant_id' => $tenant, 'legacy_id' => $row->SpielD, 'plays_on' => $row->Datum, 'starts_at' => $time ?: null, 'host_name' => $row->Gastgeber ?? '', 'guest_name' => $row->Gast ?? '', 'host_category' => $row->GastgeberKategorie, 'guest_category' => $row->GastKategorie, 'host_club_number' => $row->GastgeberVNR, 'guest_club_number' => $row->GastVNR, 'field_id' => $lookup('field', $field), 'host_room_id' => $lookup('room', $row->GastgeberGarderobe), 'guest_room_id' => $lookup('room', $row->GastGarderobe), 'host_side_id' => $lookup('side', $row->GastgeberGarderobeSeite), 'guest_side_id' => $lookup('side', $row->GastGarderobeSeite), 'remarks' => $row->Bemerkungen, 'association_match_id' => $row->VerbandMatchID, 'source' => $row->Quelle ?: 'manual', 'show_on_signage' => (bool) $row->ShowOnSignage, 'imported_at' => $row->ImportedAt, 'synced_at' => $row->LastSyncAt, 'active' => $row->sdeletedAt === null]);
                $count++;
            }
            $notices = 0;
            foreach ($db->table('legacy_prod.MeldungenSignage')->get() as $row) {
                $db->table('clubora.signage_notices')->insert(['tenant_id' => $tenant, 'legacy_id' => $row->MeldungID, 'shows_on' => $row->Datum, 'title' => $row->Betreff ?? '', 'body' => strip_tags((string) $row->Meldung), 'active' => $row->sdeletedAt === null]);
                $notices++;
            }
            $db->table('clubora.imports')->insert(['name' => 'facilities-v1']);

            return ['competitions' => $count, 'notices' => $notices, 'fields' => 3];
        });
    }
}
