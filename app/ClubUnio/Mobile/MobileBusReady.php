<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobileBusReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_bus_enabled'), 503, 'Busreservationen noch nicht freigeschaltet.');
        return $next($request);
    }
}
