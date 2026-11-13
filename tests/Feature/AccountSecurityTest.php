<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TenantSecurityRecovery;
use App\ClubUnio\Http\StrongLoginController;
use App\ClubUnio\Services\AccountSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Hash};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class AccountSecurityTest extends TestCase
{
    private AccountSecurity $security;
    private Account $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'cache.default' => 'array',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-security.enabled' => true, 'clubunio-security.origin' => 'https://security.test']);
        DB::purge('clubunio');
        $this->security = app(AccountSecurity::class);
        $db = $this->security->db();
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.accounts (id integer PRIMARY KEY, username text, password text, active boolean, api_only boolean, created_at text);
CREATE TABLE clubora.account_security (account_id integer PRIMARY KEY, totp_encrypted text, totp_last_step integer,
 session_version text, recovery_hashes text DEFAULT '[]', invitation_hash text, invitation_expires_at text,
 invitation_recovery boolean DEFAULT 0, invitation_email boolean DEFAULT 0, invitation_add_method boolean DEFAULT 0,
 reset_pending boolean DEFAULT 0);
CREATE TABLE clubora.account_passkeys (credential_hash text PRIMARY KEY, account_id integer, rp_id text, public_key text, signature_counter integer);
CREATE TABLE clubora.mobile_sessions (id integer PRIMARY KEY, account_id integer, revoked_at text);
CREATE TABLE clubora.tenants (id integer PRIMARY KEY);
CREATE TABLE clubora.tenant_accounts (id integer PRIMARY KEY, tenant_id integer, account_id integer, role text);
CREATE TABLE clubora.platform_admins (account_id integer, active boolean);
CREATE TABLE clubora.object_changes (tenant_id integer, actor_id integer, object_type text, object_id integer, after_value text);
CREATE TABLE clubora.account_security_pairs (id text PRIMARY KEY, proof_hash text, confirmation_hash text,
 account_id integer, expires_at text, consumed_at text, session_stamp text);
INSERT INTO clubora.mobile_sessions VALUES (1, 1, NULL), (2, 2, NULL);
SQL);
        $db->table('clubora.accounts')->insert(['id' => 1, 'username' => 'test', 'password' => Hash::make('synthetic-password'),
            'active' => true, 'api_only' => false]);
        $this->actor = Account::findOrFail(1);
    }

    public function test_totp_enrollment_replay_and_recovery_only_authorize_new_setup(): void
    {
        $this->assertSame($this->actor->id, $this->security->password('TEST', 'synthetic-password')->id);
        $stamp = $this->security->stamp($this->actor);
        $invitation = $this->security->invitation($this->actor);
        $secret = $this->security->totp()->generateSecretKey();
        $codes = $this->security->enroll($this->actor, $invitation, ['totp_encrypted' => Crypt::encryptString($secret)]);
        $this->assertCount(8, array_unique($codes));
        $this->assertTrue($this->security->configured($this->actor));
        $this->assertNotSame($stamp, $this->security->stamp($this->actor));
        $this->assertNotNull($this->security->db()->table('clubora.mobile_sessions')->where('id', 1)->value('revoked_at'));
        $this->assertNull($this->security->db()->table('clubora.mobile_sessions')->where('id', 2)->value('revoked_at'));
        $this->assertStringNotContainsString($codes[0], $this->security->settings()->value('recovery_hashes'));
        $this->denied(403, fn () => $this->security->checkInvitation($this->actor, $invitation));
        $otp = $this->security->totp()->getCurrentOtp($secret);
        $newStamp = $this->security->verifySecondFactor($this->actor, $otp);
        $this->denied(401, fn () => $this->security->verifySecondFactor($this->actor, $otp));
        $this->denied(401, fn () => $this->security->verifySecondFactor($this->actor, $codes[0]));
        $setupCode = $this->security->recover($this->actor, $codes[0]);
        $this->assertNotSame($newStamp, $this->security->stamp($this->actor));
        $this->security->checkInvitation($this->actor, $setupCode);
        $this->denied(401, fn () => $this->security->recover($this->actor, $codes[0]));
    }

    public function test_email_recovery_cannot_survive_privilege_grant_in_another_club(): void
    {
        $memberships = $this->security->db()->table('clubora.tenant_accounts');
        $memberships->insert(['account_id' => 1, 'role' => 'member']);
        $this->assertTrue($this->security->emailRecoveryAllowed($this->actor));
        $code = $this->security->invitation($this->actor, recovery: true, email: true);
        $this->security->checkInvitation($this->actor, $code);
        $memberships->insert(['account_id' => 1, 'role' => 'office']);
        $this->assertFalse($this->security->emailRecoveryAllowed($this->actor));
        $this->denied(403, fn () => $this->security->checkInvitation($this->actor, $code));
        $memberships->where('role', 'office')->delete();
        $this->security->db()->table('clubora.platform_admins')->insert(['account_id' => 1, 'active' => true]);
        $this->denied(403, fn () => $this->security->checkInvitation($this->actor, $code));
    }

    public function test_only_the_exact_secure_origin_and_non_embedded_assertions_are_accepted(): void
    {
        $origin = 'https://security.test';
        $this->assertSame($origin, $this->security->origin(Request::create($origin.'/account/security')));
        $this->denied(404, fn () => $this->security->origin(Request::create('http://security.test/account/security')));
        $this->denied(404, fn () => $this->security->origin(Request::create('https://other.security.test/account/security')));
        $encode = fn ($data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $this->denied(422, fn () => $this->security->clientData($encode(['origin' => $origin, 'crossOrigin' => true]), $origin));
        $this->denied(422, fn () => $this->security->clientData($encode(['origin' => 'https://other.security.test']), $origin));
        $this->assertSame(json_encode(['origin' => $origin]), $this->security->clientData($encode(['origin' => $origin]), $origin));
        config(['clubunio-security.enabled' => false]);
        $this->denied(404, fn () => $this->security->origin(Request::create($origin.'/account/security')));
    }

    public function test_browser_setup_screen_and_discoverable_passkey_options(): void
    {
        config(['app.url' => 'https://security.test', 'session.driver' => 'array']);
        $this->get('https://security.test/account/security')->assertOk()
            ->assertSee('data-enrollment', false)->assertSee('method="post"', false);
        $options = $this->postJson('https://security.test/account/security/passkey-options', ['mode' => 'portal'])
            ->assertOk()->assertJsonPath('rpId', 'security.test')->assertJsonPath('userVerification', 'required')->json();
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/D', $options['challenge']);
        $this->postJson('https://security.test/account/security/passkey', [
            'mode' => 'clubunio', 'id' => 'AQ', 'client' => 'AQ', 'authenticator' => 'AQ', 'signature' => 'AQ',
        ])->assertStatus(422);
        $this->postJson('https://security.test/account/security/passkey', [
            'mode' => 'portal', 'id' => 'AQ', 'client' => 'AQ', 'authenticator' => 'AQ', 'signature' => 'AQ',
        ])->assertStatus(422);
    }

    public function test_passkey_assertion_verifies_signature_and_updates_counter(): void
    {
        $origin = 'https://security.test';
        $windowsConfig = PHP_BINARY ? dirname(PHP_BINARY).'/extras/ssl/openssl.cnf' : '';
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            + (is_file($windowsConfig) ? ['config' => $windowsConfig] : []));
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        $credential = random_bytes(32);
        $this->security->enroll($this->actor, $this->security->invitation($this->actor), [], [
            'credential_hash' => hash('sha256', $credential), 'rp_id' => 'security.test',
            'public_key' => openssl_pkey_get_details($privateKey)['key'], 'signature_counter' => 0,
        ]);
        $encode = fn ($bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $challenge = random_bytes(32);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $encode($challenge), 'origin' => $origin]);
        $authenticator = hash('sha256', 'security.test', true).chr(5).pack('N', 1);
        $this->assertTrue(openssl_sign($authenticator.hash('sha256', $client, true), $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $data = ['id' => $encode($credential), 'client' => $encode($client),
            'authenticator' => $encode($authenticator), 'signature' => $encode($signature)];
        [$actor, $stamp] = $this->security->passkeyLogin($origin, $challenge, $data);
        $this->assertSame($this->actor->id, $actor->id);
        $this->assertSame($this->security->stamp($actor), $stamp);
        $this->denied(401, fn () => $this->security->passkeyLogin($origin, $challenge, $data));
        $this->denied(409, fn () => $this->security->invitation($actor));
        $addMethod = $this->security->invitation($actor, existingStamp: $stamp);
        $codes = $this->security->enroll($actor, $addMethod, ['totp_encrypted' => Crypt::encryptString($this->security->totp()->generateSecretKey())]);
        $this->assertCount(8, $codes);
        $this->assertSame(1, $this->security->db()->table('clubora.account_passkeys')->count());
    }

    public function test_pairing_requires_the_original_app_proof_and_expires(): void
    {
        config(['clubunio-mobile.bootstrap.enabled' => false]);
        $controller = app(StrongLoginController::class);
        $request = fn ($operation, $payload) => Request::create('https://security.test/api/mobile/v1/session/security/'.$operation,
            'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
        $proof = bin2hex(random_bytes(32));
        $data = $controller->pair($request('begin', ['proof_hash' => hash('sha256', $proof)]), 'begin')->getData(true)['data'];
        $payload = ['pair' => $data['pair'], 'proof' => $proof];
        $this->assertTrue($controller->pair($request('redeem', $payload), 'redeem')->getData(true)['data']['pending']);
        $this->denied(404, fn () => $controller->pair($request('redeem', ['proof' => str_repeat('a', 64)] + $payload), 'redeem'));
        $this->security->db()->table('clubora.account_security_pairs')->where('id', $data['pair'])->update(['expires_at' => now()->subMinute()]);
        $this->denied(404, fn () => $controller->pair($request('redeem', $payload), 'redeem'));
    }

    public function test_new_accounts_can_enter_the_wizard_but_cannot_bypass_it(): void
    {
        config(['clubunio-security.enroll_since' => now()->subDay()->toIso8601String(), 'session.driver' => 'array',
            'app.url' => 'https://security.test']);
        $this->assertFalse($this->security->mustEnroll($this->actor));
        $this->security->db()->table('clubora.accounts')->where('id', $this->actor->id)->update(['created_at' => now()]);
        $this->assertTrue($this->security->mustEnroll($this->actor->fresh()));
        $credentials = ['mode' => 'portal', 'username' => 'test', 'password' => 'synthetic-password'];
        $this->postJson('https://security.test/account/security/password', $credentials)->assertUnauthorized();
        $this->postJson('https://security.test/account/security/setup-start', $credentials)->assertOk()->assertJsonStructure(['setup_code']);
        $this->assertGuest('portal');
    }

    public function test_office_reset_is_tenant_scoped_audited_and_revokes_the_old_factor(): void
    {
        $db = $this->security->db();
        $db->table('clubora.tenants')->insert([['id' => 10], ['id' => 20]]);
        $db->table('clubora.accounts')->insert(['id' => 2, 'username' => 'office', 'password' => Hash::make('synthetic-operator'),
            'active' => true, 'api_only' => false]);
        $db->table('clubora.tenant_accounts')->insert([
            ['id' => 11, 'tenant_id' => 10, 'account_id' => 1, 'role' => 'member'],
            ['id' => 12, 'tenant_id' => 10, 'account_id' => 2, 'role' => 'office'],
            ['id' => 21, 'tenant_id' => 20, 'account_id' => 1, 'role' => 'member'],
        ]);
        $factor = $this->security->totp()->generateSecretKey();
        $this->security->enroll($this->actor, $this->security->invitation($this->actor), ['totp_encrypted' => Crypt::encryptString($factor)]);
        $operator = Account::findOrFail(2);
        $this->security->enroll($operator, $this->security->invitation($operator),
            ['totp_encrypted' => Crypt::encryptString($this->security->totp()->generateSecretKey())]);
        $tenant = Tenant::findOrFail(10);
        $req = Request::create('https://security.test/account/recovery/10/11', 'POST');
        $req->setLaravelSession(app('session')->driver('array'));
        $req->session()->put(['clubunio.security.clubunio' => $this->security->stamp($operator), 'clubunio.security.verified_at.clubunio' => time()]);
        $input = ['operator_password' => 'synthetic-operator', 'reason' => 'Independent synthetic verification', 'identity_verified' => true];
        $service = app(TenantSecurityRecovery::class);
        $this->denied(403, fn () => $service->reset($req, $operator, $tenant, 11, $input));
        $db->table('clubora.tenant_accounts')->where('id', 21)->delete();
        $stamp = $this->security->stamp($this->actor);
        $code = $service->reset($req, $operator, $tenant, 11, $input);
        $this->assertNotSame($stamp, $this->security->stamp($this->actor));
        $this->assertTrue($this->security->resetPending($this->actor));
        $this->denied(401, fn () => $this->security->verifySecondFactor($this->actor, $this->security->totp()->getCurrentOtp($factor)));
        $this->assertStringNotContainsString($code, $db->table('clubora.object_changes')->value('after_value'));
        $this->security->enroll($this->actor, $code, ['totp_encrypted' => Crypt::encryptString($this->security->totp()->generateSecretKey())]);
        $this->assertFalse($this->security->resetPending($this->actor));
    }

    private function denied(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('The request must fail.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
