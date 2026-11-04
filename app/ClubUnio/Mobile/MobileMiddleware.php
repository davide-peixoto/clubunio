<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Api\ApiErrors;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TenantDomainService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class MobileMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('clubunio.api.request_id', (string) Str::uuid());
        try {
            $logout = $request->isMethod('DELETE')
                && $request->routeIs('clubunio.mobile.logout', 'clubunio.mobile.revoke');
            abort_unless(config('clubunio-mobile.enabled') || $logout, 503);
            $bound = app(TenantDomainService::class)->resolve($request);
            if ($request->routeIs('clubunio.mobile.login')) {
                return $this->protect($next($request));
            }
            $actor = app(MobileSessions::class)->authenticate($request);
            $request->attributes->set('mobile.actor', $actor);
            $key = 'mobile-rate:'.$actor->id;
            abort_if(RateLimiter::tooManyAttempts($key, 240), 429);
            RateLimiter::hit($key, 60);
            if ($request->route('tenant')) {
                $tenant = Tenant::whereKey($request->route('tenant'))->firstOrFail();
                abort_if($bound && ! $bound->is($tenant), 404);
                app(MobileAccess::class)->enter($actor, $tenant);
                app(MobileBootstrapState::class)->authorizeTenant($request, $tenant);
                $request->attributes->set('mobile.tenant', $tenant);
                $request->attributes->set('mobile.season_scope', true);
            }

            return $this->protect($next($request));
        } catch (\Throwable $e) {
            return app(ApiErrors::class)->render($e, $request);
        }
    }

    private function protect($response)
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Request-ID', request()->attributes->get('clubunio.api.request_id'));

        return $response;
    }
}
