<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Http\Request;

final class VereinOidcDomain
{
    public function tenant(Request $request): ?Tenant
    {
        $slug = config('clubunio-verein-oidc.web_domains', [])[$request->getSchemeAndHttpHost()] ?? null;
        if (!$request->isSecure() || !is_string($slug)) {
            return null;
        }
        $tenant = app(TenantDomainService::class)->resolve($request);

        return $tenant?->slug === $slug ? $tenant : null;
    }
}
