<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

class TeamPlanning extends Page
{
    protected string $view = 'clubunio.planning-entry';

    protected static ?string $title = 'Teamplanung';

    public static function getNavigationUrl(): string
    {
        return route('clubunio.workspace.planning.index');
    }

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return ['clubunio.workspace.planning.*', 'clubunio.workspace.training.*', parent::getNavigationItemActiveRoutePattern()];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Mannschaften';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public static function canAccess(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('planning');
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        abort_unless(static::canAccess(), 403);
        $this->redirect(route('clubunio.workspace.planning.index'));
    }
}
