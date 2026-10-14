<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;

class TeamAssignmentsImporter
{
    public function run(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }
        $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'team-assignments-v1')->exists()) {
                return;
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $tenant) {
                throw new \LogicException('Foundation required');
            }
            foreach (['team_players', 'team_staff', 'technical_leads', 'staff_functions'] as $table) {
                if ($db->table('clubora.'.$table)->where('tenant_id', $tenant)->exists()) {
                    throw new \LogicException('Target already populated');
                }
            }
            foreach ($db->table('legacy_prod.TeamSatffFunktion')->get() as $r) {
                $db->table('clubora.staff_functions')->insert([
                    'tenant_id' => $tenant, 'legacy_id' => $r->TeamSatffFunktionID, 'code' => 'legacy_'.$r->TeamSatffFunktionID,
                    'name' => $r->Bezeichnung, 'active' => $r->sdeletedAt === null]);
            }
            $members = $db->table('clubora.members')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $seasons = $db->table('clubora.team_seasons')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            $functions = $db->table('clubora.staff_functions')->where('tenant_id', $tenant)->pluck('id', 'legacy_id');
            foreach ([['TeamList', 'team_players', 'TeamlistID'], ['TeamStaff', 'team_staff', 'TeamStaffID'], ['TechnischeLeiterTeams', 'technical_leads', 'TechnischeLeiterTeamID']] as [$source,$target,$key]) {
                $rows = [];
                foreach ($db->table('legacy_prod.'.$source)->get() as $r) {
                    if (! isset($members[$r->MitgliederID],$seasons[$r->TeamSaisonID])) {
                        throw new \LogicException('Unresolved team assignment');
                    }
                    $data = ['tenant_id' => $tenant, 'legacy_id' => $r->$key, 'member_id' => $members[$r->MitgliederID],
                        'team_season_id' => $seasons[$r->TeamSaisonID], 'active' => $r->sdeletedAt === null && ($r->Aktiv ?? true)];
                    if ($target === 'team_players') {
                        $data['legacy_invoice_position_id'] = $r->PositionsID;
                    }
                    if ($target === 'team_staff') {
                        if ($r->TeamSatffFunktionID !== null && ! isset($functions[$r->TeamSatffFunktionID])) {
                            throw new \LogicException('Unresolved staff function');
                        }
                        $data['staff_function_id'] = $r->TeamSatffFunktionID === null ? null : $functions[$r->TeamSatffFunktionID];
                    }
                    $rows[] = $data;
                }
                foreach (array_chunk($rows, 200) as $batch) {
                    $db->table('clubora.'.$target)->insert($batch);
                }
            }
            $db->table('clubora.imports')->insert(['name' => 'team-assignments-v1']);
        });
    }
}
