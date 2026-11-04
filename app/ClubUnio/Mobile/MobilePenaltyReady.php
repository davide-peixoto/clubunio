<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobilePenaltyReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_penalty_enabled', false), 503, 'Vereinschallenge noch nicht freigeschaltet.');
        return $next($request);
    }
}
