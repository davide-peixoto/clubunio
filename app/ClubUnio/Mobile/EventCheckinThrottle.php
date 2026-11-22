<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Arriving members often share the venue's Wi-Fi; throttle accounts, not that IP. */
final class EventCheckinThrottle
{
    public function handle(Request $request, Closure $next)
    {
        $id = (int) $request->attributes->get('mobile.actor')?->id;
        abort_unless($id > 0, 401);
        $key = 'event-checkin-confirm:'.$id;
        abort_if(RateLimiter::tooManyAttempts($key, 30), 429);
        RateLimiter::hit($key, 60);
        return $next($request);
    }
}
