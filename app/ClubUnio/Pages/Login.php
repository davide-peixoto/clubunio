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
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        if ($response && ($actor = Filament::auth()->user()) && app(AccountSecurity::class)->requiresStrongLogin($actor)) {
            Filament::auth()->logout();
            session()->regenerate();
            $this->redirect(route('clubunio.security.show', ['setup' => app(AccountSecurity::class)->mustEnroll($actor) ? '1' : null]));
            return null;
        }
        return $response;
    }

    protected function getFormActions(): array
    {
        $actions = parent::getFormActions();
        if (app(AccountSecurity::class)->enabled()) {
            $actions[] = Action::make('strongLogin')->label(trans('account-security.strong_login'))
                ->url(route('clubunio.security.show'))->color('gray');
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
