<?php

use App\ClubUnio\Http\ImpersonationMiddleware;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(\Illuminate\Contracts\Http\Kernel::class);
$router = $app['router'];
foreach (['clubunio.support.start', 'clubunio.support.grant', 'clubunio.portal.members',
    'filament.clubunio.resources.members.index', 'default-livewire.update'] as $name) {
    $route = $router->getRoutes()->getByName($name);
    if (! $route) {
        throw new RuntimeException('Missing protected route: '.$name);
    }
    $middleware = $router->gatherRouteMiddleware($route);
    $support = array_search(ImpersonationMiddleware::class, $middleware, true);
    $session = array_search(\Illuminate\Session\Middleware\StartSession::class, $middleware, true);
    if ($support === false || $session === false || $support <= $session) {
        throw new RuntimeException('Support middleware/session order incorrect: '.$name);
    }
    foreach ($middleware as $position => $class) {
        if (str_starts_with($class, \Illuminate\Auth\Middleware\Authenticate::class.':') && $position < $support) {
            throw new RuntimeException('Authentication runs before support identity: '.$name);
        }
    }
}
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new RuntimeException('Unexpected database');
}
foreach (['grants', 'sessions', 'events'] as $table) {
    $db->table('clubora.support_'.$table)->limit(1)->get();
}
echo 'Support routes/session guards verified; enabled grants: '.$db->table('clubora.support_grants')->where('enabled', true)->count().PHP_EOL;
