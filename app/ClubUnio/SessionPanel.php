<?php

namespace App\ClubUnio;

use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

class SessionPanel extends Panel
{
    public function getUrl(?Model $tenant = null): ?string
    {
        return url('/');
    }

    public function getRedirectUrl(?Model $tenant = null): ?string
    {
        return $this->auth()->check() ? url('/') : $this->getLoginUrl();
    }
}
