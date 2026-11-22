<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileEventCheckinRoutes
{
    public static function global(): void
    {
        Route::get('event-check-ins/{token}/context', [MobileEventCheckinController::class, 'context'])->where('token', '[a-f0-9]{64}')->name('event-checkin.context');
    }
    public static function tenant(): void
    {
        Route::get('event-check-ins/{token}', [MobileEventCheckinController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('event-checkin.show');
        Route::post('event-check-ins/{token}', [MobileEventCheckinController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->middleware(EventCheckinThrottle::class)->name('event-checkin.confirm');
    }
}
