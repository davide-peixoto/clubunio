<?php

use App\ClubUnio\Http\MarketingController;
use Illuminate\Support\Facades\Route;

Route::get('/{locale}', MarketingController::class)
    ->where('locale', 'de|en')->name('clubunio.marketing');

Route::get('/{locale}/privacy', [MarketingController::class, 'privacy'])
    ->where('locale', 'de|en')->name('clubunio.marketing.privacy');

Route::post('/{locale}/inquiry', [MarketingController::class, 'inquiry'])
    ->where('locale', 'de|en')->middleware('throttle:5,1')->name('clubunio.marketing.inquiry');
