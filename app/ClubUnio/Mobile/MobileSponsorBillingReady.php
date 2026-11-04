<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobileSponsorBillingReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('clubunio-mobile.native_sponsor_billing_enabled', false), 503, 'Persoenliche Sponsorenabrechnung ist noch nicht freigegeben.');
        return $next($request);
    }
}
