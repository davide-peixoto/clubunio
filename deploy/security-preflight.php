<?php

if (PHP_SAPI !== 'cli') exit(1);
$root = $argv[1] ?? '';
if (!in_array($root, ['/home/runcloud/webapps/ClubUnioStage', '/home/runcloud/webapps/ClubUnioProd'], true)) exit(1);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$expected = str_ends_with($root, 'Stage') ? 'https://stage.clubunio.com' : 'https://app.clubunio.com';
$url = config('app.url');
if ($url !== $expected) throw new LogicException('Exact HTTPS application identity required');
$settings = $db->getConfig();
$output = [
    'root' => $root, 'app_url' => $url, 'php' => PHP_VERSION,
    'debug' => config('app.debug'), 'environment' => config('app.env'),
    'database' => $db->getDatabaseName(), 'db_driver' => $db->getDriverName(),
    'db_user' => $settings['username'] ?? null,
    'schema' => $settings['search_path'] ?? $settings['schema'] ?? null,
    'search_path' => $db->selectOne('SHOW search_path')->search_path,
    'cache_store' => config('cache.default'),
    'cache_drivers' => array_map(fn ($c) => $c['driver'] ?? null, config('cache.stores')),
    'session_secure' => config('session.secure'), 'session_http_only' => config('session.http_only'),
    'session_same_site' => config('session.same_site'),
    'clam_socket' => config('clubunio-mobile.attachments.clamd_socket'),
    'clam_socket_exists' => is_string(config('clubunio-mobile.attachments.clamd_socket')) && file_exists(config('clubunio-mobile.attachments.clamd_socket')),
    'oidc' => array_intersect_key(config('clubunio-verein-oidc'), array_flip(['enabled','only','invites_enabled','mobile_enabled','mobile_mfa_acr','web_mfa_acr','origin','issuer','client'])),
    'platform_oidc' => array_intersect_key(config('clubunio-platform-oidc'), array_flip(['enabled','legacy_allowed','mfa_acr','origin','issuer','client'])),
    'backchannel_enabled' => config('clubunio-keycloak-backchannel.enabled', false),
    'packages' => [], 'tables' => [],
];
foreach (['laravel/framework','phpseclib/phpseclib','symfony/html-sanitizer','symfony/http-foundation','symfony/http-kernel','symfony/routing'] as $package) {
    $output['packages'][$package] = Composer\InstalledVersions::getPrettyVersion($package);
}
foreach (['cache','cache_locks','keycloak_logout_watermarks','accounts','migrations','clubhouse_menus','mobile_sessions'] as $table) {
    $output['tables'][$table] = $db->getSchemaBuilder()->hasTable('clubunio.'.$table);
}
echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
