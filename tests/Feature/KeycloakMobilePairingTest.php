<?php

namespace Tests\Feature;

use App\ClubUnio\Http\KeycloakMobilePairingController;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\AccountSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class KeycloakMobilePairingTest extends TestCase
{
    private const ORIGIN = 'https://security.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'cache.default' => 'array',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-mobile.bootstrap.enabled' => false, 'clubunio-security.enabled' => true,
            'clubunio-security.origin' => self::ORIGIN,
            'clubunio-verein-oidc.origin' => self::ORIGIN, 'clubunio-verein-oidc.enabled' => true,
            'clubunio-verein-oidc.invites_enabled' => true, 'clubunio-verein-oidc.mobile_enabled' => true,
            'clubunio-verein-oidc.mobile_mfa_acr' => 'synthetic-mfa']);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.account_security (account_id integer PRIMARY KEY, session_version text, totp_encrypted text);
CREATE TABLE clubora.account_passkeys (account_id integer);
CREATE TABLE clubora.account_security_pairs (id text PRIMARY KEY, proof_hash text, confirmation_hash text,
 account_id integer, expires_at text, consumed_at text, session_stamp text);
CREATE TABLE clubora.keycloak_account_links (issuer text, subject text, account_id integer);
SQL);
    }

    public function test_pairing_requires_exact_origin_fresh_keycloak_mfa_and_original_app_proof(): void
    {
        $controller = app(KeycloakMobilePairingController::class);
        $request = fn ($operation, $payload, $origin = self::ORIGIN) => Request::create($origin.'/api/mobile/v1/session/security/'.$operation,
            'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
        $proof = bin2hex(random_bytes(32));
        $begin = ['proof_hash' => hash('sha256', $proof)];
        $this->denied(404, fn () => $controller->pair($request('begin', $begin, 'https://other.test'), 'begin'));
        config(['clubunio-verein-oidc.mobile_mfa_acr' => null]);
        $this->denied(404, fn () => $controller->pair($request('begin', $begin), 'begin'));
        config(['clubunio-verein-oidc.mobile_mfa_acr' => 'synthetic-mfa']);
        $pair = $controller->pair($request('begin', $begin), 'begin')->getData(true)['data'];
        $payload = ['pair' => $pair['pair'], 'proof' => $proof];
        $this->assertTrue($controller->pair($request('redeem', $payload), 'redeem')->getData(true)['data']['pending']);
        $this->denied(404, fn () => $controller->pair($request('redeem', ['proof' => str_repeat('a', 64)] + $payload), 'redeem'));
        $controller->validPair($request('begin', $pair));
        $this->denied(422, fn () => $controller->validPair($request('begin', ['confirmation' => '00000000'] + $pair)));

        $issuer = config('clubunio-verein-oidc.issuer');
        $db = DB::connection('clubunio');
        $db->table('clubora.keycloak_account_links')->insert(['issuer' => $issuer, 'subject' => 'subject-a', 'account_id' => 42]);
        $actor = (new Account)->forceFill(['id' => 42, 'password' => 'synthetic-hash']);
        $claims = (object) ['iss' => $issuer, 'sub' => 'subject-a', 'acr' => 'synthetic-mfa', 'auth_time' => time() - 301];
        $this->denied(403, fn () => $controller->complete($pair, $actor, $claims));
        $claims->auth_time = time();
        $controller->complete($pair, $actor, $claims);
        $this->denied(403, fn () => $controller->complete($pair, $actor, $claims));
        $this->assertNotEmpty($db->table('clubora.account_security_pairs')->value('session_stamp'));
        $db->table('clubora.account_security_pairs')->update(['expires_at' => now()->subMinute()]);
        $this->denied(404, fn () => $controller->pair($request('redeem', $payload), 'redeem'));
    }

    public function test_existing_local_mfa_is_not_downgraded_to_password_only(): void
    {
        $actor = (new Account)->forceFill(['id' => 42, 'password' => 'synthetic-hash']);
        DB::connection('clubunio')->table('clubora.account_passkeys')->insert(['account_id' => 42]);
        $this->assertTrue(app(AccountSecurity::class)->requiresStrongLogin($actor));
        $this->assertNull(app('router')->getRoutes()->getByName('clubunio.security.show'));
        $this->assertNull(app('router')->getRoutes()->getByName('clubunio.security.recovery'));
    }

    private function denied(int $expected, callable $action): void
    {
        try {
            $action();
            $this->fail('The request must be denied.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($expected, $e->getStatusCode());
        }
    }
}
