<?php

use App\ClubUnio\Http\AuthenticateApiIntegration;
use App\ClubUnio\Http\BindApiTenantDomain;
use App\ClubUnio\Http\BindTenantDomain;
use App\ClubUnio\Services\TenantDomainService;
use App\Providers\TenantBoundaryServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->routesAreCached() || ! ($app->getLoadedProviders()[TenantBoundaryServiceProvider::class] ?? false)) {
        throw new RuntimeException('Cached combined tenant/API release required');
    }
    $db = DB::connection('clubunio');
    if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new RuntimeException('Reviewed ClubUnio database required');
    }
    foreach (['2026_10_25_000076_api_platform', '2026_10_25_000077_tenant_boundaries', '2026_10_26_000078_api_tenant_boundary'] as $migration) {
        if (! $db->table('migrations')->where('migration', $migration)->exists()) {
            throw new RuntimeException('Required migration missing: '.$migration);
        }
    }
    $constraint = $db->selectOne("SELECT convalidated FROM pg_constraint WHERE conrelid='clubora.api_integrations'::regclass AND conname='api_integration_tenant_profile_fk'");
    if (! $constraint?->convalidated) {
        throw new RuntimeException('Validated integration tenant/profile constraint required');
    }
    $routes = $app['router']->getRoutes();
    $count = 0;
    foreach ($routes as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        $auth = array_search(AuthenticateApiIntegration::class, $middleware, true);
        $domain = array_search(BindApiTenantDomain::class, $middleware, true);
        if ($auth === false || $domain === false || $auth >= $domain) {
            throw new RuntimeException('API route boundary missing: '.$route->uri());
        }
        $count++;
    }
    if (! $count) {
        throw new RuntimeException('API routes missing');
    }
    foreach (['filament.clubunio.auth.login', 'filament.clubunio.resources.members.index'] as $name) {
        if (! in_array(BindTenantDomain::class, $routes->getByName($name)?->gatherMiddleware() ?? [], true)) {
            throw new RuntimeException('Web tenant domain boundary missing: '.$name);
        }
    }
    // Validate the configured canonical host, not a loopback host forbidden in production.
    $url = config('app.url');
    if (! is_string($url) || ! parse_url($url, PHP_URL_HOST)) {
        throw new RuntimeException('Canonical application URL required');
    }
    app(TenantDomainService::class)->resolve(Request::create($url.'/login'));
    echo json_encode(['combined_tenant_api_release' => true, 'cached_api_routes' => $count,
        'web_domain_guard' => true, 'api_domain_guard' => true, 'migrations_verified' => [76, 77, 78]], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Combined tenant/API release check failed: '.$error::class.' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
}
