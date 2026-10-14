<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioOidcClient;
use App\ClubUnio\Services\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BexioConnectionTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private BexioConnectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt-in rolled-back DB tests');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'oidc-'.bin2hex(random_bytes(6)), 'name' => 'OIDC fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => bin2hex(random_bytes(8)), 'display_name' => 'OIDC admin', 'active' => true, 'password' => bcrypt('test')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        foreach (['BEXIO_CLIENT_ID' => 'test-client', 'BEXIO_CLIENT_SECRET' => 'test-secret', 'BEXIO_AUTHORIZE_SCOPES' => 'kb_invoice_show', 'BEXIO_REFRESH_TOKEN' => 'NEVER-ROTATE-LEGACY'] as $key => $value) {
            $row = new Setting;
            $row->forceFill(['tenant_id' => $this->tenant->id, 'area' => 'bexio', 'key' => $key, 'label' => $key, 'value_type' => 'string', 'enabled' => false, 'is_secret' => true, 'encrypted_value' => Crypt::encryptString($value)])->save();
        }
        $this->service = app(BexioConnectionService::class);
        $this->actingAs($this->actor, 'clubunio');
        Http::preventStrayRequests();
        Http::fake([]);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function base(): string
    {
        return '/clubora-bexio/'.$this->tenant->slug;
    }

    private function begin(): array
    {
        $response = $this->post($this->base().'/connect')->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith(BexioOidcClient::PROVIDER.'/protocol/openid-connect/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString('offline_access', $query['scope']);
        $this->assertStringContainsString('openid', $query['scope']);
        $this->assertStringNotContainsString('test-secret', $url);

        return $query;
    }

    private function fakeExchange(array $query, array $overrides = []): void
    {
        $key = RSA::createKey(2048)->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256');
        $encode = fn ($v) => rtrim(strtr(base64_encode(is_array($v) ? json_encode($v) : $v), '+/', '-_'), '=');
        $claims = array_replace(['iss' => BexioOidcClient::PROVIDER, 'sub' => 'fixture-user', 'aud' => 'test-client',
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $query['nonce']], $overrides);
        $body = $encode(['alg' => 'RS256', 'kid' => 'test-key']).'.'.$encode($claims);
        $jwt = $body.'.'.$encode($key->sign($body));
        $jwks = json_decode($key->getPublicKey()->toString('JWK'), true);
        $jwks['keys'][0]['kid'] = 'test-key';
        Http::fake([
            BexioOidcClient::TOKEN_ENDPOINT => Http::response(['id_token' => $jwt, 'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            BexioOidcClient::PROVIDER.'/protocol/openid-connect/certs' => Http::response($jwks),
        ]);
    }

    public function test_central_callback_uses_session_tenant_and_is_single_use(): void
    {
        config(['app.url' => 'https://dev.clubunio.ch']);
        $this->get('/integrations/bexio')->assertOk();
        $response = $this->post('/integrations/bexio/connect', ['tenant' => $this->tenant->slug])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://dev.clubunio.ch/integrations/bexio/callback', $query['redirect_uri']);
        $this->fakeExchange($query);
        $url = '/integrations/bexio/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code', 'tenant' => 'another-tenant']);
        $this->get($url)->assertSessionHas('notice');
        $this->assertSame('new-refresh', $this->service->connection($this->tenant)['refresh_token']);
        $this->get($url)->assertStatus(400);
        Http::assertSentCount(2);
    }

    public function test_central_routes_reject_foreign_tenant_and_revoked_admin(): void
    {
        $other = Tenant::create(['slug' => 'other-'.bin2hex(random_bytes(6)), 'name' => 'Other']);
        $this->post('/integrations/bexio/connect', ['tenant' => $other->slug])->assertForbidden();
        $this->get('/integrations/bexio?tenant='.$other->slug)->assertForbidden();
        $query = $this->begin();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $this->get('/integrations/bexio/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_central_callback_without_context_never_contacts_provider(): void
    {
        $this->get('/integrations/bexio/callback?state=wrong&code=test')->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_real_oidc_library_verifies_signed_callback_and_prevents_replay(): void
    {
        $this->get($this->base())->assertOk()->assertDontSee('test-secret');
        $query = $this->begin();
        Http::assertNothingSent();
        $this->fakeExchange($query);
        $url = $this->base().'/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']);
        $this->get($url)->assertRedirect()->assertSessionHas('notice');
        $this->assertSame('new-access', $this->service->accessToken($this->tenant));
        $this->assertSame('new-refresh', $this->service->connection($this->tenant)['refresh_token']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === BexioOidcClient::TOKEN_ENDPOINT && str_contains($request->body(), 'code_verifier='));
        $this->get($url)->assertSessionHas('connection_error');
        Http::assertSentCount(2);
        $legacy = Setting::where('tenant_id', $this->tenant->id)->where('key', 'BEXIO_REFRESH_TOKEN')->first();
        $this->assertSame('NEVER-ROTATE-LEGACY', Crypt::decryptString($legacy->encrypted_value));
    }

    public function test_invalid_state_never_contacts_provider_and_office_cannot_connect(): void
    {
        $this->begin();
        $this->get($this->base().'/callback?state=wrong&code=test')->assertSessionHas('connection_error');
        Http::assertNothingSent();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        $this->get($this->base())->assertForbidden();
        $this->post($this->base().'/connect')->assertForbidden();
        $this->get($this->base().'/callback?state=wrong&code=test')->assertForbidden();
    }

    public function test_bad_nonce_is_rejected_without_storing_tokens(): void
    {
        $query = $this->begin();
        $this->fakeExchange($query, ['nonce' => 'wrong']);
        $this->get($this->base().'/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('connection_error');
        $this->assertSame([], $this->service->connection($this->tenant));
    }

    public function test_refresh_rotates_atomically_and_reuses_new_token_after_concurrent_401(): void
    {
        $this->service->connect($this->tenant, ['access_token' => 'expired', 'refresh_token' => 'old-refresh', 'expires_in' => 1, 'token_type' => 'Bearer'], $this->service->configuration($this->tenant)['fingerprint'], $this->actor->id);
        Http::fake([BexioOidcClient::TOKEN_ENDPOINT => Http::response(['access_token' => 'rotated', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);
        $this->assertSame('rotated', $this->service->accessToken($this->tenant));
        $this->assertSame('rotated', $this->service->accessToken($this->tenant, 'expired'));
        $this->assertSame('rotated-refresh', $this->service->connection($this->tenant)['refresh_token']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['refresh_token'] === 'old-refresh' && $request['grant_type'] === 'refresh_token');
        $record = Setting::where('tenant_id', $this->tenant->id)->where('key', BexioConnectionService::CONNECTION_KEY)->first();
        $this->assertStringNotContainsString('rotated-refresh', $record->encrypted_value);
        $this->expectException(HttpException::class);
        app(SettingsService::class)->update($this->actor, $this->tenant, $record->id, 'tamper');
    }

    public function test_legacy_refresh_is_never_used_and_missing_connection_fails_closed(): void
    {
        try {
            $this->service->accessToken($this->tenant);
            $this->fail('Expected missing connection');
        } catch (\RuntimeException $e) {
            $this->assertSame(401, $e->getCode());
        }
        Http::assertNothingSent();
    }

    public function test_expired_callback_and_wrong_audience_are_rejected(): void
    {
        $query = $this->begin();
        $prefix = 'bexio_oidc.'.$this->tenant->id.'.'.$this->actor->id;
        session()->put($prefix.'.context.expires', time() - 1);
        $this->get($this->base().'/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('connection_error');
        Http::assertNothingSent();
        $query = $this->begin();
        $this->fakeExchange($query, ['aud' => 'another-client']);
        $this->get($this->base().'/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('connection_error');
        $this->assertSame([], $this->service->connection($this->tenant));
    }

    public function test_failed_refresh_does_not_replace_previous_connection(): void
    {
        $this->service->connect($this->tenant, ['access_token' => 'expired', 'refresh_token' => 'own-refresh', 'expires_in' => 1, 'token_type' => 'Bearer'], $this->service->configuration($this->tenant)['fingerprint'], $this->actor->id);
        $before = $this->service->connection($this->tenant);
        Http::fake([BexioOidcClient::TOKEN_ENDPOINT => Http::response(['error' => 'invalid_grant'], 400)]);
        try {
            $this->service->accessToken($this->tenant);
            $this->fail('Expected refresh failure');
        } catch (\RuntimeException $e) {
            $this->assertSame(401, $e->getCode());
        }
        $this->assertSame($before, $this->service->connection($this->tenant));
        Http::assertSentCount(1);
    }
}
