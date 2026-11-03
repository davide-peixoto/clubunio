<?php

namespace App\ClubUnio\Services;

use Illuminate\Database\Connection;

/** Read-only checks against the imported snapshot, never the live PHPMaker DB. */
final class SeasonReferenceAudit
{
    public static function mappings(): array
    {
        return [
            ['team_seasons', 'season_id', 'TeamsSaison', 'TeamSaisonID', 'SaisonID', 'seasons'],
            ['club_events', 'season_id', 'Events', 'EventID', 'SaisonID', 'seasons'],
            ['invoices', 'season_id', 'Rechnung', 'RechnungsID', 'SaisonID', 'seasons'],
            ['expenses', 'season_id', 'Spesenbelege', 'SpesenID', 'SaisonID', 'seasons'],
            ['expenses', 'team_season_id', 'Spesenbelege', 'SpesenID', 'TeamSaisonID', 'team_seasons'],
            ['contribution_rules', 'season_id', 'Beitragstabelle', 'BeitragID', 'SaisonID', 'seasons'],
            ['registrations', 'team_season_id', 'MitgliederAnmeldungen', 'AnmeldungID', 'TeamSaisonID', 'team_seasons'],
            ['team_players', 'team_season_id', 'TeamList', 'TeamlistID', 'TeamSaisonID', 'team_seasons'],
            ['team_staff', 'team_season_id', 'TeamStaff', 'TeamStaffID', 'TeamSaisonID', 'team_seasons'],
            ['technical_leads', 'team_season_id', 'TechnischeLeiterTeams', 'TechnischeLeiterTeamID', 'TeamSaisonID', 'team_seasons'],
            ['training_slots', 'team_season_id', 'Trainingszeiten', 'TrainingID', 'TeamSaisonID', 'team_seasons'],
            ['team_events', 'team_season_id', 'TeamTermine', 'TeamTerminID', 'TeamSaisonID', 'team_seasons'],
            ['bus_reservations', 'team_season_id', 'BusReservierungen', 'ResID', 'TeamSaisonID', 'team_seasons'],
            ['invoice_lines', 'invoice_id', 'Positionen', 'PositionsID', 'RechnungsID', 'invoices'],
            ['expense_lines', 'expense_id', 'SpesenPositionen', 'SpesenPositionID', 'SpesenID', 'expenses'],
            ['event_guests', 'club_event_id', 'Gaeste', 'VisitorID', 'EventID', 'club_events'],
            ['legacy_training_plans', 'suggested_team_season_id', 'TrainingPlannerAuditLog', 'TrainingPlannerAuditLogID', 'SharedTeamSaisonID', 'team_seasons', 'source_id'],
        ];
    }

    public function inspect(Connection $db, int $tenant): array
    {
        $references = $db->select(<<<'SQL'
SELECT cols.table_name, cols.column_name
FROM information_schema.columns cols
JOIN information_schema.tables t ON t.table_schema=cols.table_schema AND t.table_name=cols.table_name AND t.table_type='BASE TABLE'
WHERE cols.table_schema='clubora' AND cols.column_name LIKE '%season%id'
ORDER BY cols.table_name, cols.column_name
SQL);
        $constraints = $db->select(<<<'SQL'
SELECT child.relname AS table_name, a.attname AS column_name, parent.relname AS parent_table,
 c.convalidated AS validated,
 EXISTS(SELECT 1 FROM unnest(c.conkey,c.confkey) AS tk(child_num,parent_num)
 JOIN pg_attribute ta ON ta.attrelid=c.conrelid AND ta.attnum=tk.child_num
 JOIN pg_attribute tp ON tp.attrelid=c.confrelid AND tp.attnum=tk.parent_num
 WHERE ta.attname='tenant_id' AND tp.attname='tenant_id') AS tenant_bound
FROM pg_constraint c
JOIN pg_class child ON child.oid=c.conrelid JOIN pg_namespace ns ON ns.oid=child.relnamespace
JOIN pg_class parent ON parent.oid=c.confrelid JOIN pg_namespace pn ON pn.oid=parent.relnamespace
CROSS JOIN LATERAL unnest(c.conkey,c.confkey) AS k(child_num,parent_num)
JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.child_num
JOIN pg_attribute pa ON pa.attrelid=c.confrelid AND pa.attnum=k.parent_num
WHERE c.contype='f' AND ns.nspname='clubora' AND pn.nspname='clubora'
 AND parent.relname IN ('seasons','team_seasons') AND a.attname LIKE '%season%id' AND pa.attname='id'
SQL);
        $result = ['tenant_id' => $tenant, 'references' => [], 'snapshot_mappings' => [], 'views' => []];
        foreach ($references as $ref) {
            if ($ref->table_name === 'sfv_schedule' && $ref->column_name === 'season_id') {
                $result['external_season_fields'][] = 'sfv_schedule.season_id';
                continue;
            }
            $parent = str_contains($ref->column_name, 'team_season') ? 'team_seasons' : 'seasons';
            $base = $db->table('clubora.'.$ref->table_name.' as c')->where('c.tenant_id', $tenant)->whereNotNull('c.'.$ref->column_name);
            $invalid = (clone $base)->leftJoin('clubora.'.$parent.' as p', fn ($j) => $j
                ->on('p.id', '=', 'c.'.$ref->column_name)->on('p.tenant_id', '=', 'c.tenant_id'))->whereNull('p.id');
            $fk = collect($constraints)->contains(fn ($c) => $c->table_name === $ref->table_name && $c->column_name === $ref->column_name
                && $c->parent_table === $parent && $c->validated && $c->tenant_bound);
            $result['references'][] = ['field' => $ref->table_name.'.'.$ref->column_name, 'parent' => $parent,
                'linked_rows' => $base->count(), 'invalid_or_cross_tenant' => $invalid->count(), 'validated_tenant_fk' => $fk];
        }
        foreach (self::mappings() as $mapping) {
            $result['snapshot_mappings'][] = $this->compareMapping($db, $tenant, $mapping);
        }
        $expenses = $db->table('clubora.expenses as e')->where('e.tenant_id', $tenant)->whereNotNull('e.season_id')->whereNotNull('e.team_season_id')
            ->join('clubora.team_seasons as ts', fn ($j) => $j->on('ts.id', '=', 'e.team_season_id')->on('ts.tenant_id', '=', 'e.tenant_id'));
        $result['expense_team_season_disagreement'] = (clone $expenses)->whereColumn('e.season_id', '!=', 'ts.season_id')->count();
        $result['source_expense_team_season_disagreement'] = $db->table('legacy_prod.Spesenbelege as e')
            ->join('legacy_prod.TeamsSaison as ts', 'ts.TeamSaisonID', '=', 'e.TeamSaisonID')->whereColumn('e.SaisonID', '!=', 'ts.SaisonID')->count();
        $result['same_expense_disagreement_in_snapshot'] = (clone $expenses)->whereColumn('e.season_id', '!=', 'ts.season_id')
            ->join('legacy_prod.Spesenbelege as s', 's.SpesenID', '=', 'e.legacy_id')
            ->join('legacy_prod.TeamsSaison as st', 'st.TeamSaisonID', '=', 's.TeamSaisonID')->whereColumn('s.SaisonID', '!=', 'st.SaisonID')->count();
        $seasons = $db->table('clubora.seasons as c')->where('c.tenant_id', $tenant)->whereNotNull('c.legacy_id')
            ->join('legacy_prod.Saison as s', 's.SaisonID', '=', 'c.legacy_id');
        $result['season_catalog'] = ['matched' => (clone $seasons)->count(),
            'label_differences' => (clone $seasons)->whereRaw('c.name IS DISTINCT FROM s."Saison"')->count(),
            'source_missing_target' => $db->table('legacy_prod.Saison as s')->leftJoin('clubora.seasons as c', fn ($j) => $j
                ->on('c.legacy_id', '=', 's.SaisonID')->where('c.tenant_id', $tenant))->whereNull('c.id')->count()];
        $labels = $db->table('clubora.team_seasons as ts')->where('ts.tenant_id', $tenant)->whereNotNull('ts.legacy_id')
            ->join('clubora.seasons as s', fn ($j) => $j->on('s.id', '=', 'ts.season_id')->on('s.tenant_id', '=', 'ts.tenant_id'))
            ->join('clubora.teams as t', fn ($j) => $j->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'));
        $result['team_lookup_label_differences'] = $labels->whereRaw("ts.label IS DISTINCT FROM (t.name || ' / ' || s.name)")->count();
        $plans = $db->table('clubora.training_plans as p')->where('p.tenant_id', $tenant)->whereNotNull('p.legacy_source_id')
            ->leftJoin('clubora.legacy_training_plans as l', fn ($j) => $j->on('l.id', '=', 'p.legacy_source_id')->on('l.tenant_id', '=', 'p.tenant_id'));
        $result['historical_training_plans'] = ['linked' => (clone $plans)->count(),
            'staging_team_disagreements' => (clone $plans)->whereRaw('p.team_season_id IS DISTINCT FROM l.team_season_id')->count()];
        $participations = $db->table('legacy_prod.TeamTerminTeilnahmen as s')->whereNull('s.sdeletedAt')
            ->join('clubora.team_events as e', fn ($j) => $j->on('e.legacy_id', '=', 's.TeamTerminID')->where('e.tenant_id', $tenant))
            ->join('clubora.members as m', fn ($j) => $j->on('m.legacy_id', '=', 's.MitgliederID')->where('m.tenant_id', $tenant))
            ->leftJoin('clubora.team_participations as p', fn ($j) => $j->on('p.event_id', '=', 'e.id')->on('p.member_id', '=', 'm.id')->where('p.tenant_id', $tenant));
        $result['planning_participation_pairs'] = ['source_pairs' => (clone $participations)->count(),
            'missing_migrated_pairs' => (clone $participations)->whereNull('p.id')->count()];
        $configured = app(\App\ClubUnio\Mobile\MobileSeasonScope::class)->current(\App\ClubUnio\Models\Tenant::findOrFail($tenant));
        $result['configured_lookup'] = $configured ? ['internal_id' => (int) $configured->id, 'legacy_id' => $configured->legacy_id,
            'name' => $configured->name] : null;
        foreach ($db->select("SELECT table_name,view_definition FROM information_schema.views WHERE table_schema='clubora' ORDER BY table_name") as $view) {
            $result['views'][] = ['name' => $view->table_name, 'legacy_dependency' => str_contains(strtolower($view->view_definition ?? ''), 'legacy_prod'),
                'season_related' => str_contains(strtolower($view->view_definition ?? ''), 'season')];
        }

        return $result;
    }

    public function compareMapping(Connection $db, int $tenant, array $mapping): array
    {
        if (! in_array($mapping, self::mappings(), true)) { throw new \InvalidArgumentException('Unknown migration mapping'); }
        [$table, $column, $source, $sourceKey, $sourceField, $parent] = $mapping;
        $targetKey = $mapping[6] ?? 'legacy_id';
        $base = $db->table('clubora.'.$table.' as c')->where('c.tenant_id', $tenant)->whereNotNull('c.'.$targetKey);
        $joined = (clone $base)->join('legacy_prod.'.$source.' as s', 's.'.$sourceKey, '=', 'c.'.$targetKey)
            ->leftJoin('clubora.'.$parent.' as p', fn ($j) => $j->on('p.legacy_id', '=', 's.'.$sourceField)->on('p.tenant_id', '=', 'c.tenant_id'));
        $missing = $db->table('legacy_prod.'.$source.' as s')->leftJoin('clubora.'.$table.' as c', fn ($j) => $j
            ->on('c.'.$targetKey, '=', 's.'.$sourceKey)->where('c.tenant_id', $tenant))->whereNull('c.id');
        if ($table === 'team_events') { $missing->whereNull('s.sdeletedAt'); }

        $result = ['field' => $table.'.'.$column, 'source' => $source.'.'.$sourceField,
            'compared_rows' => (clone $joined)->count(),
            'different_reference' => (clone $joined)->whereRaw($db->getQueryGrammar()->wrap('c.'.$column).' IS DISTINCT FROM p.id')->count(),
            'unresolved_source_reference' => (clone $joined)->whereNotNull('s.'.$sourceField)->whereNull('p.id')->count(),
            'source_rows_without_target' => $missing->count(),
            'imported_rows_without_snapshot' => (clone $base)->leftJoin('legacy_prod.'.$source.' as s', 's.'.$sourceKey, '=', 'c.'.$targetKey)->whereNull('s.'.$sourceKey)->count()];
        $recognized = 0;
        if ($table === 'expenses' && $column === 'team_season_id' && $result['different_reference'] > 0) {
            $changed = (clone $joined)->whereRaw('c.team_season_id IS DISTINCT FROM p.id')
                ->get(['c.id', 'c.team_season_id', 'p.id as source_team_season_id'])->keyBy('id');
            $verified = [];
            foreach ($db->table('clubora.object_changes')->where('tenant_id', $tenant)->where('object_type', 'expenses')
                ->whereIn('object_id', $changed->keys())->get(['object_id', 'before_value', 'after_value']) as $audit) {
                $before = json_decode($audit->before_value ?? '', true); $after = json_decode($audit->after_value ?? '', true);
                $row = $changed->get($audit->object_id);
                if (($after['correction_key'] ?? null) === 'expense_team_season_fix_20261103'
                    && isset($before['team_season_id'], $after['team_season_id'])
                    && $row->source_team_season_id !== null
                    && (int) $before['team_season_id'] === (int) $row->source_team_season_id
                    && (int) $after['team_season_id'] === (int) $row->team_season_id) {
                    $verified[$audit->object_id] = true;
                }
            }
            $recognized = count($verified);
        }
        $result['recognized_correction_differences'] = $recognized;
        $result['unexplained_reference_differences'] = $result['different_reference'] - $recognized;

        return $result;
    }
}
