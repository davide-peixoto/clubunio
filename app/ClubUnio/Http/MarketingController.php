<?php

namespace App\ClubUnio\Http;

use Illuminate\Http\Request;

final class MarketingController
{
    public function __invoke(Request $request, string $locale)
    {
        abort_unless(array_key_exists($locale, config('clubunio-languages.enabled', [])), 404);
        app()->setLocale($locale);

        return response()->view('clubunio.marketing', [
            'locale' => $locale,
            'languages' => config('clubunio-languages.enabled'),
            'contact' => config('clubunio-marketing.contact'),
            'plans' => config('clubunio-marketing.plans'),
        ])->header('Content-Language', $locale);
    }
}
