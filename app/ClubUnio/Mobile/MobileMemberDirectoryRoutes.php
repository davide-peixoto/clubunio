<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileMemberDirectoryRoutes
{
    public static function register(): void
    {
        Route::get('members', [MobileMemberDirectoryController::class, 'index']);
        Route::get('members/change-requests', [MobileMemberDirectoryController::class, 'changes']);
    }
}
