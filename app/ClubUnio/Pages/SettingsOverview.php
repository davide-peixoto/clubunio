<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\NavigationStructure;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class SettingsOverview extends Page
{
    protected string $view = 'clubunio.settings-overview';

    protected static ?string $title = 'Einstellungen';

    protected static ?string $slug = 'configuration';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && (Filament::auth()->user()?->fresh()?->canAccessTenant($tenant) ?? false);
    }

    protected function getViewData(): array
    {
        $panel = Filament::getCurrentPanel();

        return ['sections' => NavigationStructure::partition($panel, $panel->getAuthorizedNavigation())['settings']];
    }
}
