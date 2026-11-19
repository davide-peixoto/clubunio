<?php

use App\ClubUnio\Http\ImpersonationController;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:clubunio'])->prefix('support/impersonation')->name('clubunio.support.')->group(function () {
    Route::get('/', [ImpersonationController::class, 'index'])->middleware(SetUpPanel::class.':clubunio')->name('index');
    Route::post('/start', [ImpersonationController::class, 'start'])->middleware('throttle:5,1')->name('start');
    Route::post('/grant', [ImpersonationController::class, 'grant'])->middleware('throttle:5,1')->name('grant');
    Route::post('/stop', [ImpersonationController::class, 'stop'])->name('stop');
});
