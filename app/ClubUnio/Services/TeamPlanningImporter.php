<?php

namespace App\ClubUnio\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TeamPlanningImporter
{
    public function run(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }
        $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(2026101222)');
            if ($db->table('clubora.imports')->where('name', 'team-planning-v1')->exists()) {
                return;
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $tenant) {
                throw new \LogicException('Foundation required');
            }
            foreach (['team_events', 'team_participations', 'team_absences'] as $table) {
                if ($db->table('clubora.'.$table)->where('tenant_id', $tenant)->exists()) {
                    throw new \LogicException('Planning target already populated');
                }
            }
            $teams = $db->table('clubora.team_seasons')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $members = $db->table('clubora.members')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $events = [];
            // Legacy event times are Swiss wall-clock values; database timestamps are UTC.
            $date = fn ($value) => $value ? Time::parse($value, 'Europe/Zurich')->toIso8601String() : null;
            $encrypt = fn ($value) => $value === null || $value === '' ? null : Crypt::encryptString($value);
            foreach ($db->table('legacy_prod.TeamTermine')->whereNull('sdeletedAt')->get() as $r) {
                if (! isset($teams[$r->TeamSaisonID])) {
                    throw new \LogicException('Unresolved planning team');
                }
                $events[$r->TeamTerminID] = $db->table('clubora.team_events')->insertGetId(['tenant_id' => $tenant, 'legacy_id' => $r->TeamTerminID,
                    'team_season_id' => $teams[$r->TeamSaisonID], 'title' => $r->Titel, 'kind' => $r->Terminart,
                    'starts_at' => $date($r->DatumVon), 'ends_at' => $date($r->DatumBis), 'deadline_at' => $date($r->AnmeldeFrist),
                    'location' => $r->Ort, 'description' => $r->Beschreibung, 'mode' => $r->TeilnahmeModus, 'status' => $r->Status, 'capacity' => $r->MaxTeilnehmer]);
            }
            foreach ($db->table('legacy_prod.TeamAbsenzen')->whereNull('sdeletedAt')->get() as $r) {
                if (! isset($members[$r->MitgliederID])) {
                    throw new \LogicException('Unresolved absence member');
                }
                $db->table('clubora.team_absences')->insert(['tenant_id' => $tenant, 'legacy_id' => $r->AbsenzID, 'member_id' => $members[$r->MitgliederID],
                    'starts_on' => substr($r->DatumVon, 0, 10), 'ends_on' => substr($r->DatumBis, 0, 10), 'kind' => $r->Absenzart, 'note_encrypted' => $encrypt($r->Bemerkung), 'revision' => $r->Revision]);
            }
            foreach ($db->table('legacy_prod.TeamTerminTeilnahmen')->whereNull('sdeletedAt')->get() as $r) {
                if (! isset($events[$r->TeamTerminID])) {
                    continue;
                }
                if (! isset($members[$r->MitgliederID])) {
                    throw new \LogicException('Unresolved participation member');
                }
                $db->table('clubora.team_participations')->insert(['tenant_id' => $tenant, 'event_id' => $events[$r->TeamTerminID], 'member_id' => $members[$r->MitgliederID],
                    'status' => $r->TeilnahmeStatus, 'attendance' => $r->AnwesenheitsStatus, 'comment_encrypted' => $encrypt($r->Kommentar),
                    'source' => $r->AbsenzID ? 'absence' : 'trainer',
                    'original_status' => $r->AbsenzOriginalTeilnahmeStatus, 'original_comment_encrypted' => $encrypt($r->AbsenzOriginalKommentar)]);
            }
            $db->table('clubora.imports')->insert(['name' => 'team-planning-v1']);
        });
    }
}
