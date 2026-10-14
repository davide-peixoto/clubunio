<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;

class ClubObjectsImporter
{
    public function run(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }
        $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'club-objects-v1')->exists()) {
                return;
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $tenant) {
                throw new \LogicException('Foundation required');
            }
            foreach (['member_statuses', 'member_types', 'registration_statuses', 'teams', 'seasons', 'team_seasons', 'registrations'] as $table) {
                if ($db->table('clubora.'.$table)->where('tenant_id', $tenant)->exists()) {
                    throw new \LogicException('Target already populated');
                }
            }
            foreach ([['MitgliederStatus', 'member_statuses', 'StatusID', 'Status'], ['MitgliederArt', 'member_types', 'MitgliederArtID', 'MitgliederArt'], ['AnmeldungStatus', 'registration_statuses', 'AnmeldungStatusID', 'Bezeichnung']] as [$source,$target,$id,$label]) {
                foreach ($db->table('legacy_prod.'.$source)->get() as $r) {
                    $db->table('clubora.'.$target)->insert(['tenant_id' => $tenant, 'legacy_id' => $r->$id,
                        'code' => $r->StatusCode ?? 'legacy_'.$r->$id, 'name' => $r->$label,
                        'sort_order' => $r->Sortierung ?? $r->$id,
                        'active' => property_exists($r, 'Aktiv') ? (bool) $r->Aktiv : ($r->sdeletedAt === null)]);
                }
            }
            foreach ($db->table('legacy_prod.Saison')->get() as $r) {
                $db->table('clubora.seasons')->insert([
                    'tenant_id' => $tenant, 'legacy_id' => $r->SaisonID, 'name' => $r->Saison, 'active' => $r->sdeletedAt === null]);
            }
            foreach ($db->table('legacy_prod.Teams')->get() as $r) {
                $db->table('clubora.teams')->insert([
                    'tenant_id' => $tenant, 'legacy_id' => $r->TeamID, 'name' => $r->Mannschaftsname,
                    'description' => $r->Bezeichnung, 'sfv_team_id' => $r->SFVTeamID,
                    'laundry_allowance' => $r->DressEntschaedigung, 'active' => $r->sdeletedAt === null]);
            }
            // Joins use original IDs; fail rather than silently omit broken references.
            $teamSeasons = $db->table('legacy_prod.TeamsSaison as ts')
                ->leftJoin('clubora.teams as t', fn ($j) => $j->on('t.legacy_id', '=', 'ts.TeamID')->where('t.tenant_id', $tenant))
                ->leftJoin('clubora.seasons as s', fn ($j) => $j->on('s.legacy_id', '=', 'ts.SaisonID')->where('s.tenant_id', $tenant))
                ->get(['ts.*', 't.id as new_team_id', 's.id as new_season_id', 't.name as team_name', 's.name as season_name']);
            foreach ($teamSeasons as $r) {
                if (! $r->new_team_id || ! $r->new_season_id) {
                    throw new \LogicException('Unresolved team-season reference');
                }
                $db->table('clubora.team_seasons')->insert(['tenant_id' => $tenant, 'legacy_id' => $r->TeamSaisonID,
                    'team_id' => $r->new_team_id, 'season_id' => $r->new_season_id, 'label' => $r->team_name.' / '.$r->season_name, 'active' => $r->sdeletedAt === null]);
            }
            $statuses = $db->table('clubora.member_statuses')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $types = $db->table('clubora.member_types')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            foreach ($db->table('legacy_prod.Mitglieder')->whereNull('sdeletedAt')->get(['MitgliederID', 'Status', 'MitgliederArt', 'Geburtsdatum']) as $r) {
                if (($r->Status !== null && ! isset($statuses[$r->Status])) || ($r->MitgliederArt !== null && ! isset($types[$r->MitgliederArt]))) {
                    throw new \LogicException('Unresolved member lookup');
                }
            }
            $db->update('UPDATE clubora.members m SET status_id=s.id,type_id=t.id,birth_date=r."Geburtsdatum"
                FROM legacy_prod."Mitglieder" r
                LEFT JOIN clubora.member_statuses s ON s.legacy_id=r."Status" AND s.tenant_id=?
                LEFT JOIN clubora.member_types t ON t.legacy_id=r."MitgliederArt" AND t.tenant_id=?
                WHERE m.tenant_id=? AND m.legacy_id=r."MitgliederID"', [$tenant, $tenant, $tenant]);
            $statuses = $db->table('clubora.registration_statuses')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $teams = $db->table('clubora.team_seasons')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            foreach ($db->table('legacy_prod.MitgliederAnmeldungen')->whereNull('sdeletedAt')->get() as $r) {
                if (($r->AnmeldungStatusID !== null && ! isset($statuses[$r->AnmeldungStatusID])) || ($r->TeamSaisonID !== null && ! isset($teams[$r->TeamSaisonID]))) {
                    throw new \LogicException('Unresolved registration lookup');
                }
                $db->table('clubora.registrations')->insert(['tenant_id' => $tenant, 'legacy_id' => $r->AnmeldungID,
                    'first_name' => $r->Vorname, 'last_name' => $r->Nachname, 'email' => $r->Email, 'city' => $r->Ort,
                    'birth_date' => $r->Geburtsdatum, 'submitted_at' => $r->EingereichtAm,
                    'status_id' => $r->AnmeldungStatusID === null ? null : $statuses[$r->AnmeldungStatusID],
                    'team_season_id' => $r->TeamSaisonID === null ? null : $teams[$r->TeamSaisonID]]);
            }
            $db->table('clubora.imports')->insert(['name' => 'club-objects-v1']);
        });
    }
}
