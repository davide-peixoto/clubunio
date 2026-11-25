<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\KeycloakBackchannelLogout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class KeycloakBackchannelController
{
    public static function routes(): void
    {
        // Deliberately outside browser sessions/CSRF: the signed logout JWT authenticates.
        Route::post('/auth/keycloak/backchannel-logout', self::class)
            ->middleware('throttle:120,1')->name('clubunio.keycloak.backchannel-logout');
    }

    public function __invoke(Request $request, KeycloakBackchannelLogout $logout)
    {
        abort_unless(config('clubunio-keycloak-backchannel.enabled') === true && $request->isSecure()
            && $request->getSchemeAndHttpHost() === config('clubunio-keycloak-backchannel.origin'), 404);
        abort_unless(strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/x-www-form-urlencoded'
            && $request->query->count() === 0 && strlen($request->getContent()) <= 18000, 400);
        $token = $request->input('logout_token');
        abort_unless(is_string($token) && strlen($token) <= 16384 && preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token), 400);
        $logout->revoke($logout->verify($token));

        return response('', 200)->header('Cache-Control', 'no-store');
    }
}
