<?php
declare(strict_types=1);

// Explicit operator action; never part of the recurring PHPMaker delta runner.
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! in_array($argv[1], ['--preview', '--apply', '--test'], true)
        || ($argv[2] !== 'clubunio_prod' && ! preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/', $argv[2]))) {
        throw new LogicException('Use --preview|--apply|--test with explicit PROD or isolated rehearsal DB');
    }
    $root = dirname(__DIR__);
    // Shared live vendor, but the new service/resource are explicitly loaded from this release.
    $live = getenv('CLUBUNIO_TRAINING_LIVE_ROOT') ?: $root;
    $loader = require $live.'/vendor/autoload.php';
    $loader->addClassMap([
        App\ClubUnio\Services\HistoricalTrainingPlanAdoption::class => $root.'/app/ClubUnio/Services/HistoricalTrainingPlanAdoption.php',
        App\ClubUnio\Resources\LegacyTrainingPlanResource::class => $root.'/app/ClubUnio/Resources/LegacyTrainingPlanResource.php',
    ]);
    $app = require $live.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app->setBasePath($root);
    config(['logging.default' => 'null']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $c = config('database.connections.clubunio');
    $c['database'] = $argv[2];
    config(['database.connections.clubunio' => $c]);
    Illuminate\Support\Facades\DB::purge('clubunio');
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->selectOne('select current_database() n')->n !== $argv[2]) {
        throw new LogicException('Unexpected database');
    }
    if ($argv[1] === '--test' && $argv[2] === 'clubunio_prod') {
        throw new LogicException('Tests require isolated rehearsal');
    }
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    $service = app(App\ClubUnio\Services\HistoricalTrainingPlanAdoption::class);
    if ($argv[1] !== '--test') {
        echo json_encode($service->run($actor, $tenant, $argv[1] === '--apply'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $assert = function (bool $ok, string $label): void { if (! $ok) throw new RuntimeException($label); };
    $legacyHash = fn () => $db->selectOne("select md5(string_agg(row_to_json(l)::text,'' order by id)) h from clubunio.legacy_training_plans l")->h;
    $baselineHash = fn () => $db->selectOne("select md5(string_agg(row_to_json(s)::text,'' order by entity,identity_hash)) h from migration_control.svs_state s")->h;
    $beforeLegacy = $legacyHash();
    $beforeBaseline = $baselineHash();
    $beforeAccounts = $db->table('clubunio.accounts')->where('active', true)->count();
    $beforePortals = $db->table('clubunio.tenant_accounts')->where('portal_enabled', true)->count();
    $preview = $service->run($actor, $tenant);
    $assert($preview['eligible'] === 34 && $preview['unresolved'] === 0 && $preview['excluded'] === 13, 'complete_valid_plan_mapping');
    $first = $service->run($actor, $tenant, true);
    $repeat = $service->run($actor, $tenant, true);
    $assert($first['imported'] === 34 && $repeat['imported'] === 0 && $repeat['already_imported'] === 34, 'idempotence');
    $plans = $db->table('clubunio.training_plans')->where('tenant_id', $tenant->id)->get();
    $assert($plans->count() === 34 && $plans->every(fn ($p) => ! $p->shared && $p->archived_at !== null), 'private_archive_only');
    $assert($legacyHash() === $beforeLegacy && $baselineHash() === $beforeBaseline, 'immutable_source_and_delta_baseline_unchanged');
    $assert($db->table('clubunio.accounts')->where('active', true)->count() === $beforeAccounts
        && $db->table('clubunio.tenant_accounts')->where('portal_enabled', true)->count() === $beforePortals, 'no_account_or_portal_activation');
    foreach ($plans as $p) {
        $source = $db->table('clubunio.legacy_training_plans')->where('id', $p->legacy_source_id)->sole();
        $team = $db->table('clubunio.team_seasons')->where('id', $p->team_season_id)->sole();
        $assert($p->owner_id === $source->suggested_owner_id && $team->tenant_id === $tenant->id, 'original_owner_and_tenant');
        if (isset(['B-Junioren' => 1, 'C-Junioren' => 1][$source->source_team_name])) {
            $prefix = $source->source_team_name === 'B-Junioren' ? 'Ba-Junioren / ' : 'Ca-Junioren / ';
            $assert(str_starts_with($team->label, $prefix), 'operator_category_mapping');
        }
        if ((int) $source->source_id === 41) $assert(str_starts_with($team->label, '1te Mannschaft / '), 'approved_remaining_herren_plan');
        $assert((bool) $p->legacy_quality_pending === (bool) $source->requires_quality_review, 'quality_status_preserved');
    }
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Illuminate\Support\Facades\Auth::guard('clubunio')->login($actor);
    Filament\Facades\Filament::setTenant($tenant);
    $resource = App\ClubUnio\Resources\LegacyTrainingPlanResource::class;
    $assert($resource::getEloquentQuery()->whereNotNull('title')->get()->filter(fn ($r) => $r->adopted_plan_id !== null)->count() === 34, 'resource_adoption_markers');
    $assert($resource::filterState($resource::getEloquentQuery(), ['value' => 'imported'])->count() === 34
        && $resource::filterState($resource::getEloquentQuery(), ['value' => 'review'])->count() === 0
        && $resource::filterState($resource::getEloquentQuery(), ['value' => 'excluded'])->count() === 13, 'resource_filters');
    app('translator')->addJsonPath($root.'/lang');
    app('translator')->setLoaded([]);
    foreach (['de', 'en'] as $locale) {
        app()->setLocale($locale);
        $message = __('training.history_counts', ['imported' => 34, 'existing' => 0, 'unresolved' => 0, 'excluded' => 13]);
        $assert(str_contains($message, '34') && str_contains($message, '13') && ! str_contains($message, ':imported'), 'translated_summary_'.$locale);
    }
    // A late failure must roll back earlier inserts and audits without touching source rows.
    $db->beginTransaction();
    try {
        $db->table('clubunio.training_plans')->whereIn('id', $plans->take(2)->pluck('id'))->delete();
        $blockedId = $plans[1]->legacy_source_id;
        $db->unprepared("CREATE FUNCTION clubunio.training_adoption_test_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.legacy_source_id = ".$blockedId." THEN RAISE EXCEPTION 'synthetic failure'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER training_adoption_test_failure BEFORE INSERT ON clubunio.training_plans FOR EACH ROW EXECUTE FUNCTION clubunio.training_adoption_test_failure()");
        $beforeCount = $db->table('clubunio.training_plans')->count();
        $blocked = false;
        try { $service->run($actor, $tenant, true); } catch (Illuminate\Database\QueryException) { $blocked = true; }
        $assert($blocked && $db->table('clubunio.training_plans')->count() === $beforeCount, 'late_failure_atomic_rollback');
    } finally { $db->rollBack(); }
    echo json_encode(['status' => 'passed', 'preview' => $preview, 'first' => $first, 'repeat' => $repeat,
        'source_and_baseline_unchanged' => true, 'accounts_unchanged' => true, 'rules_validated' => true,
        'resource_markers_and_filters' => true, 'late_failure_rollback' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Historical plan adoption stopped ['.get_class($e).']; private values suppressed.'.PHP_EOL);
    if ($e instanceof LogicException || ($e instanceof RuntimeException && ! $e instanceof Illuminate\Database\QueryException)) fwrite(STDERR, $e->getMessage().PHP_EOL);
    if ($e instanceof Illuminate\Validation\ValidationException) fwrite(STDERR, 'Validation fields: '.implode(',', array_keys($e->errors())).PHP_EOL);
    exit(1);
}
