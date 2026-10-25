<?php

namespace App\ClubUnio\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class Login extends \Filament\Auth\Pages\Login
{
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
