<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
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
if (! in_array(App\ClubUnio\Http\UseActiveTenant::class, $route->gatherMiddleware(), true)) {
    throw new RuntimeException('Tenant middleware missing');
}
echo "Cached clean routes and tenant middleware verified.\n";
