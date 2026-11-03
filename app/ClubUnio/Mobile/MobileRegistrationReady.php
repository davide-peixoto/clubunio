<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobileRegistrationReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_registrations_enabled', false), 503, 'Persoenlicher Anmeldungsablauf ist noch nicht freigegeben.');
        return $next($request);
    }
}
