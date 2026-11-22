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

        $scheme = $entry['mobile_callback_scheme'] ?? null;
        return ['origin' => $origin, 'client' => $entry['client'], 'secret' => $entry['secret'], 'tenant' => $tenant]
            + (is_string($scheme) && preg_match('/\A[a-z][a-z0-9-]{2,60}\z/', $scheme)
                ? ['mobile_callback_scheme' => $scheme] : []);
    }
}
