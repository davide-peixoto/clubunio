<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Request;

final class VereinOidcDomain
{
    public function configuration(Request $request): ?array
    {
        $origin = $request->getSchemeAndHttpHost();
        $entry = config('clubunio-verein-oidc.domains')[$origin] ?? null;
        if (! $request->isSecure() || ! is_array($entry)
            || empty($entry['client']) || empty($entry['secret'])) {
            return null;
        }

        $tenant = app(TenantDomainService::class)->resolve($request);
        if (! $tenant || $tenant->slug !== ($entry['tenant'] ?? null)) {
            return null;
        }

        return ['origin' => $origin, 'client' => $entry['client'], 'secret' => $entry['secret'], 'tenant' => $tenant];
    }
}
