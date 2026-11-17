<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\KeycloakAccountConsole;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PersonalProfileTest extends TestCase
{
    private const CONSOLE = 'https://auth.clubunio.com/realms/clubunio/account/';
    private const SECURITY = self::CONSOLE.'account-security/signing-in';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'clubunio-domains.central_hosts' => ['localhost'],
            'clubunio-verein-oidc.only' => false, 'clubunio-verein-oidc.enabled' => true,
            'clubunio-platform-oidc.enabled' => true, 'clubunio-platform-oidc.legacy_allowed' => true,
            'clubunio-security.enabled' => false,
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.accounts (id integer PRIMARY KEY, username text, display_name text, active boolean, api_only boolean, preferred_locale text);
CREATE TABLE clubora.tenants (id integer PRIMARY KEY, name text, slug text);
CREATE TABLE clubora.tenant_accounts (account_id integer, tenant_id integer, role text);
CREATE TABLE clubora.platform_admins (account_id integer, active boolean);
INSERT INTO clubora.accounts VALUES
 (1,'platform@example.invalid','Synthetic Platform',1,0,'en'),
 (2,'member@example.invalid','Synthetic Member',1,0,'en');
INSERT INTO clubora.platform_admins VALUES (1,1);
INSERT INTO clubora.tenants VALUES (10,'Synthetic Club','synthetic-club'), (20,'Other Club','other-club');
SQL);
    }

    public function test_member_profile_is_personal_even_without_a_club_role(): void
    {
        $this->get('/portal/profile')->assertRedirect();
        $this->actingAs(Account::findOrFail(2), 'portal')->get('/portal/profile?account=1')->assertOk()
            ->assertSee('Synthetic Member')->assertDontSee('Synthetic Platform')
            ->assertSee('Open security settings')->assertDontSee('Keycloak')
            ->assertSee('href="'.self::SECURITY.'"', false)->assertSee('clubunio-profile.css?v=blue-v2')
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_platform_profile_keeps_existing_access_rules_and_ignores_account_query(): void
    {
        $this->get('/platform/profile')->assertRedirect();
        $this->actingAs(Account::findOrFail(2), 'clubunio')->get('/platform/profile')->assertForbidden();
        $this->actingAs(Account::findOrFail(1), 'clubunio')->get('/platform/profile?account=2')->assertOk()
            ->assertSee('Synthetic Platform')->assertDontSee('Synthetic Member')
            ->assertSee('Open security settings')->assertSee('href="'.self::SECURITY.'"', false);
    }

    public function test_portal_profile_resolves_only_the_actors_club_memberships(): void
    {
        $db = DB::connection('clubunio')->table('clubora.tenant_accounts');
        $db->insert([
            ['account_id' => 2, 'tenant_id' => 10, 'role' => 'office'],
            ['account_id' => 1, 'tenant_id' => 20, 'role' => 'office'],
        ]);

        $this->actingAs(Account::findOrFail(2), 'portal')->get('/portal/profile?club=other-club')->assertOk()
            ->assertSee('Synthetic Club')->assertDontSee('Other Club')
            ->assertSee('cu-profile-section cu-profile-identity')
            ->assertSee('cu-profile-section cu-profile-security')
            ->assertSee('href="'.self::SECURITY.'"', false);
    }

    public function test_invalid_issuer_does_not_create_an_external_link(): void
    {
        $this->assertSame(self::CONSOLE, KeycloakAccountConsole::url());
        config(['clubunio-verein-oidc.issuer' => 'https://auth.clubunio.com.evil.test/realms/clubunio']);
        $this->assertNull(KeycloakAccountConsole::url());
        $this->assertNull(KeycloakAccountConsole::securityUrl());
    }

    public function test_german_profile_uses_clubunio_language_for_security(): void
    {
        $member = Account::findOrFail(2);
        DB::connection('clubunio')->table('clubora.accounts')->where('id', 2)->update(['preferred_locale' => 'de']);

        $this->actingAs($member->refresh(), 'portal')->get('/portal/profile')->assertOk()
            ->assertSee('Kontodaten')->assertSee('Sicherheitseinstellungen öffnen')->assertDontSee('Keycloak');
    }

    public function test_keycloak_only_does_not_offer_the_local_password_endpoint(): void
    {
        config(['clubunio-verein-oidc.only' => true]);
        try {
            app(\App\ClubUnio\Mobile\MobileAccountController::class)->password(request());
            $this->fail('Local password changes should be closed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }
}
