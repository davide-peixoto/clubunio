<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobileAdminNoticeReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_push_admin_enabled', false), 503, 'Persönliche Pushverwaltung noch nicht freigeschaltet.');
        return $next($request);
    }
}
