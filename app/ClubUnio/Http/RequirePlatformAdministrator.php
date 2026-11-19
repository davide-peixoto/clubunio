<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\PlatformAdministrationService;
use App\ClubUnio\Services\TenantDomainService;
use Closure;
use Illuminate\Http\Request;

final class RequirePlatformAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        // Do not depend on middleware sorting: reject custom hosts before redirects/auth.
        abort_if(app(TenantDomainService::class)->resolve($request), 404);
        if (PlatformOidcController::required()) {
            abort_unless($request->isSecure() && $request->getSchemeAndHttpHost() === config('clubunio-platform-oidc.origin'), 404);
            if ((int) $request->session()->get('platform.oidc.expires') <= time()) {
                \Illuminate\Support\Facades\Auth::guard('platform')->logout();
                return redirect()->route('clubunio.platform.oidc.start');
            }
        }
        $actor = $request->user(PlatformOidcController::guard($request));
        if (! $actor) {
            return redirect()->route('clubunio.platform.login');
        }
        app(PlatformAdministrationService::class)->authorize($actor);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
