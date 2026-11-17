<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\VereinOidcDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class KeycloakLogout
{
    public static function redirect(string $configuration, ?Request $request = null): RedirectResponse
    {
        $config = config($configuration);
        if ($configuration === 'clubunio-verein-oidc' && $request
            && ($domain = app(VereinOidcDomain::class)->configuration($request))) {
            $config = array_replace($config, $domain);
        }


        return redirect()->away($config['issuer'].'/protocol/openid-connect/logout?'.http_build_query([
            'client_id' => $config['client'],
            'post_logout_redirect_uri' => rtrim($config['origin'], '/').'/',
        ], '', '&', PHP_QUERY_RFC3986))->header('Cache-Control', 'no-store');
    }
}
