<?php

namespace App\ClubUnio\Services;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

final class ClubUnioUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $disabled = explode(',', (string) config('clubunio-keycloak-migration.legacy_disabled_accounts'));

        return !config('clubunio-verein-oidc.only')
            && !in_array((string) $user->getAuthIdentifier(), $disabled, true)
            && parent::validateCredentials($user, $credentials);
    }
}
