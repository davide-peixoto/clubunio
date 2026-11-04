<?php

try {
    if (PHP_SAPI !== 'cli' || count(array_intersect(['--foundation','--enabled'], array_slice($argv, 1))) !== 1
        || array_diff(array_slice($argv, 1), ['--foundation','--enabled'])) throw new LogicException('Explicit challenge stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled = in_array('--enabled', $argv, true);
    if ((bool) config('clubunio-mobile.native_penalty_enabled', false) !== $enabled) throw new LogicException('Challenge stage mismatch');
    $count = 0;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobilePenaltyController::class.'@')) continue;
        foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobilePenaltyReady::class] as $middleware) {
            if (! in_array($middleware, $route->gatherMiddleware(), true)) throw new LogicException('Ungated challenge route');
        }
        $count++;
    }
    if ($count !== 5 || ! array_key_exists('Penalty', App\ClubUnio\Services\MobileModuleSettings::MODULES)) throw new LogicException('Challenge route/module inventory incomplete');
    if ($enabled) {
        $db = Illuminate\Support\Facades\DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Reviewed PostgreSQL required');
        if (! $db->table('clubora.migrations')->where('migration', '2026_11_04_000600_mobile_penalty_challenge')->exists()) throw new LogicException('Reviewed challenge600 ledger required');
        foreach (['mobile_penalty_challenges' => 'generation', 'mobile_penalty_sessions' => 'choices_sha256', 'mobile_penalty_best' => 'score', 'mobile_penalty_resets' => 'dispatch_reference'] as $table => $column) {
            if (! $db->getSchemaBuilder()->hasColumn('clubora.'.$table, $column)) throw new LogicException('Existing challenge schema incomplete');
        }
    }
    echo 'Personal original challenge '.($enabled ? 'enabled schema' : 'closed foundation')." and five scoped routes verified; no round, reset, message or provider action.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Challenge gate failed: '.get_class($error)." (private details suppressed).\n"); exit(1);
}
