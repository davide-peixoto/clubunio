<?php

namespace App\ClubUnio\Http;

use Illuminate\Http\RedirectResponse;

final class KeycloakLogout
{
    public static function redirect(string $configuration): RedirectResponse
    {
        $config = config($configuration);

        if ($configuration === 'clubunio-verein-oidc'
            && app(\App\ClubUnio\Services\VereinOidcDomain::class)->tenant(request())) {
            $config['origin'] = request()->getSchemeAndHttpHost();
        }

        return redirect()->away($config['issuer'].'/protocol/openid-connect/logout?'.http_build_query([
            'client_id' => $config['client'],
            'post_logout_redirect_uri' => rtrim($config['origin'], '/').'/',
        ], '', '&', PHP_QUERY_RFC3986))->header('Cache-Control', 'no-store');
    }
}
