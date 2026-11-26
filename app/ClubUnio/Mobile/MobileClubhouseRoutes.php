<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileClubhouseRoutes
{
    public static function register(): void
    {
        Route::prefix('clubhouse')->name('clubhouse.')->group(function () {
            Route::get('/', [MobileClubhouseController::class, 'index'])->name('index');
            Route::get('manage', [MobileClubhouseController::class, 'manage'])->name('manage');
            Route::post('openings', [MobileClubhouseController::class, 'opening'])->name('opening.create');
            Route::patch('openings/{opening}', [MobileClubhouseController::class, 'opening'])->whereNumber('opening')->name('opening.update');
            Route::post('exceptions', [MobileClubhouseController::class, 'exception'])->name('exception.create');
            Route::delete('exceptions/{exception}', [MobileClubhouseController::class, 'removeException'])->whereNumber('exception')->name('exception.remove');
            Route::post('menus', [MobileClubhouseController::class, 'menu'])->name('menu.create');
            Route::patch('menus/{menu}', [MobileClubhouseController::class, 'menu'])->whereNumber('menu')->name('menu.update');
            Route::get('menus/{menu}/pdf', [MobileClubhouseController::class, 'document'])->whereNumber('menu')->name('menu.pdf');
            Route::post('menus/{menu}/pdf', [MobileClubhouseController::class, 'upload'])->whereNumber('menu')->name('menu.upload');
            Route::delete('menus/{menu}/pdf', [MobileClubhouseController::class, 'removeDocument'])->whereNumber('menu')->name('menu.remove-pdf');
            Route::post('menus/{menu}/{operation}', [MobileClubhouseController::class, 'transition'])->whereNumber('menu')
                ->whereIn('operation', ['publish', 'withdraw', 'archive', 'restore'])->name('menu.transition');
        });
    }
}
