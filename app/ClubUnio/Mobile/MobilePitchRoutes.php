<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Route;

final class MobilePitchRoutes
{
    public static function register(): void
    {
        Route::get('pitch-planning/options', [MobilePitchController::class, 'options'])->name('pitch.options');
        Route::get('pitch-planning/calendar', [MobilePitchController::class, 'calendar'])->name('pitch.calendar');
        Route::get('pitch-planning/requests', [MobilePitchController::class, 'requests'])->name('pitch.requests');
        Route::post('pitch-planning/requests', [MobilePitchController::class, 'request'])->name('pitch.request');
        Route::post('pitch-planning/move', [MobilePitchController::class, 'move'])->name('pitch.move');
        Route::post('pitch-planning/requests/{booking}/review', [MobilePitchController::class, 'review'])
            ->where('booking', '[1-9][0-9]{0,17}')->name('pitch.review');
        Route::post('pitch-planning/competitions/{competition}/teams', [MobilePitchController::class, 'competitionTeams'])
            ->where('competition', '[1-9][0-9]{0,17}')->name('pitch.competition-teams');
        Route::get('my-teams/{team}/pitch-calendar', [MobilePitchController::class, 'teamCalendar'])
            ->where('team', '[1-9][0-9]{0,17}')->name('pitch.team-calendar');
    }
}
