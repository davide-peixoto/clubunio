<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileParentAccessRoutes
{
    public static function register(): void
    {
        Route::get('profile/parent-access', [MobileParentAccessController::class, 'index']);
        Route::post('profile/parent-access', [MobileParentAccessController::class, 'grant']);
        Route::post('profile/parent-access/{link}/{operation}', [MobileParentAccessController::class, 'decide'])
            ->whereNumber('link')->whereIn('operation', ['approve', 'revoke']);
    }
}
