<?php

use App\ClubUnio\Http\MarketingController;
use Illuminate\Support\Facades\Route;

Route::get('/{locale}', MarketingController::class)
    ->where('locale', 'de|en')->name('clubunio.marketing');

Route::get('/{locale}/privacy', [MarketingController::class, 'privacy'])
    ->where('locale', 'de|en')->name('clubunio.marketing.privacy');
