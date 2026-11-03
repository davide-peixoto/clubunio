<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! config('clubunio-mobile.native_planning_enabled') || ! config('clubunio-mobile.native_my_teams_enabled')) { throw new LogicException('Native core activation missing'); }
foreach (['expenses', 'documents', 'facilities', 'bus'] as $module) {
    if ((bool) config('clubunio-mobile.native_'.$module.'_enabled') !== in_array('--'.$module.'-enabled', $argv, true)) {
        throw new LogicException('Follow-up package release stage mismatch');
    }
}
$routes = collect(Illuminate\Support\Facades\Route::getRoutes())->keyBy(fn ($route) => $route->uri());
foreach (['members/{member}', 'members/{member}/invoices', 'members/{member}/contact',
    'members/{member}/change-requests', 'members/{member}/change-requests/{change}/decide'] as $suffix) {
    $route = $routes->get('api/mobile/v1/clubs/{tenant}/'.$suffix);
    if (! $route || ! in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true)) { throw new LogicException('Missing personal dossier guard'); }
}
echo "Native planning/My Teams core enabled; personal dossier guards and explicit follow-up release stages verified.\n";
