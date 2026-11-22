<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\LanguagePreferences;
use Illuminate\Http\Request;

final class EventCheckinEntryController
{
    public function __invoke(Request $request, string $token)
    {
        $languages = app(LanguagePreferences::class);
        $locale = $languages->resolve(is_string($request->query('lang')) ? $request->query('lang') : null,
            $request->getPreferredLanguage(array_keys($languages->enabled())));
        app()->setLocale($locale);
        // Only configured HTTPS hosts can become a reference passed to a native app.
        $host = strtolower($request->getHost());
        // Config keys contain dots; Laravel's dot accessor cannot retrieve them directly.
        $schemes = ((array) config('clubunio-event-links.app_schemes', []))[$host] ?? [];
        abort_unless($request->isSecure() && $request->getPort() === 443 && is_array($schemes) && count($schemes) > 0, 404);
        $reference = 'https://'.$host.'/event-check-in/'.$token;
        $apps = [];
        foreach ($schemes as $app) {
            if (! is_array($app) || ! preg_match('/^[a-z][a-z0-9-]{2,120}$/D', $app['scheme'] ?? '') || ! is_string($app['label'] ?? null)) continue;
            $apps[] = ['name' => $app['label'], 'url' => $app['scheme'].'://open?url='.rawurlencode($reference)];
        }
        abort_if($apps === [], 404);
        return response()->view('clubunio.event-checkin-entry', compact('apps', 'locale'))
            ->header('Content-Language', $locale)->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    }
}
