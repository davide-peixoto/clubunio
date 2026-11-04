<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileSponsorBillingRoutes
{
    // Called inside the existing sponsor-run prefix, itself inside personal clubs/{tenant}.
    public static function register(): void
    {
        Route::prefix('billing')->group(function () {
            Route::get('events/{event}', [MobileSponsorBillingController::class, 'index'])->whereNumber('event');
            Route::middleware(MobileSponsorBillingReady::class)->group(function () {
                Route::post('events/{event}/prepare', [MobileSponsorBillingController::class, 'prepare'])->whereNumber('event');
                Route::post('invoices/{invoice}/{action}', [MobileSponsorBillingController::class, 'action'])->whereNumber('invoice')
                    ->whereIn('action', MobileSponsorBillingData::ACTIONS);
                Route::get('invoices/{invoice}/pdf', [MobileSponsorBillingController::class, 'pdf'])->whereNumber('invoice');
            });
        });
    }
}
