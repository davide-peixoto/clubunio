<?php

use App\ClubUnio\Mobile\MobileController as Mobile;
use App\ClubUnio\Mobile\MobileMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->middleware(MobileMiddleware::class)->name('clubunio.mobile.')->group(function () {
    Route::post('session', [Mobile::class, 'login'])->name('login');
    Route::get('context', [Mobile::class, 'context'])->name('context');
    Route::get('sessions', [Mobile::class, 'sessions'])->name('sessions');
    Route::delete('session', [Mobile::class, 'revoke'])->name('logout');
    Route::delete('sessions/{session}', [Mobile::class, 'revoke'])->whereUuid('session')->name('revoke');
    Route::prefix('clubs/{tenant}')->whereNumber('tenant')->group(function () {
        Route::get('teams', [Mobile::class, 'teams']);
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
        Route::post('ai', [Mobile::class, 'ai']);
    });
});
