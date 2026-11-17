<?php

namespace Tests\Feature;

use App\ClubUnio\Services\KeycloakMigrationGateway;
use App\ClubUnio\Services\KeycloakMigrationNames;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KeycloakMigrationGatewayTest extends TestCase
{
    private const BASE = 'https://keycloak.test';
    private const ID = '12345678-1234-1234-1234-123456789abc';
    private const EMAIL = 'synthetic@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        config(['clubunio-verein-oidc.issuer' => self::BASE.'/realms/test',
            'clubunio-keycloak-migration.client' => 'synthetic-client',
            'clubunio-keycloak-migration.secret' => 'synthetic-secret']);
        Http::preventStrayRequests();
    }

    public function test_existing_email_never_provisions_or_adopts_identity(): void
    {
        Http::fake([self::BASE.'/realms/test/protocol/openid-connect/token' => Http::response(['access_token' => 'synthetic']),
            self::BASE.'/admin/realms/test/users?*' => Http::response([['id' => self::ID]])]);
        $this->assertNull(app(KeycloakMigrationGateway::class)->create(self::EMAIL));
        Http::assertSentCount(2);
    }

    public function test_provisioning_and_resend_use_exact_subject_and_keycloak_mail(): void
    {
        $users = self::BASE.'/admin/realms/test/users';
        Http::fake([self::BASE.'/realms/test/protocol/openid-connect/token' => Http::response(['access_token' => 'synthetic']),
            $users.'?*' => Http::response([]),
            $users.'/'.self::ID.'/execute-actions-email?*' => Http::response(null, 204),
            $users.'/'.self::ID => Http::response(['id' => self::ID, 'email' => self::EMAIL, 'emailVerified' => false]),
            $users => Http::response(null, 201, ['Location' => $users.'/'.self::ID])]);
        $gateway = app(KeycloakMigrationGateway::class);
        $this->assertSame(self::ID, $gateway->create(self::EMAIL, ['firstName' => 'Ana', 'lastName' => 'Da Silva']));
        $gateway->invite(self::ID, self::EMAIL);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === $users && !isset($r['credentials'])
            && $r['firstName'] === 'Ana' && $r['lastName'] === 'Da Silva');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_starts_with($r->url(), $users.'/'.self::ID.'/execute-actions-email')
            && $r->data() === ['VERIFY_EMAIL', 'UPDATE_PASSWORD', 'CONFIGURE_TOTP']);
    }

    public function test_repair_preserves_existing_names_and_profile_attributes_without_sending_mail(): void
    {
        $user = self::BASE.'/admin/realms/test/users/'.self::ID;
        $profile = ['username' => self::EMAIL, 'email' => self::EMAIL, 'firstName' => 'Preferred',
            'attributes' => ['locale' => ['de']]];
        Http::fake([self::BASE.'/realms/test/protocol/openid-connect/token' => Http::response(['access_token' => 'synthetic']),
            $user => Http::sequence()->push($profile + ['id' => self::ID, 'emailVerified' => true])->pushStatus(204)]);
        app(KeycloakMigrationGateway::class)->refreshNames(self::ID, self::EMAIL, ['firstName' => 'Original', 'lastName' => 'Da Silva']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->url() === $user && $r->data() == $profile + ['lastName' => 'Da Silva']);
        Http::assertSentCount(3);
    }

    public function test_display_name_fallback_does_not_guess_from_an_email(): void
    {
        $this->assertSame(['firstName' => 'Ana', 'lastName' => 'Da Silva'], KeycloakMigrationNames::fromDisplayName('  Ana Da Silva  '));
        $this->assertSame([], KeycloakMigrationNames::fromDisplayName(self::EMAIL));
        $this->assertSame([], KeycloakMigrationNames::fromDisplayName('Ana'));
    }
}
