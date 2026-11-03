<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileExpenseRoutes
{
    // Register inside the existing personal clubs/{tenant} group only.
    public static function register(): void {
        Route::prefix('expenses')->group(function () {
            Route::get('/', [MobileExpenseController::class, 'index']);
            Route::get('options', [MobileExpenseController::class, 'options']);
            Route::get('banks', [MobileExpenseController::class, 'banks']);
            Route::post('banks', [MobileExpenseController::class, 'bank']);
            Route::match(['PATCH','DELETE'], 'banks/{bank}', [MobileExpenseController::class, 'bank'])->whereNumber('bank');
            Route::post('receipts', [MobileExpenseController::class, 'uploadReceipt']);
            Route::delete('receipts/{receipt}', [MobileExpenseController::class, 'discardReceipt'])->whereUuid('receipt');
            Route::post('receipts/{receipt}/suggest', [MobileExpenseOcrController::class, 'suggest'])->whereUuid('receipt');
            Route::get('lines/{line}/file', [MobileExpenseController::class, 'file'])->whereNumber('line');
            Route::post('/', [MobileExpenseController::class, 'save']);
            Route::get('{expense}', [MobileExpenseController::class, 'show'])->whereNumber('expense');
            Route::patch('{expense}', [MobileExpenseController::class, 'save'])->whereNumber('expense');
            Route::post('{expense}/transition', [MobileExpenseController::class, 'transition'])->whereNumber('expense');
            Route::post('{expense}/uploads/{upload}/retry', [MobileExpenseController::class, 'retryUpload'])->whereNumber('expense')->whereNumber('upload');
            Route::post('{expense}/lines', [MobileExpenseController::class, 'line'])->whereNumber('expense');
            Route::match(['PATCH','DELETE'], '{expense}/lines/{line}', [MobileExpenseController::class, 'line'])->whereNumber('expense')->whereNumber('line');
        });
    }
}
