<?php

namespace App\ClubUnio\Http;

use Illuminate\Support\Facades\Route;

final class MemberCardRoutes
{
    public static function public(): void
    {
        Route::get('/member-card/{token}', MemberCardVerificationController::class)
            ->where('token', '[a-f0-9]{64}')->middleware('throttle:90,1')->name('clubunio.member-card.verify');
    }
}
