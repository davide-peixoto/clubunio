<?php

if (PHP_SAPI !== 'cli' || count($argv) !== 2 || ! in_array($argv[1], ['--foundation', '--enabled'], true)) {
    fwrite(STDERR, "Choose exactly one sponsor release stage.\n"); exit(1);
}
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled = $argv[1] === '--enabled';
    if ((bool) config('clubunio-mobile.native_sponsor_enabled') !== $enabled) throw new LogicException('Sponsor stage mismatch');
    $count = 0;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobileSponsorController::class.'@')) continue;
        foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobileSponsorReady::class] as $middleware) {
            if (! in_array($middleware, $route->gatherMiddleware(), true)) throw new LogicException('Sponsor middleware missing');
        }
        $count++;
    }
    if ($count !== 15) throw new LogicException('Unexpected sponsor route count');
    if ($enabled) {
        $db = Illuminate\Support\Facades\DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
            || ! $db->table('clubora.migrations')->where('migration', '2026_11_03_000200_mobile_sponsor_commands')->exists()
            || ! $db->getSchemaBuilder()->hasColumn('clubora.mobile_sponsor_commands', 'fingerprint')) throw new LogicException('Reviewed sponsor schema missing');
    }
    echo "Fifteen protected sponsor routes and explicit release stage verified; no provider action.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Sponsor release gate failed: '.get_class($error)."\n"); exit(1);
}
