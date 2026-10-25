<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\TenantDomainService;
use Closure;
use Illuminate\Http\Request;

class BindTenantDomain
{
    public function handle(Request $request, Closure $next)
    {
        $domains = app(TenantDomainService::class);
        $request->attributes->remove(TenantDomainService::class);
        $tenant = $domains->resolve($request);
        if (! $tenant) {
            return $next($request);
        }
        $request->attributes->set(TenantDomainService::class, $tenant);
        $routeTenant = $request->route()?->parameter('tenant');
        abort_if($routeTenant instanceof Tenant && ! $routeTenant->is($tenant), 404);
        abort_if(is_string($routeTenant) && $routeTenant !== $tenant->slug, 404);
        abort_unless(! config('session.domain'), 503, 'Vereinsdomains benötigen hostgebundene Sitzungscookies.');
        $actor = $request->hasSession() ? $request->user($request->is('portal/*') ? 'portal' : 'clubunio') : null;
        if ($actor) {
            abort_unless($request->is('portal/*')
                ? app(MemberAccessService::class)->canEnter($actor, $tenant)
                : $actor->fresh()?->canAccessTenant($tenant), 403);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
