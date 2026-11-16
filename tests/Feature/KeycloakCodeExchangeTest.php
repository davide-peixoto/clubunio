<?php

namespace Tests\Feature;

use App\ClubUnio\Services\KeycloakCodeExchange;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class KeycloakCodeExchangeTest extends TestCase
{
    private const ISSUER = 'https://idp.test/realms/synthetic';

    private function fakeProvider(array $overrides = []): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $token = JWT::encode($overrides + [
            'iss' => self::ISSUER, 'aud' => 'app-test', 'sub' => 'synthetic-subject',
            'nonce' => 'synthetic-nonce', 'iat' => time(), 'exp' => time() + 60,
        ], $key, 'RS256', 'synthetic-key');
        $base64url = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        Http::swap(new Factory);
        Http::fake([
            self::ISSUER.'/protocol/openid-connect/token' => Http::response(['id_token' => $token]),
            self::ISSUER.'/protocol/openid-connect/certs' => Http::response(['keys' => [[
                'kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'synthetic-key',
                'n' => $base64url($rsa['n']), 'e' => $base64url($rsa['e']),
            ]]]),
        ]);
    }

    public function test_public_client_exchanges_a_code_with_pkce_and_no_secret(): void
    {
        $this->fakeProvider();
        $claims = $this->exchange();
        $this->assertSame('synthetic-subject', $claims->sub);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/token')
            && ! $r->hasHeader('Authorization') && $r['client_id'] === 'app-test'
            && $r['code_verifier'] === 'synthetic-verifier');
    }

    public function test_confidential_client_authenticates_without_changing_the_exchange(): void
    {
        $this->fakeProvider();
        $this->assertSame('synthetic-subject', $this->exchange('synthetic-secret')->sub);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/token')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('app-test:synthetic-secret')));
    }

    public function test_claims_from_another_flow_cannot_login(): void
    {
        foreach ([['nonce' => 'other'], ['iss' => 'https://other.test'], ['aud' => 'another-client'],
            ['aud' => ['app-test', 'another-client']], ['azp' => 'another-client'], ['sub' => ''],
            ['exp' => time() - 60], ['iat' => time() - 360]] as $invalid) {
            $this->fakeProvider($invalid);
            try {
                $this->exchange();
                $this->fail('Unexpectedly accepted invalid identity claims.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }

    private function exchange(?string $secret = null): \stdClass
    {
        return app(KeycloakCodeExchange::class)->verify([
            'issuer' => self::ISSUER, 'client' => 'app-test',
            'callback' => 'https://club.test/callback', 'secret' => $secret,
        ], 'synthetic-code', 'synthetic-verifier', 'synthetic-nonce');
    }
}
