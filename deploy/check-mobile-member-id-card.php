<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$enabled = in_array('--enabled', $argv, true);
if (! $enabled && ! in_array('--foundation', $argv, true)) throw new LogicException('Explicit member card release stage required');
if ((bool) config('clubunio-mobile.native_member_id_card_enabled', false) !== $enabled) throw new LogicException('Member card release stage mismatch');
$count = 0;
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    if (! str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobileMemberIdCardController::class.'@')) continue;
    if (! in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true)) throw new LogicException('Unprotected member card route');
    if ($route->uri() !== 'api/mobile/v1/clubs/{tenant}/members/{member}/id-card') throw new LogicException('Unexpected member card route');
    $count++;
}
if ($count !== 1) throw new LogicException('Unexpected member card route inventory');
echo 'Personal member card '.($enabled ? 'enabled' : 'closed foundation')." and one scoped route verified; no private member data read or export performed.\n";
