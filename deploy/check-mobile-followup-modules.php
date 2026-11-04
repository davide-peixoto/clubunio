<?php

// Exact DEV-only release gate. Never installs migrations or calls providers.
if (PHP_SAPI !== 'cli' || ! in_array('--synthetic-rollback', $argv, true)
    || count(array_intersect(['--foundation', '--enabled'], $argv)) !== 1
    || array_diff(array_slice($argv, 1), ['--synthetic-rollback', '--foundation', '--enabled'])) {
    fwrite(STDERR, "Require --synthetic-rollback and exactly one release stage.\n"); exit(1);
}
$probe = null;
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (rtrim((string) config('app.url'), '/') !== 'https://dev.clubunio.ch' || ! config('clubunio-mobile.enabled')) throw new LogicException('Exact DEV origin and mobile API required');
    $enabled = in_array('--enabled', $argv, true);
    foreach (App\ClubUnio\Mobile\MobileFollowupModules::CONTRACTS as [$flag]) {
        if ((bool) config('clubunio-mobile.'.$flag, false) !== $enabled) throw new LogicException('Actual module stage mismatch');
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new LogicException('Standalone reviewed PostgreSQL required');
    foreach ([
        'members' => [App\ClubUnio\Mobile\MobileMemberDirectoryController::class, App\ClubUnio\Mobile\MobileMemberDirectoryReady::class],
        'registrations' => [App\ClubUnio\Mobile\MobileRegistrationController::class, App\ClubUnio\Mobile\MobileRegistrationReady::class],
        'training-plans' => [App\ClubUnio\Mobile\MobileTrainingController::class, App\ClubUnio\Mobile\MobileTrainingReady::class],
        'sponsor-run/events' => [App\ClubUnio\Mobile\MobileSponsorController::class, App\ClubUnio\Mobile\MobileSponsorReady::class],
    ] as $path => [$controller, $ready]) {
        $route = Illuminate\Support\Facades\Route::getRoutes()->match(Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/1/'.$path, 'GET'));
        if (! str_starts_with($route->getActionName(), $controller.'@')
            || ! in_array($ready, $route->gatherMiddleware(), true)
            || ! in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true)) throw new LogicException('Real scoped module route/readiness required');
    }
    if ($enabled) {
        foreach (['2026_11_03_000100_mobile_training_shares' => ['mobile_training_plan_shares', 'plan_id'],
            '2026_11_03_000200_mobile_sponsor_commands' => ['mobile_sponsor_commands', 'command_key'],
            '2026_11_03_000300_mobile_registration_requests' => ['mobile_registration_requests', 'request_key']] as $migration => [$table, $column]) {
            if (! $db->table('clubora.migrations')->where('migration', $migration)->exists()
                || ! $db->getSchemaBuilder()->hasColumn('clubora.'.$table, $column)) throw new LogicException('Reviewed existing module schema and ledger required');
        }
    }
    require __DIR__.'/mobile-followup-menu-probe.php';
    $probe = new ClubUnioRelease\FollowupMenuProbe;
    $checks = $probe->run($enabled);
    echo 'Four personal original modules '.($enabled ? 'enabled' : 'closed foundation')." verified by $checks HTTP-kernel checks; synthetic transaction rolled back, no provider/mail/bank action.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Followup module gate failed at '.($probe?->stage ?? 'bootstrap/schema').': '.get_class($error)." (private values suppressed).\n");
    exit(1);
}
