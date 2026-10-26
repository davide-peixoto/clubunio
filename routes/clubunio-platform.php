<?php

use App\ClubUnio\Http\PlatformAdministrationController;
use App\ClubUnio\Http\RequirePlatformAdministrator;
use Illuminate\Support\Facades\Route;

Route::get('/platform/login', [PlatformAdministrationController::class, 'loginForm'])->name('clubunio.platform.login');
Route::post('/platform/login', [PlatformAdministrationController::class, 'login'])->middleware('throttle:5,1');
Route::prefix('platform')->middleware(RequirePlatformAdministrator::class)->group(function () {
    Route::get('/', [PlatformAdministrationController::class, 'index'])->name('clubunio.platform.index');
    Route::post('/tenants', [PlatformAdministrationController::class, 'create'])->middleware('throttle:10,1')->name('clubunio.platform.create');
    Route::post('/accounts', [PlatformAdministrationController::class, 'createAccount'])->middleware('throttle:10,1')->name('clubunio.platform.account.create');
    Route::get('/tenants/{id}', [PlatformAdministrationController::class, 'show'])->whereNumber('id')->name('clubunio.platform.show');
    Route::post('/tenants/{id}', [PlatformAdministrationController::class, 'change'])->whereNumber('id')->middleware('throttle:30,1')->name('clubunio.platform.change');
    Route::post('/logout', [PlatformAdministrationController::class, 'logout'])->name('clubunio.platform.logout');
});
