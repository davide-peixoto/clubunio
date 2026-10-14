<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

class BusReservations extends Page
{
    protected string $view = 'clubunio.planning-entry';

    protected static ?string $title = 'Busreservierungen';

    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-truck';
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant && Filament::auth()->user()?->canAccessTenant($tenant), 403);
        $this->redirect(route('clubunio.bus.index', $tenant->slug));
    }
}
