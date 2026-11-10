<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;

final class MobilePushRegistry
{
    public function app(string $id, string $platform, string $origin): array
    {
        abort_unless(config('clubunio-mobile-push.enabled', false), 503);
        // Config keys contain dots: read exact keys rather than treating native IDs as config paths.
        $apps = config('clubunio-mobile-push.apps', []);
        $app = $apps[$platform.':'.$id] ?? $apps[$id] ?? null;
        abort_unless(is_array($app) && in_array($platform, ['android', 'ios'], true)
            && ($app['platform'] ?? null) === $platform && ($app['origin'] ?? null) === $origin
            && preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~D', $origin)
            && array_key_exists('fixed_tenant_id', $app), 403);
        abort_unless($app['fixed_tenant_id'] === null || (is_int($app['fixed_tenant_id']) && $app['fixed_tenant_id'] > 0), 503);
        // Recheck verified domain ownership at dispatch as well as registration.
        $bound = app(TenantDomainService::class)->resolve(Request::create($origin));
        abort_unless(($app['fixed_tenant_id'] === null && $bound === null)
            || ($bound && $app['fixed_tenant_id'] === (int) $bound->id), 403);
        return $app;
    }

    public function request(Request $request, string $id, string $platform): array
    {
        $app = $this->app($id, $platform, $request->getSchemeAndHttpHost());
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_unless(($app['fixed_tenant_id'] === null && $bound === null)
            || ($bound && $app['fixed_tenant_id'] === (int) $bound->id), 403);
        return $app;
    }
}
