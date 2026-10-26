<?php

use App\ClubUnio\Http\ImpersonationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:clubunio'])->prefix('support/impersonation')->name('clubunio.support.')->group(function () {
    Route::get('/', [ImpersonationController::class, 'index'])->name('index');
    Route::post('/start', [ImpersonationController::class, 'start'])->middleware('throttle:5,1')->name('start');
    Route::post('/grant', [ImpersonationController::class, 'grant'])->middleware('throttle:5,1')->name('grant');
    Route::post('/stop', [ImpersonationController::class, 'stop'])->name('stop');
});
