<?php

use App\ClubUnio\Http\{SocialInsightsController, UseActiveTenant};
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:clubunio',SetUpPanel::class.':clubunio',UseActiveTenant::class,'throttle:20,1'])->group(function () {
    Route::post('/social-connect/{platform}',[SocialInsightsController::class,'start'])
        ->where('platform','instagram|facebook')->name('clubunio.social-insights.start');
    Route::get('/social-connect/callback',[SocialInsightsController::class,'callback'])->name('clubunio.social-insights.callback');
});
