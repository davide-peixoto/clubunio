<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileMemberDocumentRoutes
{
    // Register only inside the existing personal clubs/{tenant} group.
    public static function register(): void
    {
        Route::prefix('member-documents')->group(function () {
            Route::get('/', [MobileMemberDocumentController::class, 'index']);
            Route::get('types', [MobileMemberDocumentController::class, 'types']);
            Route::get('members', [MobileMemberDocumentController::class, 'members']);
            Route::post('/', [MobileMemberDocumentController::class, 'upload']);
            Route::get('files/{document}', [MobileMemberDocumentController::class, 'showFile'])->whereNumber('document');
            Route::get('files/{document}/pdf', [MobileMemberDocumentController::class, 'filePdf'])->whereNumber('document');
            Route::get('archive/{archive}', [MobileMemberDocumentController::class, 'showArchive'])->whereNumber('archive');
            Route::get('archive/{archive}/pdf', [MobileMemberDocumentController::class, 'archivePdf'])->whereNumber('archive');
            Route::post('archive/{archive}/sign', [MobileMemberDocumentController::class, 'signArchive'])->whereNumber('archive');
            Route::get('{receipt}', [MobileMemberDocumentController::class, 'show'])->whereNumber('receipt');
            Route::get('{receipt}/pdf', [MobileMemberDocumentController::class, 'pdf'])->whereNumber('receipt');
            Route::post('{receipt}/sign', [MobileMemberDocumentController::class, 'sign'])->whereNumber('receipt');
        });
    }
}
