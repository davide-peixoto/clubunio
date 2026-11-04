<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\LanguagePreferences;
use Closure;
use Illuminate\Http\Request;

final class UseLanguage
{
    public function handle(Request $request, Closure $next)
    {
        $languages = app(LanguagePreferences::class);
        $actor = $languages->actor($request);
        $tenant = $request->route('tenant');
        if (! $tenant instanceof \App\ClubUnio\Models\Tenant) { $tenant = null; }
        $locale = $languages->resolve($actor?->preferred_locale,
            $request->hasSession() ? $request->session()->get('clubunio.locale') : null,
            $tenant?->default_locale);
        app()->setLocale($locale);
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        return $response;
    }
}
