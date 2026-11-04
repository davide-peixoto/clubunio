<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileAdminNoticeRoutes
{
    public static function register(): void
    {
        Route::get('push-admin/options', [MobileAdminNoticeController::class, 'options']);
        Route::get('push-admin/lookup', [MobileAdminNoticeController::class, 'lookup']);
        Route::post('push-admin/preview', [MobileAdminNoticeController::class, 'preview']);
        Route::post('push-admin/notices', [MobileAdminNoticeController::class, 'send']);
        Route::get('push-admin/notices/{notice}', [MobileAdminNoticeController::class, 'receipt'])->whereNumber('notice');
        Route::get('admin-notices/{notice}', [MobileAdminNoticeController::class, 'content'])->whereNumber('notice');
    }
}
