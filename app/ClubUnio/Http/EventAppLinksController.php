<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\EventAppLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class EventAppLinksController
{
    public static function routes(): void
    {
        foreach (['/apple-app-site-association', '/.well-known/apple-app-site-association'] as $path) {
            Route::get($path, [self::class, 'apple']);
        }
        Route::get('/.well-known/assetlinks.json', [self::class, 'android']);
    }

    public function apple(Request $request)
    {
        abort_unless($request->isSecure(), 404);
        return response()->json(app(EventAppLinks::class)->apple($request->getHost()))
            ->withHeaders(['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function android(Request $request)
    {
        abort_unless($request->isSecure(), 404);
        return response()->json(app(EventAppLinks::class)->android($request->getHost()))
            ->withHeaders(['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
