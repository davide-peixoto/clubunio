<?php

namespace Tests\Feature;

use App\ClubUnio\Wallet\GoogleWalletClient;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class GoogleWalletClientTest extends TestCase
{
    private string $path;
    private string $publicKey;
    private GoogleWalletClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/php/extras/ssl/openssl.cnf')) $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options); $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->path = tempnam(sys_get_temp_dir(), 'wallet-test-');
        file_put_contents($this->path, json_encode(['type' => 'service_account', 'project_id' => 'clubunio-test',
            'client_email' => 'clubunio-wallet-stage@clubunio-test.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
        chmod($this->path, 0600);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'cache.default' => 'array', 'app.url' => 'https://stage.clubunio.com',
            'clubunio-google-wallet' => ['enabled' => true, 'environment' => 'stage', 'issuer_id' => '3388000000023233410',
                'project_id' => 'clubunio-test', 'service_account_email' => 'clubunio-wallet-stage@clubunio-test.iam.gserviceaccount.com',
                'credentials' => $this->path, 'public_origin' => 'https://stage.clubunio.com']]);
        Cache::flush(); Http::preventStrayRequests(); $this->client = new GoogleWalletClient;
    }

    protected function tearDown(): void { if (isset($this->path)) @unlink($this->path); parent::tearDown(); }

    private function id(): string { return $this->client->objectId('f9ad94a7-3fd4-4234-82a3-67df52ef6a5c'); }
    private function payload(): array { return ['id' => $this->id(), 'classId' => $this->client->classId(), 'state' => 'ACTIVE']; }

    public function test_save_jwt_is_signed_scoped_and_contains_only_object_reference(): void
    {
        $this->assertTrue($this->client->available());
        $url = $this->client->saveUrl($this->id());
        $parts = explode('.', substr($url, strlen('https://pay.google.com/gp/v/save/')));
        $decode = fn ($v) => base64_decode(strtr($v, '-_', '+/'));
        $this->assertSame(1, openssl_verify($parts[0].'.'.$parts[1], $decode($parts[2]), $this->publicKey, OPENSSL_ALGO_SHA256));
        $claims = json_decode($decode($parts[1]), true);
        $this->assertSame('google', $claims['aud']); $this->assertSame('savetowallet', $claims['typ']);
        $this->assertSame(['genericObjects' => [['id' => $this->id()]]], $claims['payload']);
        $this->assertLessThan(1800, strlen($url)); Http::assertNothingSent();
    }

    public function test_environment_and_credential_mismatch_fail_closed(): void
    {
        config(['app.url' => 'https://app.clubunio.com']); $this->assertFalse($this->client->available());
        config(['app.url' => 'https://stage.clubunio.com', 'clubunio-google-wallet.service_account_email' => 'other@example.test']);
        $this->assertFalse($this->client->available()); Http::assertNothingSent();
    }

    public function test_cross_environment_object_is_rejected_without_network(): void
    {
        $this->expectExceptionMessage('google_wallet_identifier');
        try { $this->client->upsert(array_replace($this->payload(), ['id' => str_replace('_stage_', '_prod_', $this->id())])); }
        finally { Http::assertNothingSent(); }
    }

    public function test_concurrent_insert_conflict_reconciles_existing_object_and_patches_it(): void
    {
        Http::fakeSequence()->push(['access_token' => 'synthetic', 'expires_in' => 3600])
            ->push([], 404)->push([], 409)->push(['id' => $this->client->classId()])
            ->push([], 404)->push([], 409)->push($this->payload())->push($this->payload());
        $this->client->upsert($this->payload());
        Http::assertSentCount(8);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->url() === 'https://walletobjects.googleapis.com/walletobjects/v1/genericObject/'.$this->id());
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
    }

    public function test_provider_failure_is_sanitized_and_does_not_retry_with_another_object(): void
    {
        Http::fakeSequence()->push(['access_token' => 'synthetic', 'expires_in' => 3600])
            ->push(['private_provider_error' => 'secret'], 429);
        $this->expectExceptionMessage('google_wallet_http_429');
        try { $this->client->upsert($this->payload()); }
        finally { Http::assertSentCount(2); }
    }

    public function test_existing_object_with_wrong_class_is_never_patched(): void
    {
        Http::fakeSequence()->push(['access_token' => 'synthetic', 'expires_in' => 3600])
            ->push(['id' => $this->client->classId()])->push(['id' => $this->id(), 'classId' => 'foreign']);
        $this->expectExceptionMessage('google_wallet_identifier');
        try { $this->client->upsert($this->payload()); }
        finally { Http::assertSentCount(3); }
    }

    public function test_timeout_after_insert_can_be_reconciled_using_the_same_object(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if ($calls === 1) return Http::response(['access_token' => 'synthetic', 'expires_in' => 3600]);
            if (in_array($calls, [2, 5])) return Http::response(['id' => $this->client->classId()]);
            if ($calls === 3) return Http::response([], 404);
            if ($calls === 4) throw new \Illuminate\Http\Client\ConnectionException('synthetic network interruption');
            return Http::response($this->payload());
        });
        try { $this->client->upsert($this->payload()); $this->fail('Timeout expected'); }
        catch (\RuntimeException $error) { $this->assertSame('google_wallet_transport', $error->getMessage()); }
        $this->client->upsert($this->payload());
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && ! isset($request['id']) && ! isset($request['classId']));
        $this->assertSame(7, $calls);
    }
}
