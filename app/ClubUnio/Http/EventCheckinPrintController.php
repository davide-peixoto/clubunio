<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\EventCheckinPoster;
use App\ClubUnio\Models\Account;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

final class EventCheckinPrintController
{
    public function __invoke(Request $request, int $tenant, int $event)
    {
        $current = Filament::getTenant();
        abort_unless($current && (int) $current->id === $tenant, 403);
        $actor = $request->user('clubunio');
        abort_unless($actor instanceof Account && $actor->active, 401);
        $data = app(EventCheckinPoster::class)->data($actor, $current, $event);
        return response()->view('clubunio.event-checkin-print', $data)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    }
}
