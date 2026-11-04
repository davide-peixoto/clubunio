<?php

namespace App\ClubUnio\Mobile;

use Closure;
use Illuminate\Http\Request;

final class MobilePitchReady
{
    public function handle(Request $request, Closure $next)
    {
        $previous = app()->getLocale();
        $locale = app(MobileLanguages::class)->forAccount($request->attributes->get('mobile.actor'))['language'];
        $request->attributes->set('mobile.pitch.locale', $locale);
        app()->setLocale($locale);
        try {
            abort_unless(config('clubunio-mobile.native_pitch_planning_enabled', false), 503,
                __('Die Platzplanung ist noch nicht freigeschaltet.'));
            return $next($request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
