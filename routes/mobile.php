<?php

use App\ClubUnio\Mobile\MobileController as Mobile;
use App\ClubUnio\Mobile\MobileMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->middleware(MobileMiddleware::class)->name('clubunio.mobile.')->group(function () {
    Route::post('session', [Mobile::class, 'login'])->name('login');
    Route::post('preferences', [Mobile::class, 'preferences']);
    Route::get('context', [Mobile::class, 'context'])->name('context');
    Route::get('profile', [\App\ClubUnio\Mobile\MobileAccountController::class, 'profile'])->name('profile');
    Route::post('password', [\App\ClubUnio\Mobile\MobileAccountController::class, 'password'])->name('password');
    Route::get('sessions', [Mobile::class, 'sessions'])->name('sessions');
    Route::delete('session', [Mobile::class, 'revoke'])->name('logout');
    Route::delete('sessions/{session}', [Mobile::class, 'revoke'])->whereUuid('session')->name('revoke');
    Route::prefix('clubs/{tenant}')->whereNumber('tenant')->group(function () {
        Route::get('modules', [\App\ClubUnio\Mobile\MobileAccountController::class, 'modules'])->name('modules');
        Route::get('teams', [Mobile::class, 'teams']);
        Route::get('planning/teams', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'teams']);
        Route::get('my-teams', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'index']);
        Route::get('my-teams/members', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'members']);
        Route::get('my-teams/staff-functions', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'functions']);
        Route::get('my-teams/{team}', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'show'])->whereNumber('team');
        Route::get('my-teams/{team}/trainings', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'trainings'])->whereNumber('team');
        Route::post('my-teams/{team}/{kind}', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'assignment'])->whereNumber('team')->whereIn('kind', ['staff', 'players']);
        Route::delete('my-teams/{team}/{kind}/{assignment}', [\App\ClubUnio\Mobile\MobileTeamsController::class, 'assignment'])->whereNumber('team')->whereIn('kind', ['staff', 'players'])->whereNumber('assignment');
        Route::get('planning/absences', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'absences']);
        Route::post('planning/absences', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'saveAbsence']);
        Route::match(['PATCH', 'DELETE'], 'planning/absences/{absence}', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'saveAbsence'])->whereNumber('absence');
        Route::get('planning/events', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'events']);
        Route::get('planning/events/{event}', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'event'])->whereNumber('event');
        Route::post('planning/events', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'save']);
        Route::post('planning/event-batches', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'batch']);
        Route::patch('planning/events/{event}', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'save'])->whereNumber('event');
        Route::delete('planning/events/{event}', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'remove'])->whereNumber('event');
        Route::post('planning/events/{event}/cancel', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'cancel'])->whereNumber('event');
        Route::post('planning/events/{event}/participation', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'participate'])->whereNumber('event');
        Route::post('planning/events/{event}/attendance', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'attendance'])->whereNumber('event');
        Route::post('planning/events/{event}/guests', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'guest'])->whereNumber('event');
        Route::match(['PATCH', 'DELETE'], 'planning/events/{event}/guests/{guest}', [\App\ClubUnio\Mobile\MobilePlanningController::class, 'guest'])->whereNumber('event')->whereNumber('guest');



        Route::get('contacts', [Mobile::class, 'contacts']);
        Route::get('chats', [Mobile::class, 'chats']);
        Route::post('direct', [Mobile::class, 'direct']);
        Route::get('chats/{chat}/messages', [Mobile::class, 'messages'])->whereNumber('chat');
        Route::post('chats/{chat}/messages', [Mobile::class, 'send'])->whereNumber('chat');
        Route::post('chats/{chat}/read', [Mobile::class, 'read'])->whereNumber('chat');
        Route::post('chats/{chat}/mute', [Mobile::class, 'mute'])->whereNumber('chat');
        Route::post('polls/{poll}/vote', [Mobile::class, 'vote'])->whereNumber('poll');
        Route::get('events', [Mobile::class, 'events']);
        Route::get('events/{event}', [Mobile::class, 'event'])->whereNumber('event');
        Route::post('events/{event}/participate', [Mobile::class, 'participate'])->whereNumber('event');
        Route::post('events/{event}/chat', [Mobile::class, 'eventChat'])->whereNumber('event');
        Route::get('invoices/{invoice}', [Mobile::class, 'invoice'])->whereNumber('invoice');
        Route::get('invoices/{invoice}/pdf', [Mobile::class, 'invoicePdf'])->whereNumber('invoice');
        Route::get('inbox', [Mobile::class, 'inbox']);
        Route::get('chats/{chat}/changes', [Mobile::class, 'changes'])->whereNumber('chat');
        Route::post('messages/{message}/edit', [Mobile::class, 'editMessage'])->whereNumber('message');
        Route::post('messages/{message}/delete', [Mobile::class, 'deleteMessage'])->whereNumber('message');
        Route::post('messages/{message}/report', [Mobile::class, 'reportMessage'])->whereNumber('message');
        Route::get('chats/{chat}/reports', [Mobile::class, 'reports'])->whereNumber('chat');
        Route::post('reports/{report}/resolve', [Mobile::class, 'resolveReport'])->whereNumber('report');
        Route::get('announcements/preview', [Mobile::class, 'announcementPreview']);
        Route::get('blocks', [Mobile::class, 'blocks']);
        Route::post('blocks', [Mobile::class, 'block']);
        Route::post('inbox/{notice}/read', [Mobile::class, 'inboxRead'])->whereNumber('notice');
        Route::post('chats/{chat}/attachments', [Mobile::class, 'uploadAttachment'])->whereNumber('chat');
        Route::post('attachments/{attachment}/scan', [Mobile::class, 'scanAttachment'])->whereUuid('attachment');
        Route::delete('attachments/{attachment}', [Mobile::class, 'cancelAttachment'])->whereUuid('attachment');
        Route::get('attachments/{attachment}', [Mobile::class, 'downloadAttachment'])->whereUuid('attachment');
        Route::post('ai', [Mobile::class, 'ai']);
    });
});
