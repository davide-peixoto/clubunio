<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobilePushEnvelope, MobilePushFcm};
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MobilePushFcmTest extends TestCase
{
    private string $keyFile;
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/php/extras/ssl/openssl.cnf')) $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'synthetic-fcm-');
        file_put_contents($this->keyFile, json_encode(['type' => 'service_account', 'project_id' => 'synthetic-project',
            'private_key' => $pem, 'client_email' => 'synthetic@synthetic-project.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token'], JSON_THROW_ON_ERROR));
    }
    protected function tearDown(): void { if (isset($this->keyFile)) unlink($this->keyFile); parent::tearDown(); }
    private function send(): array
    {
        return app(MobilePushFcm::class)->send(['project_id' => 'synthetic-project', 'credentials_file' => $this->keyFile],
            str_repeat('synthetic-device-token', 3), new MobilePushEnvelope(12, 34, 'planning', 56, '00000000-0000-4000-8000-000000000001'));
    }
    public function test_real_oauth_library_and_fcm_request_are_faked_without_private_payload(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/synthetic-project/messages/synthetic-123'])]);
        self::assertSame(['state' => 'accepted', 'code' => 'provider_accepted', 'reference' => 'projects/synthetic-project/messages/synthetic-123'], $this->send());
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') return false;
            parse_str($request->body(), $body);
            $jwt = explode('.', $body['assertion']);
            $claims = json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true);
            return $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
                && $claims['aud'] === 'https://oauth2.googleapis.com/token';
        });
        Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/synthetic-project/messages:send'
            && $r['message']['data'] === ['v' => '1', 'account_id' => '12', 'tenant_id' => '34', 'kind' => 'planning',
                'object_id' => '56', 'delivery_id' => '00000000-0000-4000-8000-000000000001']
            && $r->hasHeader('Authorization', 'Bearer synthetic-access'));
    }
    public function test_uncertain_post_is_never_retried(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::failedConnection()]);
        self::assertSame(['state' => 'attention', 'code' => 'delivery_uncertain'], $this->send());
        // Exactly one OAuth and one FCM attempt, including the uncertain connection.
        Http::assertSentCount(2);
    }
    public function test_unregistered_token_is_definitively_discarded(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404)]);
        self::assertSame(['state' => 'discarded', 'code' => 'token_unregistered'], $this->send());
    }
    public function test_oauth_failure_does_not_send_to_fcm(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        self::assertSame(['state' => 'attention', 'code' => 'oauth_unavailable'], $this->send());
        Http::assertSentCount(1);
    }
    public function test_untrusted_credential_uri_is_rejected_before_http(): void
    {
        $data = json_decode(file_get_contents($this->keyFile), true); $data['token_uri'] = 'https://attacker.example/token';
        file_put_contents($this->keyFile, json_encode($data));
        try { $this->send(); self::fail('Untrusted OAuth URI accepted'); }
        catch (\LogicException) { Http::assertNothingSent(); }
    }
    public function test_unknown_target_kind_cannot_encode_a_url_or_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MobilePushEnvelope(1, 2, 'https://attacker.example', 3, '00000000-0000-4000-8000-000000000001');
    }
}
