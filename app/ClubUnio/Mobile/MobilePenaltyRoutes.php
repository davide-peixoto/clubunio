<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobilePenaltyRoutes
{
    public static function register(): void
    {
        Route::prefix('penalty')->controller(MobilePenaltyController::class)->group(function () {
            Route::get('options', 'options');
            Route::get('leaderboard', 'leaderboard');
            Route::post('sessions', 'start');
            Route::post('sessions/{round}/finish', 'finish')->where('round', '[a-f0-9]{48}');
            Route::post('leaderboard/reset', 'reset');
        });
    }
}
