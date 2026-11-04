<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\BackofficeReadContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class UseActiveTenant
{
    public function handle(Request $request, Closure $next)
    {
        Filament::setTenant(app(ActiveTenant::class)->resolve($request));
        $languages = app(\App\ClubUnio\Services\LanguagePreferences::class);
        app()->setLocale($languages->resolve($request->user('clubunio')?->preferred_locale,
            $request->session()->get('clubunio.locale'), Filament::getTenant()?->default_locale));

        return app(BackofficeReadContext::class)->during($request, $next);
    }
}
