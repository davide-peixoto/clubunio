<?php

use App\ClubUnio\Http\PlatformAdministrationController;
use App\ClubUnio\Http\RequirePlatformAdministrator;
use Illuminate\Support\Facades\Route;

Route::get('/vereinslogin/oidc/start', [\App\ClubUnio\Http\PlatformOidcController::class, 'start'])
    ->middleware('throttle:20,1')->block(10,10)->name('clubunio.verein.oidc.start');
Route::get('/vereinslogin/oidc/callback', [\App\ClubUnio\Http\PlatformOidcController::class, 'callback'])
    ->middleware('throttle:20,1')->block(10,10)->name('clubunio.verein.oidc.callback');
Route::get('/vereinslogin/oidc/invitation', [\App\ClubUnio\Http\PlatformOidcController::class, 'invitation'])
    ->middleware('throttle:20,1')->name('clubunio.verein.oidc.invite');
Route::post('/vereinslogin/oidc/invitation', [\App\ClubUnio\Http\PlatformOidcController::class, 'start'])
    ->middleware('throttle:10,1')->block(10,10)->name('clubunio.verein.oidc.invite.accept');
Route::get('/vereinslogin/oidc/mobile/native', [\App\ClubUnio\Http\PlatformOidcController::class, 'start'])
    ->middleware('throttle:20,1')->name('clubunio.verein.oidc.mobile.native');
Route::get('/vereinslogin/oidc/mobile', [\App\ClubUnio\Http\PlatformOidcController::class, 'mobile'])
    ->middleware('throttle:20,1')->name('clubunio.verein.oidc.mobile');
Route::post('/vereinslogin/oidc/mobile', [\App\ClubUnio\Http\PlatformOidcController::class, 'start'])
    ->middleware('throttle:10,1')->block(10,10)->name('clubunio.verein.oidc.mobile.start');

Route::get('/club-logo/{id}/{version}.png', [PlatformAdministrationController::class, 'logo'])
    ->whereNumber('id')->where('version', '[a-f0-9]{64}')->middleware('throttle:120,1')->name('clubunio.tenant-logo');
Route::get('/platform/login', [PlatformAdministrationController::class, 'loginForm'])->name('clubunio.platform.login');
Route::post('/platform/login', [PlatformAdministrationController::class, 'login'])->middleware('throttle:5,1');
Route::get('/platform/oidc/start', [\App\ClubUnio\Http\PlatformOidcController::class, 'start'])
    ->middleware('throttle:20,1')->block(10,10)->name('clubunio.platform.oidc.start');
Route::get('/platform/oidc/callback', [\App\ClubUnio\Http\PlatformOidcController::class, 'callback'])
    ->middleware('throttle:20,1')->block(10,10)->name('clubunio.platform.oidc.callback');
Route::get('/vereinslogin/oidc/platform-migration', [\App\ClubUnio\Http\PlatformOidcController::class, 'start'])
    ->middleware('throttle:20,1')->block(10,10)->name('clubunio.verein.oidc.platform-migration');
Route::prefix('platform')->middleware(RequirePlatformAdministrator::class)->group(function () {
    Route::get('/profile', [\App\ClubUnio\Http\PersonalProfileController::class, 'platform'])->name('clubunio.platform.profile');
    Route::get('/users', [PlatformAdministrationController::class, 'platformUsers'])->name('clubunio.platform.users');
    Route::post('/users', [PlatformAdministrationController::class, 'createPlatformUser'])->middleware('throttle:10,1')->name('clubunio.platform.users.create');
    Route::post('/users/{account}', [PlatformAdministrationController::class, 'changePlatformUser'])
        ->whereNumber('account')->middleware('throttle:20,1')->name('clubunio.platform.users.change');
    Route::post('/users/{account}/keycloak-invitation', [PlatformAdministrationController::class, 'invitePlatformUser'])
        ->whereNumber('account')->middleware('throttle:4,1')->block(45, 5)->name('clubunio.platform.users.invite');
    Route::get('/', [PlatformAdministrationController::class, 'index'])->name('clubunio.platform.index');
    Route::post('/tenants', [PlatformAdministrationController::class, 'create'])->middleware('throttle:10,1')->name('clubunio.platform.create');
    Route::post('/accounts', [PlatformAdministrationController::class, 'createAccount'])->middleware('throttle:10,1')->name('clubunio.platform.account.create');
    Route::get('/tenants/{id}', [PlatformAdministrationController::class, 'show'])->whereNumber('id')->name('clubunio.platform.show');
    Route::post('/tenants/{id}', [PlatformAdministrationController::class, 'change'])->whereNumber('id')->middleware('throttle:30,1')->name('clubunio.platform.change');
    Route::post('/tenants/{id}/keycloak-migration', [PlatformAdministrationController::class, 'keycloakMigration'])
        ->whereNumber('id')->middleware('throttle:6,1')->block(120, 5)->name('clubunio.platform.keycloak-migration');
    Route::post('/logout', [PlatformAdministrationController::class, 'logout'])->name('clubunio.platform.logout');
});
