<?php

use App\ClubUnio\Http\ApiController;
use App\ClubUnio\Http\ApiFileController;
use App\ClubUnio\Http\ApiProviderController;
use App\ClubUnio\Http\ApiReportController;
use App\ClubUnio\Http\ApiUploadController;
use App\ClubUnio\Http\AuthenticateApiIntegration;
use App\ClubUnio\Http\BindApiTenantDomain;
use App\ClubUnio\Http\WebsiteApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateApiIntegration::class, BindApiTenantDomain::class])->group(function () {
    Route::get('context', [ApiController::class, 'context'])->name('clubunio.api.context');
    Route::get('sfv/schedule', [ApiProviderController::class, 'schedule'])->name('clubunio.api.sfv.schedule');
    Route::post('provider-actions/{operation}', [ApiProviderController::class, 'stage'])->name('clubunio.api.provider.stage');
    Route::get('provider-work/{id}', [ApiProviderController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.provider.show');
    Route::post('provider-work/{id}/process', [ApiProviderController::class, 'process'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.provider.process');
    Route::get('reports/{kind}', [ApiReportController::class, 'index'])->name('clubunio.api.report');
    Route::get('exports/{kind}', [ApiReportController::class, 'export'])->name('clubunio.api.export');
    Route::get('files/{kind}/{id}', [ApiFileController::class, 'open'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.file');
    Route::post('uploads/{kind}/{id}', [ApiUploadController::class, 'stage'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.upload.stage');
    Route::get('uploads/{id}', [ApiUploadController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.upload.show');
    Route::post('uploads/{id}/process', [ApiUploadController::class, 'process'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.upload.process');
    foreach (['teams', 'fixtures', 'training-times', 'facility-status'] as $feed) {
        Route::get('website/'.$feed, [WebsiteApiController::class, 'index'])->defaults('feed', $feed)->name('clubunio.api.website.'.$feed);
    }
    Route::get('{resource}', [ApiController::class, 'index'])->name('clubunio.api.index');
    Route::get('{resource}/{id}', [ApiController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.show');
    Route::post('{resource}', [ApiController::class, 'create'])->name('clubunio.api.create');
    Route::patch('{resource}/{id}', [ApiController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.update');
    Route::post('{resource}/{id}/actions/{action}', [ApiController::class, 'action'])->where('id', '[1-9][0-9]{0,17}')->name('clubunio.api.action');
});
