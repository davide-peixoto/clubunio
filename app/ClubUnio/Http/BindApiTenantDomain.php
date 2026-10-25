<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Services\TenantDomainService;
use Closure;
use Illuminate\Http\Request;

final class BindApiTenantDomain
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->remove(TenantDomainService::class);
        $context = $request->attributes->get('clubunio.api.context');
        abort_unless($context instanceof ApiContext, 401);
        $tenant = app(TenantDomainService::class)->resolve($request);
        // A domain restricts the token, never selects a different token tenant.
        abort_if($tenant && (int) $tenant->id !== (int) $context->tenant->id, 404);
        if ($tenant) {
            $request->attributes->set(TenantDomainService::class, $tenant);
        }

        return $next($request);
    }
}
