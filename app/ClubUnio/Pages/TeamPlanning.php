<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

class TeamPlanning extends Page
{
    protected string $view = 'clubunio.planning-entry';

    protected static ?string $title = 'Teamplanung';

    public static function getNavigationGroup(): ?string
    {
        return 'Mannschaften';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant && Filament::auth()->user()?->canAccessTenant($tenant), 403);
        $this->redirect(route('clubunio.planning.index', $tenant->slug));
    }
}
