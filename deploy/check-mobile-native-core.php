<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! config('clubunio-mobile.native_planning_enabled') || ! config('clubunio-mobile.native_my_teams_enabled')) { throw new LogicException('Native core activation missing'); }
foreach (['native_expenses_enabled', 'native_documents_enabled', 'native_facilities_enabled', 'native_bus_enabled'] as $key) {
    if (config('clubunio-mobile.'.$key)) { throw new LogicException('Unreviewed follow-up package activation'); }
}
$routes = collect(Illuminate\Support\Facades\Route::getRoutes())->keyBy(fn ($route) => $route->uri());
foreach (['members/{member}', 'members/{member}/invoices', 'members/{member}/contact',
    'members/{member}/change-requests', 'members/{member}/change-requests/{change}/decide'] as $suffix) {
    $route = $routes->get('api/mobile/v1/clubs/{tenant}/'.$suffix);
    if (! $route || ! in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true)) { throw new LogicException('Missing personal dossier guard'); }
}
echo "Native planning/My Teams core enabled; personal dossier guards and closed follow-up packages verified.\n";
