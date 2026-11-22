<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\{LanguagePreferences, MemberCardService};
use Illuminate\Http\Request;

final class MemberCardVerificationController
{
    public function __invoke(Request $request, string $token)
    {
        $languages = app(LanguagePreferences::class);
        $locale = $languages->resolve(is_string($request->query('lang')) ? $request->query('lang') : null, $request->getPreferredLanguage(array_keys($languages->enabled())));
        app()->setLocale($locale);
        $unavailable = false;
        try { $card = app(MemberCardService::class)->resolve($token); }
        catch (\Throwable) {
            // Never expose token, DB exception, private paths or names on an unavailable service.
            $unavailable = true;
            $card = ['Known' => false, 'Valid' => false, 'CheckedAt' => now()->toIso8601String()];
        }
        return response()->view('clubunio.member-card-verification', compact('card', 'unavailable', 'locale'), $unavailable ? 503 : 200)
            ->header('Content-Language', $locale)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
    }
}
