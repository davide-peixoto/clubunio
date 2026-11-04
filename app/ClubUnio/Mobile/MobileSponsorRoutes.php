<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Support\Facades\Route;
final class MobileSponsorRoutes {
    public static function register(): void {
        Route::prefix('sponsor-run')->group(function () {
            MobileSponsorBillingRoutes::register();
            Route::get('events', [MobileSponsorController::class, 'events']);
            Route::post('events', [MobileSponsorController::class, 'saveEvent']);
            Route::get('teams', [MobileSponsorController::class, 'teams']);
            Route::patch('events/{event}', [MobileSponsorController::class, 'saveEvent'])->whereNumber('event');
            Route::get('events/{event}/participants', [MobileSponsorController::class, 'participants'])->whereNumber('event');
            Route::get('events/{event}/helpers', [MobileSponsorController::class, 'helpers'])->whereNumber('event');
            Route::get('events/{event}/helper-candidates', [MobileSponsorController::class, 'helperCandidates'])->whereNumber('event');
            Route::post('events/{event}/helpers', [MobileSponsorController::class, 'saveHelper'])->whereNumber('event');
            Route::get('events/{event}/billing', [MobileSponsorController::class, 'billing'])->whereNumber('event');
            Route::get('participants/{participant}/pledges', [MobileSponsorController::class, 'pledges'])->whereNumber('participant');
            Route::post('participants/{participant}/pledges', [MobileSponsorController::class, 'pledge'])->whereNumber('participant');
            Route::patch('participants/{participant}/operations', [MobileSponsorController::class, 'operations'])->whereNumber('participant');
            Route::patch('participants/{participant}/result', [MobileSponsorController::class, 'approve'])->whereNumber('participant');
            Route::get('participants/{participant}/share-link', [MobileSponsorController::class, 'share'])->whereNumber('participant');
            Route::post('participants/{participant}/share-link', [MobileSponsorController::class, 'share'])->whereNumber('participant');
        });
    }
}
