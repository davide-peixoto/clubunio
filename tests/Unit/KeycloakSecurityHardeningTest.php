<?php

namespace Tests\Unit;

use App\ClubUnio\Http\{KeycloakBackchannelController, PlatformOidcController, RequirePlatformAdministrator, RequireStrongWebSession};
use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\{KeycloakBackchannelLogout, KeycloakWebAssurance};
use App\ClubUnio\Services\{AccountSecurity, KeycloakMobileAssurance, KeycloakLogoutState};
use App\ClubUnio\Http\KeycloakMobilePairingController;
use App\ClubUnio\Mobile\MobileSessions;
use Firebase\JWT\JWT;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\{ArraySessionHandler, Store};
use Illuminate\Support\Facades\{Facade, Http};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Isolated in-memory databases; no app bootstrap, .env, migrations or provider traffic. */
final class KeycloakSecurityHardeningTest extends TestCase
{
    private Application $application;
    private $database;
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private const ISSUER = 'https://identity.invalid/realms/test';

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->application = new Application(dirname(__DIR__, 2));
        $this->application->instance('env', 'testing');
        $this->application->instance('request', Request::create('https://club.invalid'));
        $this->application->instance('translator', new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'));
        $this->application->instance('view', $this->createMock(\Illuminate\Contracts\View\Factory::class));
        $this->application->instance('config', new Repository([
            'app' => ['key' => 'synthetic-test-key'],
            'clubunio-security' => ['enabled' => false],
            'clubunio-verein-oidc' => ['enabled' => true, 'only' => true, 'origin' => 'https://club.invalid',
                'issuer' => self::ISSUER, 'client' => 'test-client', 'secret' => 'synthetic', 'subjects' => ['subject' => 1],
                'mobile_enabled' => false, 'invites_enabled' => false, 'mobile_mfa_acr' => 'test-mfa'],
            'clubunio-platform-oidc' => ['enabled' => true, 'legacy_allowed' => false, 'origin' => 'https://club.invalid',
                'issuer' => self::ISSUER, 'client' => 'platform-client', 'secret' => 'synthetic', 'subjects' => ['subject' => 1]],
            'clubunio-keycloak-backchannel' => ['enabled' => true, 'origin' => 'https://club.invalid', 'cache_store' => 'security', 'clients' => []],
            'cache' => ['default' => 'security', 'stores' => ['security' => ['driver' => 'database', 'connection' => 'clubunio',
                'table' => 'cache', 'lock_connection' => 'clubunio', 'lock_table' => 'cache_locks']], 'prefix' => 'test'],
        ]));
        $capsule = new Manager($this->application);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'clubunio');
        $capsule->bootEloquent();
        $this->application->instance('db', $capsule->getDatabaseManager());
        $this->application->instance('cache', new CacheManager($this->application));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->application);
        $this->database = $capsule->getConnection('clubunio');
        $this->database->statement("ATTACH DATABASE ':memory:' AS clubora");
        $this->database->unprepared('CREATE TABLE clubora.accounts(id INTEGER PRIMARY KEY,active INTEGER,api_only INTEGER,password TEXT);
            CREATE TABLE clubora.account_security(account_id INTEGER,session_version TEXT);
            CREATE TABLE clubora.keycloak_account_links(issuer TEXT,subject TEXT,account_id INTEGER);
            CREATE TABLE clubora.keycloak_logout_watermarks(account_id INTEGER PRIMARY KEY,issuer TEXT,subject TEXT,cutoff INTEGER);
            CREATE TABLE clubora.mobile_sessions(id TEXT,account_id INTEGER,created_at TEXT,revoked_at TEXT,token_hash TEXT,security_stamp TEXT,expires_at TEXT);
            CREATE TABLE clubora.account_security_pairs(id TEXT,proof_hash TEXT,confirmation_hash TEXT,account_id INTEGER,session_stamp TEXT,expires_at TEXT,consumed_at TEXT);
            CREATE TABLE cache(key TEXT PRIMARY KEY,value TEXT,expiration INTEGER);
            CREATE TABLE cache_locks(key TEXT PRIMARY KEY,owner TEXT,expiration INTEGER);');
        $this->database->table('clubora.accounts')->insert(['id' => 1, 'active' => 1, 'api_only' => 0, 'password' => 'synthetic-hash']);
        $this->database->table('clubora.keycloak_account_links')->insert(['issuer' => self::ISSUER, 'subject' => 'subject', 'account_id' => 1]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        parent::tearDown();
    }

    private function rejects(callable $operation, int $status): void
    {
        try { $operation(); $this->fail('Expected request rejection'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function claims(array $changes = []): \stdClass
    {
        return (object) array_replace(['iss' => self::ISSUER, 'aud' => 'test-client', 'sub' => 'subject',
            'iat' => time(), 'exp' => time() + 120, 'jti' => 'unique-test-token',
            'events' => (object) [KeycloakBackchannelLogout::EVENT => (object) []]], $changes);
    }

    private function start(bool $platform): Request
    {
        $request = Request::create('https://club.invalid/'.($platform ? 'platform' : 'vereinslogin').'/oidc/start');
        $route = (new Route('GET', '/', fn () => null))->name($platform ? 'clubunio.platform.oidc.start' : 'clubunio.verein.oidc.start');
        $request->setRouteResolver(fn () => $route);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(120)));
        return $request;
    }

    public function test_web_and_platform_mfa_ignore_disabled_mobile_and_invitation_features(): void
    {
        $this->assertSame('test-mfa', KeycloakWebAssurance::requiredLevel());
        $this->assertSame('test-mfa', KeycloakWebAssurance::requiredLevel(true));
        config(['clubunio-verein-oidc.web_mfa_acr' => 'web-mfa', 'clubunio-platform-oidc.mfa_acr' => 'platform-mfa']);
        foreach ([false => 'web-mfa', true => 'platform-mfa'] as $platform => $level) {
            $response = (new PlatformOidcController)->start($this->start((bool) $platform));
            parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
            $this->assertSame('0', $query['max_age']);
            $this->assertSame([$level], json_decode($query['claims'], true)['id_token']['acr']['values']);
            $this->assertSame('S256', $query['code_challenge_method']);
        }
    }

    public function test_missing_or_invalid_mfa_configuration_fails_closed(): void
    {
        foreach ([null, '', '1', 'bad value'] as $level) {
            config(['clubunio-verein-oidc.mobile_mfa_acr' => $level]);
            foreach ([false, true] as $platform) {
                $this->rejects(fn () => (new PlatformOidcController)->start($this->start($platform)), 503);
            }
        }
        Http::assertNothingSent();
    }

    public function test_session_proof_requires_fresh_actual_mfa_and_current_account_stamp(): void
    {
        $actor = Account::findOrFail(1);
        $this->assertFalse(KeycloakWebAssurance::acceptsSession($actor, null));
        $this->rejects(fn () => KeycloakWebAssurance::proof($actor, (object) ['acr' => '1', 'auth_time' => time()]), 403);
        $proof = KeycloakWebAssurance::proof($actor, (object) ['acr' => 'test-mfa', 'auth_time' => time(), 'iat' => time()]);
        $this->assertTrue(KeycloakWebAssurance::acceptsSession($actor, $proof));
        $this->database->table('clubora.account_security')->insert(['account_id' => 1, 'session_version' => 'revoked']);
        $this->assertFalse(KeycloakWebAssurance::acceptsSession($actor, $proof));
        $this->assertFalse(KeycloakWebAssurance::acceptsSession($actor, array_replace($proof, ['auth_time' => time() - 301])));
    }

    public function test_legacy_platform_session_cannot_use_either_middleware_without_mfa_proof(): void
    {
        $request = Request::create('https://club.invalid/platform', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $route = (new Route('POST', '/platform', fn () => null))->name('clubunio.platform.index');
        $request->setRouteResolver(fn () => $route);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(120)));
        $request->session()->put('platform.oidc.expires', time() + 60);
        $actor = Account::findOrFail(1);
        $request->setUserResolver(fn () => $actor);
        $guard = new class { public function logout(): void {} };
        $this->application->instance('auth', new class($guard) { public function __construct(private $guard) {} public function guard($name) { return $this->guard; } });
        foreach ([new RequireStrongWebSession, new RequirePlatformAdministrator] as $middleware) {
            $request->session()->put('platform.oidc.expires', time() + 60);
            // Avoid any actual tenant queries in platform middleware.
            config(['clubunio-domains.central_hosts' => ['club.invalid'], 'app.url' => 'https://club.invalid']);
            $this->rejects(fn () => $middleware->handle($request, function () { $this->fail('Proofless session reached handler'); }), 401);
        }
    }

    public function test_logout_claim_validation_rejects_cross_jwt_and_wrong_subject_scope(): void
    {
        $valid = $this->claims();
        $this->assertTrue(KeycloakBackchannelLogout::validClaims($valid, self::ISSUER, ['test-client'], time()));
        foreach ([['iss' => 'https://evil.invalid'], ['aud' => 'other-client'], ['aud' => [42]], ['iat' => time() - 301],
            ['iat' => (string) time()], ['iat' => time() + 11], ['exp' => null], ['exp' => (string) (time() + 60)],
            ['exp' => time() - 1], ['sub' => null, 'sid' => 'sid-only-not-supported'], ['jti' => ''], ['nonce' => null],
            ['events' => (object) []], ['events' => (object) [KeycloakBackchannelLogout::EVENT => []]]] as $changes) {
            $this->assertFalse(KeycloakBackchannelLogout::validClaims($this->claims($changes), self::ISSUER, ['test-client'], time()), json_encode($changes));
        }
        $missingExpiry = $this->claims();
        unset($missingExpiry->exp);
        $this->assertFalse(KeycloakBackchannelLogout::validClaims($missingExpiry, self::ISSUER, ['test-client'], time()));
    }

    private function signedToken(\stdClass $claims): string
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        if (PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/apache/conf/openssl.cnf')) $options['config'] = 'C:/xampp/apache/conf/openssl.cnf';
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        Http::fake([self::ISSUER.'/protocol/openid-connect/certs' => Http::response(['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'synthetic-key', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e']),
        ]]])]);
        return JWT::encode((array) $claims, $key, 'RS256', 'synthetic-key', ['typ' => 'logout+jwt']);
    }

    public function test_real_rsa_signature_and_logout_claims_are_required(): void
    {
        $service = new KeycloakBackchannelLogout;
        $token = $this->signedToken($this->claims());
        $this->assertSame('subject', $service->verify($token)->sub);
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode(json_encode((array) $this->claims(['sub' => 'attacker']))), '+/', '-_'), '=');
        $this->rejects(fn () => $service->verify(implode('.', $parts)), 400);
        $this->rejects(fn () => $service->verify($this->signedToken($this->claims(['nonce' => 'id-token']))), 400);
        $this->rejects(fn () => $service->verify(JWT::encode((array) $this->claims(), str_repeat('synthetic', 8), 'HS256', 'synthetic-key')), 400);
        $this->assertSame(0, $this->database->table('clubora.keycloak_logout_watermarks')->count());
    }

    public function test_subject_logout_is_isolated_idempotent_and_preserves_newer_logins(): void
    {
        $claims = $this->claims();
        $date = fn ($seconds) => \Illuminate\Support\Carbon::createFromTimestampUTC($seconds)->toIso8601String();
        foreach ([['old', 1, $date($claims->iat - 60)], ['same-second', 1, substr($date($claims->iat), 0, 19).'.500000+00:00'],
            ['new', 1, $date($claims->iat + 1)], ['foreign', 2, $date($claims->iat - 60)]] as [$id, $account, $created]) {
            $this->database->table('clubora.mobile_sessions')->insert(['id' => $id, 'account_id' => $account, 'created_at' => $created, 'revoked_at' => null]);
        }
        $service = new KeycloakBackchannelLogout;
        $service->revoke($claims);
        $this->assertSame(['old', 'same-second'], $this->database->table('clubora.mobile_sessions')->whereNotNull('revoked_at')->orderBy('id')->pluck('id')->all());
        $this->database->table('clubora.mobile_sessions')->insert(['id' => 'later-login', 'account_id' => 1, 'created_at' => $date($claims->iat + 10), 'revoked_at' => null]);
        $service->revoke($claims);
        $this->assertSame(2, $this->database->table('clubora.mobile_sessions')->whereNotNull('revoked_at')->count());
        $this->database->table('clubora.keycloak_account_links')->delete();
        $this->database->table('clubora.mobile_sessions')->insert(['id' => 'mapped-old', 'account_id' => 1, 'created_at' => $date($claims->iat - 10), 'revoked_at' => null]);
        $service->revoke($this->claims(['jti' => 'mapped-only-event']));
        $this->assertNotNull($this->database->table('clubora.mobile_sessions')->where('id', 'mapped-old')->value('revoked_at'));
    }

    public function test_unknown_subject_and_unshared_cache_fail_safely(): void
    {
        $service = new KeycloakBackchannelLogout;
        $service->revoke($this->claims(['sub' => 'unknown-subject']));
        $this->assertSame(0, $this->database->table('clubora.mobile_sessions')->count());
        config(['cache.stores.security.driver' => 'array']);
        $this->rejects(fn () => $service->revoke($this->claims()), 503);
    }

    private function pairing(string $id): array
    {
        $confirmation = '12345678';
        $this->database->table('clubora.account_security_pairs')->insert(['id' => $id, 'proof_hash' => hash('sha256', str_repeat('b', 64)),
            'confirmation_hash' => hash_hmac('sha256', $id.'|'.$confirmation, 'synthetic-test-key'),
            'account_id' => null, 'session_stamp' => null, 'expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s'), 'consumed_at' => null]);
        return ['pair' => $id, 'confirmation' => $confirmation, 'native' => false];
    }

    public function test_delivered_logout_blocks_completed_pairs_old_tokens_and_late_old_claims(): void
    {
        config(['clubunio-verein-oidc.invites_enabled' => true, 'clubunio-verein-oidc.mobile_enabled' => true,
            'clubunio-security.origin' => 'https://club.invalid']);
        $actor = Account::findOrFail(1);
        $claims = $this->claims(['iat' => time() - 20, 'auth_time' => time() - 20, 'acr' => 'test-mfa']);
        $pair = $this->pairing('11111111-1111-4111-8111-111111111111');
        $controller = new KeycloakMobilePairingController(new AccountSecurity);
        $controller->complete($pair, $actor, $claims, 'https://club.invalid');
        $oldStamp = $this->database->table('clubora.account_security_pairs')->where('id', $pair['pair'])->value('session_stamp');
        $secret = str_repeat('a', 64);
        $this->database->table('clubora.mobile_sessions')->insert(['id' => $pair['pair'], 'account_id' => 1,
            'created_at' => now()->subMinute()->toIso8601String(), 'revoked_at' => null, 'expires_at' => now()->addDay()->toIso8601String(),
            'token_hash' => hash('sha256', $secret), 'security_stamp' => $oldStamp]);
        $request = Request::create('https://club.invalid/api/mobile/session', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$pair['pair'].'.'.$secret]);
        $this->assertSame(1, (new MobileSessions)->authenticate($request)->id);
        $proof = KeycloakWebAssurance::proof($actor, $claims);
        (new KeycloakBackchannelLogout)->revoke($this->claims(['iat' => time() - 10]));
        $this->assertNotSame($oldStamp, app(KeycloakMobileAssurance::class)->stamp($actor));
        $this->assertFalse(KeycloakWebAssurance::acceptsSession($actor, $proof));
        $this->rejects(fn () => (new MobileSessions)->authenticate($request), 401);
        // A completed pair's old stamp also fails the exact check used by redeem.
        $this->assertFalse(hash_equals(app(KeycloakMobileAssurance::class)->stamp($actor), $oldStamp));
        $latePair = $this->pairing('22222222-2222-4222-8222-222222222222');
        $this->rejects(fn () => $controller->complete($latePair, $actor, $claims, 'https://club.invalid'), 403);
        $this->rejects(fn () => KeycloakWebAssurance::proof($actor, $claims), 403);
        $freshClaims = $this->claims(['auth_time' => time(), 'acr' => 'test-mfa']);
        $controller->complete($latePair, $actor, $freshClaims, 'https://club.invalid');
        $this->assertSame(1, $this->database->table('clubora.account_security_pairs')->where('id', $latePair['pair'])->value('account_id'));
        $this->assertTrue(KeycloakWebAssurance::acceptsSession($actor, KeycloakWebAssurance::proof($actor, $freshClaims)));
    }

    public function test_revocation_floor_is_monotone_and_survives_replay_cache_eviction(): void
    {
        $service = new KeycloakBackchannelLogout;
        $new = $this->claims(['iat' => time() - 10]);
        $service->revoke($new);
        $actor = Account::findOrFail(1);
        $stamp = app(AccountSecurity::class)->stamp($actor);
        $service->revoke($this->claims(['iat' => time() - 20, 'jti' => 'older-event']));
        $this->assertSame($new->iat, KeycloakLogoutState::cutoff($actor));
        $this->assertSame($stamp, app(AccountSecurity::class)->stamp($actor));
        $this->application['cache']->store('security')->flush();
        $service->revoke($new);
        $this->assertSame($new->iat, KeycloakLogoutState::cutoff($actor));
        $this->assertFalse(KeycloakLogoutState::accepts($actor, $this->claims(['iat' => $new->iat, 'auth_time' => $new->iat])));
        $this->assertTrue(KeycloakLogoutState::accepts($actor, $this->claims(['auth_time' => time()])));
        config(['clubunio-keycloak-backchannel.enabled' => false, 'clubunio-keycloak-backchannel.state_enforced' => true]);
        $this->assertSame($new->iat, KeycloakLogoutState::cutoff($actor));
        $this->assertSame($stamp, app(AccountSecurity::class)->stamp($actor));
    }

    public function test_signed_form_delivery_executes_without_browser_session_and_handles_duplicates(): void
    {
        KeycloakBackchannelController::routes();
        $this->application['router']->getRoutes()->refreshNameLookups();
        $route = $this->application['router']->getRoutes()->getByName('clubunio.keycloak.backchannel-logout');
        $this->assertNotNull($route);
        $this->assertNotContains('web', $route->gatherMiddleware());
        $token = $this->signedToken($this->claims());
        $request = Request::create('https://club.invalid/auth/keycloak/backchannel-logout', 'POST', ['logout_token' => $token], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query(['logout_token' => $token]));
        $this->assertFalse($request->hasSession());
        foreach ([1, 2] as $attempt) {
            $response = (new KeycloakBackchannelController)($request, new KeycloakBackchannelLogout);
            $this->assertSame(200, $response->getStatusCode());
        }
        $this->assertSame(1, $this->database->table('clubora.keycloak_logout_watermarks')->count());
    }

    public function test_platform_mapping_without_verein_link_is_supported_and_conflicts_fail_closed(): void
    {
        $this->database->table('clubora.keycloak_account_links')->delete();
        config(['clubunio-verein-oidc.subjects' => [], 'clubunio-platform-oidc.subjects' => ['subject' => 1]]);
        $claims = $this->claims(['aud' => 'platform-client']);
        $service = new KeycloakBackchannelLogout;
        $this->assertSame('subject', $service->verify($this->signedToken($claims))->sub);
        $service->revoke($claims);
        $this->assertSame(1, $this->database->table('clubora.keycloak_logout_watermarks')->value('account_id'));
        config(['clubunio-platform-oidc.migrated_subjects' => ['subject' => 2]]);
        $this->rejects(fn () => $service->revoke($this->claims(['jti' => 'conflicting-map'])), 503);
        $this->assertSame(1, $this->database->table('clubora.keycloak_logout_watermarks')->count());
    }

    public function test_first_invitation_link_requires_a_second_fresh_login_before_authenticating(): void
    {
        config(['clubunio-verein-oidc.subjects' => [], 'clubunio-platform-oidc.subjects' => [],
            'clubunio-verein-oidc.invites_enabled' => true, 'clubunio-parent-access.enabled' => false]);
        $this->database->table('clubora.keycloak_account_links')->delete();
        $this->database->unprepared('CREATE TABLE clubora.tenants(id INTEGER PRIMARY KEY,slug TEXT);
            INSERT INTO clubora.tenants VALUES(1,\'test-club\');
            CREATE TABLE clubora.tenant_accounts(tenant_id INTEGER,account_id INTEGER,role TEXT);
            INSERT INTO clubora.tenant_accounts VALUES(1,1,\'admin\');
            CREATE TABLE clubora.keycloak_account_invitations(token_hash TEXT,tenant_id INTEGER,account_id INTEGER,
                issuer TEXT,email TEXT,expires_at TEXT,accepted_at TEXT,first_name TEXT,last_name TEXT);');
        $this->database->table('clubora.keycloak_account_invitations')->insert(['token_hash' => str_repeat('a', 64),
            'tenant_id' => 1, 'account_id' => 1, 'issuer' => self::ISSUER, 'email' => 'synthetic@example.invalid',
            'expires_at' => now()->addHour()->toDateTimeString(), 'accepted_at' => null]);
        $tenant = \App\ClubUnio\Models\Tenant::findOrFail(1);
        $this->application->instance(\App\ClubUnio\Services\BackofficeReadContext::class, new class($tenant) {
            public function __construct(private $tenant) {}
            public function tenantsFor($actor) { return collect([$this->tenant]); }
        });
        $provider = new class {
            public \stdClass $claims;
            public function verify(...$args) { return $this->claims; }
        };
        $provider->claims = $this->claims(['sub' => 'first-subject', 'iat' => time() - 20, 'auth_time' => time() - 20,
            'acr' => 'test-mfa', 'email_verified' => true, 'email' => 'synthetic@example.invalid']);
        $this->application->instance(\App\ClubUnio\Services\KeycloakCodeExchange::class, $provider);
        $guard = new class { public int $logins = 0; public function login($actor) { $this->logins++; } };
        $this->application->instance('auth', new class($guard) {
            public function __construct(private $guard) {} public function guard($name) { return $this->guard; }
        });
        // The logout precedes first linking; there is deliberately no local account mapping yet.
        (new KeycloakBackchannelLogout)->revoke($this->claims(['sub' => 'first-subject', 'iat' => time() - 10]));
        $this->assertSame(0, $this->database->table('clubora.keycloak_logout_watermarks')->count());
        $session = new Store('test', new ArraySessionHandler(120));
        $pending = ['state' => 'first-state', 'expires' => time() + 60, 'verifier' => 'synthetic', 'nonce' => 'synthetic',
            'client' => 'test-client', 'callback' => 'https://club.invalid/vereinslogin/oidc/callback',
            'invitation' => str_repeat('a', 64), 'pair' => null];
        $session->put('clubunio.oidc.pending', $pending);
        $request = Request::create('https://club.invalid/vereinslogin/oidc/callback?state=first-state&code=first-code');
        $request->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name('clubunio.verein.oidc.callback'));
        $request->setLaravelSession($session);
        $controller = new PlatformOidcController;
        $response = $controller->callback($request, new \App\ClubUnio\Services\PlatformAdministrationService);
        $this->assertSame(0, $guard->logins);
        $this->assertFalse($session->has('clubunio.oidc.assurance'));
        $this->assertSame(1, $this->database->table('clubora.keycloak_account_links')->count());
        parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('0', $query['max_age']);
        $this->assertNotSame('first-state', $query['state']);
        $this->assertNull($session->get('clubunio.oidc.pending.invitation'));
        $provider->claims->iat = time();
        $provider->claims->auth_time = time();
        $second = Request::create('https://club.invalid/vereinslogin/oidc/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fresh-code']));
        $second->setRouteResolver($request->getRouteResolver());
        $second->setLaravelSession($session);
        $controller->callback($second, new \App\ClubUnio\Services\PlatformAdministrationService);
        $this->assertSame(1, $guard->logins);
        $this->assertTrue(KeycloakWebAssurance::acceptsSession(Account::findOrFail(1), $session->get('clubunio.oidc.assurance')));
        Http::assertNothingSent();
    }

    public function test_backchannel_transport_rejects_unapproved_hosts_and_json_before_fetching_keys(): void
    {
        $controller = new KeycloakBackchannelController;
        $this->rejects(fn () => $controller(Request::create('https://evil.invalid/auth/keycloak/backchannel-logout', 'POST'), new KeycloakBackchannelLogout), 404);
        $this->rejects(fn () => $controller(Request::create('https://club.invalid/auth/keycloak/backchannel-logout', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}'), new KeycloakBackchannelLogout), 400);
        $this->rejects(fn () => (new KeycloakBackchannelLogout)->verify(str_repeat('x', 17000)), 400);
        Http::assertNothingSent();
    }
}
