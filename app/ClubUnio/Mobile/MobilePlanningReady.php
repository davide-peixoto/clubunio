<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobilePlanningReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_planning_enabled'), 503, 'Die neue Planung ist noch nicht verfuegbar.');
        return $next($request);
    }
}
