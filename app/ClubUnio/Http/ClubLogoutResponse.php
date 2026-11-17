<?php

namespace App\ClubUnio\Http;

use Filament\Auth\Http\Responses\LogoutResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

final class ClubLogoutResponse extends LogoutResponse
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        return config('clubunio-verein-oidc.only')
            ? KeycloakLogout::redirect('clubunio-verein-oidc', $request)
            : parent::toResponse($request);
    }
}
