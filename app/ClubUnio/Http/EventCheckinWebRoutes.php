<?php

namespace App\ClubUnio\Http;

use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Support\Facades\Route;

final class EventCheckinWebRoutes
{
    public static function public(): void
    {
        Route::get('/event-check-in/{token}', EventCheckinEntryController::class)->where('token', '[a-f0-9]{64}')
            ->middleware('throttle:600,1')->name('clubunio.event-checkin.entry');
    }

    public static function board(): void
    {
        Route::get('/event-check-in-print/{tenant}/{event}', EventCheckinPrintController::class)
            ->whereNumber(['tenant', 'event'])
            ->middleware(['auth:clubunio', SetUpPanel::class.':clubunio', UseActiveTenant::class, 'throttle:30,1'])
            ->name('clubunio.event-checkin.print');
    }
}
