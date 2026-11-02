<?php

namespace App\Providers;

use App\ClubUnio\Mobile\ChatService;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\ServiceProvider;

final class MobileCommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\ClubUnio\Mobile\MalwareScanner::class, \App\ClubUnio\Mobile\ClamAvScanner::class);
    }

    public function boot(): void
    {
        // Disabled until reviewed additive migration/activation. Existing inserts are untouched then.
        TeamSeason::created(function (TeamSeason $team) {
            if (config('clubunio-mobile.enabled')) {
                app(ChatService::class)->provisionTeam(Tenant::whereKey($team->tenant_id)->firstOrFail(), (int) $team->id);
            }
        });
    }
}
