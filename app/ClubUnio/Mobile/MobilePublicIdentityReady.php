<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Api\ApiErrors;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class MobilePublicIdentityReady
{
    public function handle(Request $request, Closure $next)
    {
        try {
            abort_unless(config('clubunio-mobile.enabled') && config('clubunio-mobile.public_identity.enabled', false), 503);
            $policy = app(MobilePublicIdentityPolicy::class); $origin = $policy->origin($request);
            $key = 'mobile-public-identity:'.hash('sha256', $origin.'|'.$request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 90), 429); RateLimiter::hit($key, 60);
            $request->attributes->set('mobile.public.origin', $origin);
            if ($request->route('tenant')) { $request->attributes->set('mobile.public.tenant', $policy->tenant($origin, (int) $request->route('tenant'))); }
            $response = $next($request);
        } catch (\Throwable $error) { $response = app(ApiErrors::class)->render($error, $request); }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
