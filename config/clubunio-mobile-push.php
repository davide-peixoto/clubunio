<?php

$flag = fn (string $key) => filter_var(env($key, false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
$raw = (string) env('CLUBUNIO_MOBILE_PUSH_APPS', '{}');
try { $apps = strlen($raw) <= 65536 ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : []; }
catch (Throwable) { $apps = []; }
if (! is_array($apps) || count($apps) > 100) $apps = [];
foreach ($apps as $id => $app) {
    if (! is_string($id) || ! preg_match('/^(?:(?:ios|android):)?[a-zA-Z0-9_.-]{3,180}$/D', $id) || ! is_array($app)
        || array_diff(array_keys($app), ['platform', 'origin', 'fixed_tenant_id', 'project_id', 'credentials_file'])
        || ! in_array($app['platform'] ?? null, ['android', 'ios'], true)
        || (str_contains($id, ':') && ! str_starts_with($id, $app['platform'].':'))
        || ! is_string($app['origin'] ?? null) || ! is_string($app['project_id'] ?? null)
        || ! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $app['project_id'])
        || ! array_key_exists('fixed_tenant_id', $app)
        || ($app['fixed_tenant_id'] !== null && (! is_int($app['fixed_tenant_id']) || $app['fixed_tenant_id'] < 1))
        || ! is_string($app['credentials_file'] ?? null) || strlen($app['credentials_file']) > 1024
        || ! preg_match('#^(?:/|[a-zA-Z]:[\\\\/])#', $app['credentials_file'])
        || str_contains($app['credentials_file'], '://') || preg_match('/[\x00-\x1f\x7f]/', $app['credentials_file'])) { $apps = []; break; }
    $parts = parse_url($app['origin']);
    if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) { $apps = []; break; }
}
return [
    // Separate source/device/provider activation. No credential discovery or legacy fallback.
    'enabled' => $flag('CLUBUNIO_MOBILE_PUSH_ENABLED'),
    'delivery_enabled' => $flag('CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED'),
    // Operator-reviewed entries: native app ID => platform/origin/fixed_tenant_id/project_id/credentials_file.
    // Platform entry has fixed_tenant_id=null; custom entry must have a verified custom tenant domain.
    'apps' => $apps,
];
