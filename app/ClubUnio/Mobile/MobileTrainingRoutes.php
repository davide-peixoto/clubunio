<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobileTrainingRoutes
{
    public static function register(): void
    {
        Route::prefix('training-plans')->controller(MobileTrainingController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('options', 'options');
            Route::get('share-teams', 'options');
            Route::post('generate', 'generate');
            Route::get('work/{work}', 'work')->whereUuid('work');
            Route::get('{plan}', 'show')->whereNumber('plan');
            Route::patch('{plan}', 'save')->whereNumber('plan');
            Route::get('{plan}/pdf', 'pdf')->whereNumber('plan');
            Route::post('{plan}/share', 'share')->whereNumber('plan');
            Route::post('{plan}/review', 'review')->whereNumber('plan');
            Route::post('{plan}/review/apply', 'apply')->whereNumber('plan');
        });
    }
}
