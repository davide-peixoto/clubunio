<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ClubUnioUserProviderTest extends TestCase
{
    public function test_cutover_rejects_only_the_configured_account_password(): void
    {
        config(['clubunio-keycloak-migration.legacy_disabled_accounts' => '18,24']);
        $provider = Auth::createUserProvider('clubunio_users');
        $hash = Hash::make('synthetic-password');
        $this->assertFalse($provider->validateCredentials((new Account)->forceFill(['id' => 18, 'password' => $hash]), ['password' => 'synthetic-password']));
        $this->assertTrue($provider->validateCredentials((new Account)->forceFill(['id' => 19, 'password' => $hash]), ['password' => 'synthetic-password']));

        config(['clubunio-verein-oidc.only' => true]);
        $this->assertFalse($provider->validateCredentials((new Account)->forceFill(['id' => 19, 'password' => $hash]), ['password' => 'synthetic-password']));
    }
}
