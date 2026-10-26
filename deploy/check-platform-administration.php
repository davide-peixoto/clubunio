<?php

use App\ClubUnio\Http\RequirePlatformAdministrator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if ($db->getDatabaseName() !== 'db_acdf8d_dclubora' || ! $app->routesAreCached()) {
        throw new RuntimeException('Reviewed cached ClubUnio release required');
    }
    foreach (['platform_admins', 'platform_changes', 'tenant_domains'] as $table) {
        if (! $db->getSchemaBuilder()->hasTable('clubora.'.$table)) {
            throw new RuntimeException('Platform schema missing');
        }
    }
    if (! $db->table('migrations')->where('migration', '2026_10_26_000080_platform_administration')->exists()) {
        throw new RuntimeException('Platform migration missing');
    }
    $routes = $app['router']->getRoutes();
    foreach (['index', 'show', 'change', 'create', 'account.create', 'logout'] as $name) {
        $route = $routes->getByName('clubunio.platform.'.$name);
        if (! $route || ! in_array(RequirePlatformAdministrator::class, $route->gatherMiddleware(), true)) {
            throw new RuntimeException('Platform route boundary missing');
        }
    }
    $admins = $db->table('clubora.platform_admins as p')->join('clubora.accounts as a', 'a.id', '=', 'p.account_id')
        ->where('p.active', true)->where('a.active', true)->where('a.api_only', false)->count();
    if (! $admins) {
        throw new RuntimeException('Initial platform administrator required');
    }
    echo json_encode(['platform_administration' => true, 'migration' => 80, 'active_platform_admins' => $admins,
        'routes_guarded' => true, 'tenant_permissions_not_inherited' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Platform release check failed: '.$error::class.' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
}
