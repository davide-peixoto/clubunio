<?php

// Read-only production-shaped adapter check, not a fabricated mobile login.
try {
    $root = dirname(__DIR__);
    $enabled = array_slice($argv, 1) === ['--enabled'];
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux'
        || !in_array(array_slice($argv, 1), [['--closed'], ['--enabled']], true)
        || !in_array($root, ['/var/www/svs-laravel/releases/20261105-clubunio-training-halves',
            '/var/www/svs-laravel/releases/20261105-clubunio-native-pitch'], true)) throw new LogicException('Fixed release/mode required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')
        || (bool)config('clubunio-mobile.native_pitch_planning_enabled') !== $enabled) throw new LogicException('Unexpected feature state');
    foreach (['clubunio-integrations.pitch_planning_test_mail_enabled', 'clubunio-mobile-push.enabled',
        'clubunio-mobile-push.delivery_enabled', 'clubunio-mobile.public_identity.mail_enabled',
        'clubunio-mobile.ai.enabled', 'clubunio-mobile.training_ai.enabled',
        'clubunio-mobile.keybox.issuance_enabled', 'clubunio-mobile.keybox.provider_enabled'] as $flag) {
        if (config($flag, false)) throw new LogicException('External provider flag unexpectedly enabled');
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected database');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        if (!app(App\ClubUnio\Services\PitchPlanningProjection::class)->ready()) throw new LogicException('Missing projection schema');
        foreach (['001500_pitch_planning', '001600_pitch_planning_notifications', '001700_pitch_weekly_areas', '001800_pitch_request_idempotency'] as $migration) {
            if ($db->table('clubora.migrations')->where('migration', '2026_11_04_'.$migration)->count() !== 1) throw new LogicException('Missing schema ledger');
        }
        $routes = array_filter(iterator_to_array(app('router')->getRoutes()), fn ($r) => str_starts_with($r->getName() ?? '', 'clubunio.mobile.pitch.'));
        if (count($routes) !== 7) throw new LogicException('Unexpected route count');
        foreach ($routes as $route) {
            foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobilePitchReady::class] as $middleware) {
                if (!in_array($middleware, $route->gatherMiddleware(), true)) throw new LogicException('Missing personal API gate');
            }
        }
        $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
        $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
        $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/modules');
        $request->attributes->set('mobile.actor', $actor);
        $request->attributes->set('mobile.tenant', $tenant);
        $request->attributes->set('mobile.season_scope', true);
        $app->instance('request', $request);
        app(App\ClubUnio\Mobile\MobileAccess::class)->enter($actor, $tenant);
        $modules = function (string $features) use ($request): array {
            $request->headers->set('X-ClubUnio-Mobile-Features', $features);
            $data = app(App\ClubUnio\Mobile\MobileAccountController::class)->modules($request)->getData(true);
            return collect($data['data']['modules'])->firstWhere('key', 'Platzverfugbarkeit') ?? [];
        };
        $new = $modules('pitch-planning-v1,facilities-v1');
        $old = $modules('facilities-v1');
        if (($new['pitch_planning'] ?? null) !== $enabled || ($old['pitch_planning'] ?? null) !== false
            || !($new['available'] ?? false) || !($old['facilities'] ?? false)) throw new LogicException('Module negotiation failed');
        $service = app(App\ClubUnio\Services\PitchPlanningService::class);
        $options = $service->options($actor, $tenant);
        $date = now('Europe/Zurich')->toDateString();
        $calendar = $service->calendar($actor, $tenant, ['from' => $date, 'to' => $date]);
        echo json_encode(['read_only' => true, 'native_pitch_enabled' => $enabled, 'pitch_routes' => count($routes),
            'own_account_module' => $new, 'old_client_module' => $old,
            'fields' => count($options['fields']), 'entries_today' => count($calendar['entries']),
            'areas' => $db->table('clubora.pitch_areas')->where('tenant_id', $tenant->id)->count(),
            'push_and_mail_closed' => true, 'mobile_login_tested' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Native pitch check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
