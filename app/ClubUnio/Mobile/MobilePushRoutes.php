<?php
namespace App\ClubUnio\Mobile;
use Illuminate\Support\Facades\Route;
final class MobilePushRoutes
{
    /** Register under existing personal authentication; never integration/legacy middleware. */
    public static function registerAccount(): void
    {
        Route::put('push/devices', [MobilePushController::class, 'register']);
        Route::post('push/devices/status', [MobilePushController::class, 'status']);
        Route::delete('push/devices', [MobilePushController::class, 'revoke']);
    }
    public static function registerTenant(): void
    {
        Route::get('notifications', [MobileNotificationController::class, 'index']);
        Route::get('notifications/unread-count', [MobileNotificationController::class, 'count']);
        Route::post('notifications/read-all', [MobileNotificationController::class, 'readAll']);
        Route::post('notifications/{notice}/read', [MobileNotificationController::class, 'read'])->whereNumber('notice');
        Route::post('push/resolve', [MobilePushController::class, 'resolve']);
        Route::get('planning/events/{event}/squad/push-preview', [MobilePushController::class, 'squadPreview'])->whereNumber('event');
        Route::post('planning/events/{event}/squad/push', [MobilePushController::class, 'squad'])->whereNumber('event');
    }
}
