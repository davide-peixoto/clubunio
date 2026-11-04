<?php

// Safe to stream over SSH: stdout contains fixed labels/counts/booleans only.
try {
    $step = 'bootstrap';
    if (PHP_SAPI !== 'cli') throw new LogicException('CLI required');
    $root = '/var/www/svs-laravel/releases/20261104-clubunio-chat-delta-v1';
    if (array_slice($argv, 1) === ['--read-only', '--current-pitch']) $root = '/var/www/svs-laravel/releases/20261104-clubunio-pitch-v1';
    if (!in_array(array_slice($argv, 1), [['--read-only'], ['--read-only', '--current-pitch']], true) || PHP_OS_FAMILY !== 'Linux'
        || realpath('/var/www/svs-laravel/current') !== $root) throw new LogicException('Pinned active release required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
        || config('app.url') !== 'https://dev.clubunio.ch') throw new LogicException('Unexpected DEV database');
    $step = 'connection';
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $exists = fn ($table) => $db->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', ['clubora.'.$table])->present;
        $report = ['captured_at_utc' => gmdate('c'), 'read_only' => $db->selectOne("SELECT current_setting('transaction_read_only') = 'on' AS value")->value,
            'release' => basename($root), 'missing_tables' => [], 'table_counts' => [], 'ledger_counts' => []];
        $step = 'table-counts';
        foreach (['tenants', 'training_options', 'training_slots', 'team_seasons', 'competitions', 'facility_statuses',
            'field_availabilities', 'message_templates', 'message_template_revisions', 'mobile_push_outbox',
            'pitch_areas', 'pitch_training_exceptions', 'pitch_requests', 'pitch_blocks', 'pitch_competition_settings',
            'pitch_competition_teams', 'pitch_planning_events', 'pitch_request_idempotency'] as $table) {
            if (!$exists($table)) $report['missing_tables'][] = $table;
            else $report['table_counts'][$table] = $db->table('clubora.'.$table)->count();
        }
        foreach (['001500', '001600', '001700', '001800'] as $number) {
            $report['ledger_counts'][$number] = $db->table('clubora.migrations')->where('migration', 'like', '2026_11_04_'.$number.'_%')->count();
        }
        $step = 'own-account-push';
        $report['push'] = ['enabled' => (bool) config('clubunio-mobile-push.enabled', false),
            'delivery_enabled' => (bool) config('clubunio-mobile-push.delivery_enabled', false),
            'configured_provider_present' => count(config('clubunio-mobile-push.apps', [])) > 0,
            'own_account_active_device_session_bindings' => null,
            'own_account_registry_matching_bindings' => null];
        if ($exists('mobile_push_bindings') && $exists('mobile_push_devices') && $exists('mobile_sessions')) {
            // Technical eligibility only: no event-specific authorization, token read or provider call.
            $bindings = $db->table('clubora.mobile_push_bindings AS b')
                ->join('clubora.mobile_push_devices AS d', 'd.id', '=', 'b.device_id')
                ->join('clubora.mobile_sessions AS s', fn ($j) => $j->on('s.id', '=', 'b.session_id')->on('s.account_id', '=', 'b.account_id'))
                ->join('clubora.accounts AS a', 'a.id', '=', 'b.account_id')
                ->where('a.username', 'davide.peixoto@svsissach.ch')->where('a.active', true)->where('a.api_only', false)
                ->whereNull('b.revoked_at')->whereNull('d.revoked_at')->whereNull('s.revoked_at')
                ->whereRaw('s.expires_at > CURRENT_TIMESTAMP');
            $report['push']['own_account_active_device_session_bindings'] = (clone $bindings)->count();
            $apps = config('clubunio-mobile-push.apps', []);
            $report['push']['own_account_registry_matching_bindings'] = !$apps ? 0 : (clone $bindings)->where(function ($q) use ($apps) {
                foreach ($apps as $id => $app) $q->orWhere(function ($match) use ($id, $app) {
                    $match->where('d.app_id', $id)->where('d.platform', $app['platform'])->where('d.origin', $app['origin']);
                    if ($app['fixed_tenant_id'] === null) $match->whereNull('d.fixed_tenant_id');
                    else $match->where('d.fixed_tenant_id', $app['fixed_tenant_id']);
                });
            })->count();
        }
        $report['closed_flags'] = [];
        foreach (['clubunio-mobile.native_pitch_planning_enabled', 'clubunio-integrations.pitch_planning_test_mail_enabled',
            'clubunio-mobile-push.enabled', 'clubunio-mobile-push.delivery_enabled', 'clubunio-mobile.public_identity.mail_enabled',
            'clubunio-mobile.ai.enabled', 'clubunio-mobile.training_ai.enabled', 'clubunio-mobile.keybox.issuance_enabled',
            'clubunio-mobile.keybox.provider_enabled'] as $flag) $report['closed_flags'][$flag] = !config($flag, false);
        $step = 'source-counts';
        $sources = [];
        if ($exists('training_options')) {
            $sources['active_fields'] = $db->table('clubora.training_options')->where('category', 'field')->where('active', true)->count();
        }
        if ($exists('training_slots')) {
            $slots = $db->table('clubora.training_slots')->where('active', true);
            $sources['active_training_slots'] = (clone $slots)->count();
            $sources['training_missing_field'] = (clone $slots)->whereNull('field_id')->count();
            $sources['training_missing_time'] = (clone $slots)->where(fn ($q) => $q->whereNull('starts_at')->orWhereNull('ends_at'))->count();
        }
        if ($exists('competitions')) {
            $competitions = $db->table('clubora.competitions')->where('active', true);
            $sources['active_competitions'] = (clone $competitions)->count();
            $sources['competitions_missing_field'] = (clone $competitions)->whereNull('field_id')->count();
            $sources['competitions_missing_time'] = (clone $competitions)->whereNull('starts_at')->count();
            $sources['competitions_with_sfv_reference'] = (clone $competitions)->whereNotNull('association_match_id')->count();
            foreach (['manual', 'SFV'] as $source) $sources['competition_source_'.$source] = (clone $competitions)->where('source', $source)->count();
            $sources['competition_source_other'] = (clone $competitions)->whereNotIn('source', ['manual', 'SFV'])->count();
            $sources['competitions_without_team_mapping'] = $exists('pitch_competition_teams')
                ? (clone $competitions)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.pitch_competition_teams AS m')
                    ->whereColumn('m.tenant_id', 'competitions.tenant_id')->whereColumn('m.competition_id', 'competitions.id'))->count()
                : $sources['active_competitions'];
        }
        if ($exists('field_availabilities') && $exists('facility_statuses') && $exists('training_options')) {
            $fields = $db->table('clubora.training_options AS f')->where('f.category', 'field')->where('f.active', true);
            $sources['fields_without_active_availability'] = (clone $fields)->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('clubora.field_availabilities AS a')->whereColumn('a.tenant_id', 'f.tenant_id')
                ->whereColumn('a.field_id', 'f.id')->where('a.active', true))->count();
            $availability = $db->table('clubora.field_availabilities AS a')->where('a.active', true)
                ->leftJoin('clubora.facility_statuses AS s', fn ($join) => $join->on('s.tenant_id', '=', 'a.tenant_id')->on('s.id', '=', 'a.status_id'));
            $sources['availability_missing_status'] = (clone $availability)->whereNull('s.id')->count();
            $sources['availability_inactive_status'] = (clone $availability)->where('s.active', false)->count();
            $sources['availability_blocks_training'] = (clone $availability)->where('s.blocks_training', true)->count();
        }
        $report['sources_all_dates_all_tenants'] = $sources;
        foreach (['pitch_requests' => ['status', ['pending','approved','rejected','cancelled']],
            'pitch_blocks' => ['status', ['approved','cancelled']],
            'pitch_planning_events' => ['delivery_state', ['held','ready','sending','accepted','failed','attention']]] as $table => [$column, $values]) {
            if (!$exists($table)) continue;
            foreach ($values as $value) $report['status_counts'][$table][$value] = $db->table('clubora.'.$table)->where($column, $value)->count();
            $report['status_counts'][$table]['other'] = $db->table('clubora.'.$table)->whereNotIn($column, $values)->count();
        }
        $report['weekly_area_column_present'] = (bool) $db->selectOne("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='clubora' AND table_name='training_slots' AND column_name='area_id') AS present")->present;
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    // Never print driver exceptions: they may contain SQL/bindings or connection details.
    fwrite(STDERR, 'Pitch readiness audit failed at '.($step ?? 'guard').' ('.get_class($error).'); no row data emitted.'.PHP_EOL);
    exit(1);
}
