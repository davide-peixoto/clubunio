<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileMemberIdCardRoutes
{
    public static function register(): void
    {
        Route::get('members/{member}/id-card', [MobileMemberIdCardController::class, 'show'])->whereNumber('member');
        Route::post('members/{member}/id-card/{operation}', [MobileMemberCardSecurityController::class, 'change'])
            ->whereNumber('member')->whereIn('operation', ['rotate', 'revoke'])->middleware('throttle:6,1');
    }
}
