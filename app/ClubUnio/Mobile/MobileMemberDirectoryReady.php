<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobileMemberDirectoryReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_members_enabled', false), 503, 'Mitgliederverzeichnis noch nicht freigeschaltet.');
        return $next($request);
    }
}
