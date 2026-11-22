<?php

namespace App\ClubUnio\Wallet;

use Illuminate\Support\Facades\Route;

final class MemberWalletRoutes
{
    public static function mobile(): void
    {
        Route::get('members/{member}/id-card/wallet/apple', [MemberWalletController::class, 'download'])->whereNumber('member');
    }

    /** Root mounts this outside cookie sessions/CSRF; protocol has ApplePass auth. */
    public static function public(): void
    {
        Route::prefix('wallet/v1')->middleware('throttle:120,1')->group(function () {
            Route::post('devices/{device}/registrations/{type}/{serial}', [MemberWalletController::class, 'register']);
            Route::delete('devices/{device}/registrations/{type}/{serial}', [MemberWalletController::class, 'unregister']);
            Route::get('devices/{device}/registrations/{type}', [MemberWalletController::class, 'updates']);
            Route::get('passes/{type}/{serial}', [MemberWalletController::class, 'pass']);
            Route::post('log', [MemberWalletController::class, 'logs']);
        });
    }
}
