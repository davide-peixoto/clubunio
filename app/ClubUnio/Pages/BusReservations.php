<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

class BusReservations extends Page
{
    protected string $view = 'clubunio.planning-entry';

    protected static ?string $title = 'Busreservierungen';

    public static function getNavigationUrl(): string
    {
        return route('clubunio.workspace.bus.index');
    }

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return ['clubunio.workspace.bus.*', parent::getNavigationItemActiveRoutePattern()];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-truck';
    }

    public static function canAccess(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('bus');
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        abort_unless(static::canAccess(), 403);
        $this->redirect(route('clubunio.workspace.bus.index'));
    }
}
