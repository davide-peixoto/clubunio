<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\ClubhouseAccess;
use Filament\Facades\Filament;
use Filament\Pages\Page;

final class Clubhouse extends Page
{
    protected string $view = 'clubunio.clubhouse.page';

    protected static ?string $slug = 'clubhouse';

    public static function getNavigationLabel(): string
    {
        return trans('clubhouse.title');
    }

    public function getTitle(): string
    {
        return trans('clubhouse.title');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Anlagen & Kommunikation');
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $actor && $tenant && app(ClubhouseAccess::class)->allows($actor, $tenant, 'view');
    }
}
