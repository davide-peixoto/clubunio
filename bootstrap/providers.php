<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\ClubUnioPanelProvider;

return [
    AppServiceProvider::class,
    App\Providers\LanguageServiceProvider::class,
    ClubUnioPanelProvider::class,
    App\Providers\TenantNavigationServiceProvider::class,
    App\Providers\TenantBoundaryServiceProvider::class,
    App\Providers\ImpersonationServiceProvider::class,
    App\Providers\MobileCommunicationServiceProvider::class,
];
