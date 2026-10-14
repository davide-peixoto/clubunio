<?php

namespace App\ClubUnio\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Jumbojett\OpenIDConnectClient;

/** Adapts the existing OIDC library to Laravel sessions and HTTP responses. */
class BexioOidcClient extends OpenIDConnectClient
{
    public const PROVIDER = 'https://auth.bexio.com/realms/bexio';

    public const TOKEN_ENDPOINT = self::PROVIDER.'/protocol/openid-connect/token';

    public function __construct(string $id, string $secret, private Session $session, private string $sessionPrefix)
    {
        parent::__construct(self::PROVIDER, $id, $secret);
        $this->providerConfigParam([
            'issuer' => self::PROVIDER,
            'authorization_endpoint' => self::PROVIDER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::TOKEN_ENDPOINT,
            'jwks_uri' => self::PROVIDER.'/protocol/openid-connect/certs',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
        $this->setCodeChallengeMethod('S256');
        $this->setHttpUpgradeInsecureRequests(false);
    }

    public function run(array $query): bool
    {
        // The library consumes $_REQUEST; only validated query parameters belong here.
        $previous = $_REQUEST;
        $_REQUEST = $query;
        try {
            return $this->authenticate();
        } finally {
            $_REQUEST = $previous;
        }
    }

    public function matchesState(string $state): bool
    {
        $expected = $this->getState();

        return is_string($expected) && $state !== '' && hash_equals($expected, $state);
    }

    protected function verifyJWTClaims($claims, ?string $accessToken = null): bool
    {
        if (($claims->iss ?? null) !== self::PROVIDER || ! is_string($claims->sub ?? null)
            || ! is_string($claims->nonce ?? null) || ! is_int($claims->exp ?? null)
            || ! is_int($claims->iat ?? null) || $claims->iat > time() + 60
            || ($this->getIdTokenHeader()->alg ?? '') !== 'RS256') {
            return false;
        }
        $audience = (array) ($claims->aud ?? []);
        if (! in_array($this->getClientID(), $audience, true)
            || (count($audience) > 1 && ($claims->azp ?? '') !== $this->getClientID())) {
            return false;
        }

        return parent::verifyJWTClaims($claims, $accessToken);
    }

    protected function getSessionKey(string $key)
    {
        return $this->session->get($this->sessionPrefix.'.'.$key, false);
    }

    protected function setSessionKey(string $key, $value)
    {
        $this->session->put($this->sessionPrefix.'.'.$key, $value);
    }

    protected function unsetSessionKey(string $key)
    {
        $this->session->forget($this->sessionPrefix.'.'.$key);
    }

    protected function commitSession() {}

    public function redirect(string $url)
    {
        if (! str_starts_with($url, self::PROVIDER.'/protocol/openid-connect/auth?')) {
            throw new \RuntimeException('Unexpected authorization URL');
        }
        throw new HttpResponseException(redirect()->away($url));
    }

    protected function fetchURL(string $url, ?string $post_body = null, array $headers = [])
    {
        if (! in_array($url, [self::TOKEN_ENDPOINT, self::PROVIDER.'/protocol/openid-connect/certs'], true)) {
            throw new \RuntimeException('Unexpected OIDC endpoint');
        }
        $request = Http::acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);
            $request = $request->withHeaders([$name => trim($value)]);
        }
        $response = $post_body === null ? $request->get($url)
            : $request->withBody($post_body, 'application/x-www-form-urlencoded')->post($url);
        if (! $response->successful()) {
            throw new \RuntimeException('OIDC provider failure', $response->status());
        }

        return $response->body();
    }
}
