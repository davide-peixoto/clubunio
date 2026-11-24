<?php

use App\ClubUnio\Http\ClubhouseController;
use App\ClubUnio\Http\UseActiveTenant;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Support\Facades\Route;

Route::get('/public/clubhouse/{tenant:slug}', [ClubhouseController::class, 'public'])->middleware('throttle:120,1')->name('clubunio.clubhouse.public');
Route::get('/public/clubhouse/{tenant:slug}/data', [ClubhouseController::class, 'data'])->middleware('throttle:120,1')->name('clubunio.clubhouse.public.data');
Route::get('/public/clubhouse/{tenant:slug}/menus/{menu}/pdf', [ClubhouseController::class, 'publicDocument'])
    ->whereNumber('menu')->middleware('throttle:60,1')->name('clubunio.clubhouse.public.pdf');
Route::get('/clubhouse-menus/{menu}/pdf', [ClubhouseController::class, 'document'])->whereNumber('menu')
    ->middleware(['auth:clubunio', SetUpPanel::class.':clubunio', UseActiveTenant::class])->name('clubunio.clubhouse.pdf');
Route::get('/portal/{tenant:slug}/clubhouse', [ClubhouseController::class, 'portal'])->middleware('auth:portal')->name('clubunio.portal.clubhouse');
Route::get('/portal/{tenant:slug}/clubhouse/menus/{menu}/pdf', [ClubhouseController::class, 'portalDocument'])
    ->whereNumber('menu')->middleware('auth:portal')->name('clubunio.portal.clubhouse.pdf');
