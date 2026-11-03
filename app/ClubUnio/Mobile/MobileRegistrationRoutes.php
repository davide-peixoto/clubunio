<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileRegistrationRoutes
{
    public static function register(): void
    {
        Route::prefix('registrations')->group(function () {
            Route::get('/', [MobileRegistrationController::class, 'index']);
            Route::get('options', [MobileRegistrationController::class, 'options']);
            Route::post('/', [MobileRegistrationController::class, 'create']);
            Route::get('{registration}', [MobileRegistrationController::class, 'show'])->whereNumber('registration');
            Route::get('{registration}/members', [MobileRegistrationController::class, 'members'])->whereNumber('registration');
            Route::post('{registration}/intake', [MobileRegistrationController::class, 'intake'])->whereNumber('registration');
            Route::post('{registration}/review', [MobileRegistrationController::class, 'review'])->whereNumber('registration');
            Route::post('{registration}/files', [MobileRegistrationController::class, 'upload'])->whereNumber('registration');
            Route::get('{registration}/files/{file}', [MobileRegistrationController::class, 'file'])->whereNumber(['registration', 'file']);
            Route::post('{registration}/files/{file}/remove', [MobileRegistrationController::class, 'remove'])->whereNumber(['registration', 'file']);
            Route::post('{registration}/uploads/{upload}/retry', [MobileRegistrationController::class, 'retry'])->whereNumber(['registration', 'upload']);
        });
    }
}
