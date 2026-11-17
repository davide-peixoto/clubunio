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

    public function configuration(Request $request): ?array
    {
        $tenant = $this->tenant($request);

        return $tenant ? ['origin' => $request->getSchemeAndHttpHost(), 'tenant' => $tenant,
            'mobile_callback_scheme' => 'sv-sissach-test-auth'] : null;
    }
}
