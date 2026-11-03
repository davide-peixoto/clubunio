<?php

try {
    if (PHP_SAPI !== 'cli' || (! in_array('--foundation', $argv, true) && ! in_array('--facilities-enabled', $argv, true) && ! in_array('--bus-enabled', $argv, true))) {
        throw new LogicException('Explicit release stage required');
    }
    if (in_array('--foundation', $argv, true) && (in_array('--facilities-enabled', $argv, true) || in_array('--bus-enabled', $argv, true))) { throw new LogicException('Conflicting release stages'); }
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected database'); }
    foreach (['facilities', 'bus'] as $module) {
        if ((bool) config('clubunio-mobile.native_'.$module.'_enabled') !== in_array('--'.$module.'-enabled', $argv, true)) { throw new LogicException('Module release stage mismatch'); }
    }
    $counts = ['facilities' => 0, 'bus' => 0];
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        foreach (['facilities' => [App\ClubUnio\Mobile\MobileFacilitiesController::class, App\ClubUnio\Mobile\MobileFacilitiesReady::class],
            'bus' => [App\ClubUnio\Mobile\MobileBusController::class, App\ClubUnio\Mobile\MobileBusReady::class]] as $module => [$controller, $middleware]) {
            if (str_starts_with($route->getActionName(), $controller.'@')) {
                if (! in_array($middleware, $route->gatherMiddleware(), true) || ! in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true)) { throw new LogicException('Unprotected personal module route'); }
                $counts[$module]++;
            }
        }
    }
    if ($counts !== ['facilities' => 2, 'bus' => 7]) { throw new LogicException('Unexpected native module routes'); }
    foreach (['field_availabilities', 'facility_statuses', 'training_options', 'buses', 'bus_statuses', 'bus_reservations', 'bus_keyboxes', 'bus_keybox_codes'] as $table) {
        if (! $db->getSchemaBuilder()->hasTable('clubora.'.$table)) { throw new LogicException('Missing native module schema'); }
    }
    echo "Personal facilities2/bus7 routes, release flags and existing schema verified; no record or device changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Facilities/bus release gate failed: '.get_class($error)." (record contents suppressed).\n");
    exit(1);
}
