<?php

use App\ClubUnio\Http\UseActiveTenant;
use App\ClubUnio\Http\UseClubWorkspace;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$panel = Filament\Facades\Filament::getPanel('clubunio');
if (! $panel->hasTenancy() || $panel->getPath() !== '') {
    throw new RuntimeException('Panel tenancy/path mismatch');
}
foreach (['filament.clubunio.pages.dashboard' => '/', 'filament.clubunio.auth.login' => '/login',
    'filament.clubunio.resources.members.index' => '/members',
    'filament.clubunio.resources.invoices.index' => '/invoices'] as $name => $expected) {
    if (route($name, ['tenant' => 'must-not-appear'], false) !== $expected) {
        throw new RuntimeException('Incorrect route: '.$name);
    }
}
$route = app('router')->getRoutes()->getByName('filament.clubunio.resources.members.index');
if (! in_array(UseActiveTenant::class, $route->gatherMiddleware(), true)) {
    throw new RuntimeException('Tenant middleware missing');
}
echo "Cached clean routes and tenant middleware verified.\n";

foreach (['clubunio.workspace.bus.index' => '/bus', 'clubunio.workspace.bus.options' => '/bus/options',
    'clubunio.workspace.planning.index' => '/planning', 'clubunio.workspace.planning.calendar' => '/planning/calendar',
    'clubunio.workspace.training.index' => '/planning/training-plans'] as $name => $expected) {
    if (route($name, [], false) !== $expected) {
        throw new RuntimeException('Incorrect workspace route: '.$name);
    }
    $route = app('router')->getRoutes()->getByName($name);
    if (! in_array(UseClubWorkspace::class, $route->gatherMiddleware(), true)) {
        throw new RuntimeException('Workspace tenant guard missing');
    }
}
echo "Bus and planning workspace routes verified.\n";
