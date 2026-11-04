<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Support\Facades\Route;
final class MobileKeyboxRoutes {
    public static function register(): void {
        Route::get('keyboxes',[MobileKeyboxController::class,'index']);
        Route::get('keyboxes/{keybox}/codes',[MobileKeyboxController::class,'show'])->whereNumber('keybox');
        Route::post('keyboxes/{keybox}/codes',[MobileKeyboxController::class,'issue'])->whereNumber('keybox');
        Route::get('keyboxes/{keybox}/codes/{command}',[MobileKeyboxController::class,'command'])->whereNumber('keybox')->whereUuid('command');
        Route::get('bus/reservations/{reservation}/keybox-codes',[MobileKeyboxController::class,'bus'])->whereNumber('reservation');
        Route::post('bus/reservations/{reservation}/keybox-codes',[MobileKeyboxController::class,'issueBus'])->whereNumber('reservation');
    }
}
