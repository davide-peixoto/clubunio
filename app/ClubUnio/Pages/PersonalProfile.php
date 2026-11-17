<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\KeycloakAccountConsole;
use Filament\Facades\Filament;
use Filament\Pages\Page;

final class PersonalProfile extends Page
{
    protected static ?string $slug = 'my-profile';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'clubunio.profile.backoffice';

    public function getTitle(): string
    {
        return trans('account-security.profile_title');
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor?->active && !$actor->api_only && !request()->attributes->has('clubunio_impersonation');
    }

    protected function getViewData(): array
    {
        return ['actor' => Filament::auth()->user(), 'accountConsole' => KeycloakAccountConsole::url()];
    }
}
