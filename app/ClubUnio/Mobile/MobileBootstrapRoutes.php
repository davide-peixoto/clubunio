<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Support\Facades\Route;
final class MobileBootstrapRoutes {
    public static function publicRoutes(): void { Route::get('mobile/v1/public/bootstrap',[MobileBootstrapController::class,'public']); }
    public static function personalRoutes(): void {
        Route::get('bootstrap',[MobileBootstrapController::class,'personal']);
        Route::get('role-guide',[MobileRoleGuideController::class,'show']);
    }
}
