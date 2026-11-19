<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobilePublicIdentityRoutes
{
    /** Register outside the authenticated MobileMiddleware group; proof is purpose-specific. */
    public static function register(): void
    {
        Route::prefix('mobile/v1/public/identity')->controller(MobilePublicIdentityController::class)->group(function () {
            Route::get('clubs', 'clubs');
            Route::get('clubs/{tenant}/options', 'options')->whereNumber('tenant');
            Route::post('clubs/{tenant}/register', 'register')->whereNumber('tenant');
            Route::post('clubs/{tenant}/forgot-password', 'forgot')->whereNumber('tenant');
            Route::match(['GET','POST'], 'clubs/{tenant}/activate', 'activate')->whereNumber('tenant');
            Route::match(['GET','POST'], 'clubs/{tenant}/reset-password', 'reset')->whereNumber('tenant');
            Route::match(['GET','POST'], 'clubs/{tenant}/confirm-email', 'confirmEmail')->whereNumber('tenant');
            Route::post('clubs/{tenant}/membership', 'membership')->whereNumber('tenant');
        });
    }
    /** Register inside the existing personal clubs/{tenant} group only. */
    public static function registerPersonal(): void
    {
        Route::get('identity/email',[MobileIdentityEnrollmentController::class,'show']);
        Route::post('identity/email',[MobileIdentityEnrollmentController::class,'store']);
        Route::get('membership-intake', [MobileFamilyIntakeController::class, 'options']);
        Route::post('membership-intake', [MobileFamilyIntakeController::class, 'submit']);
    }
}
