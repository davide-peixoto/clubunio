<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\AccountSecurity;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class Login extends \Filament\Auth\Pages\Login
{
    public function mount(): void
    {
        if (config('clubunio-verein-oidc.only') && !Filament::auth()->check()) {
            $this->redirect(route('clubunio.verein.oidc.start'));

            return;
        }
        parent::mount();
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        if ($response && ($actor = Filament::auth()->user()) && \App\ClubUnio\Http\PlatformOidcController::requiresClubOidc($actor)) {
            Filament::auth()->logout();
            session()->forget(['clubunio.oidc.expires', 'clubunio.oidc.account']);
            $this->redirect(route('clubunio.verein.oidc.start'));
            return null;
        }
        if ($response && ($actor = Filament::auth()->user()) && app(AccountSecurity::class)->requiresStrongLogin($actor)) {
            Filament::auth()->logout();
            session()->regenerate();
            abort_unless(config('clubunio-verein-oidc.enabled'), 403);
            $this->redirect(route('clubunio.verein.oidc.start'));
            return null;
        }
        return $response;
    }

    protected function getFormActions(): array
    {
        $actions = parent::getFormActions();
        if (config('clubunio-verein-oidc.enabled')) {
            $actions[] = Action::make('keycloak')->label(trans('account-security.keycloak_sign_in'))
                ->url(route('clubunio.verein.oidc.start'))->color('gray');
        }
        return $actions;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([$this->getEmailFormComponent(), $this->getPasswordFormComponent()]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('Benutzername')->required()->autocomplete('username')->autofocus();
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return ['username' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'active' => true, 'api_only' => false];
    }
}
