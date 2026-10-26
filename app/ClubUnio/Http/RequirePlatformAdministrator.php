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
        $actor = $request->user('clubunio');
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
