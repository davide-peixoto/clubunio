<?php

namespace App\ClubUnio\Services;

use Firebase\JWT\{JWK, JWT};
use Illuminate\Support\Facades\Http;

final class KeycloakCodeExchange
{
    public function verify(array $config, string $code, string $verifier, string $nonce): \stdClass
    {
        $endpoint = $config['issuer'].'/protocol/openid-connect/';
        try {
            $http = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
            $exchange = (clone $http)->asForm();
            $client = empty($config['secret'])
                ? ['client_id' => $config['client']]
                : [];
            if ($client === []) {
                $exchange->withBasicAuth($config['client'], $config['secret']);
            }
            $token = $exchange->post($endpoint.'token', $client + [
                'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
                'redirect_uri' => $config['callback'],
            ])->throw()->json('id_token');
            if (!is_string($token) || strlen($token) > 16384) throw new \UnexpectedValueException;
            $keys = (clone $http)->get($endpoint.'certs')->throw()->json();
            $claims = self::decodeSignedToken($token, $keys);
        } catch (\Throwable) {
            abort(403);
        }
        $audience = (array) ($claims->aud ?? []);
        abort_unless(($claims->iss ?? null) === $config['issuer'] && in_array($config['client'], $audience, true)
            && (count($audience) === 1 || isset($claims->azp)) && ($claims->azp ?? $config['client']) === $config['client']
            && ($claims->nonce ?? null) === $nonce && is_int($claims->exp ?? null) && $claims->exp > time()
            && is_int($claims->iat ?? null) && $claims->iat >= time() - 300 && $claims->iat <= time() + 10
            && is_string($claims->sub ?? null) && $claims->sub !== '', 403);

        return $claims;
    }

    /** Same restricted signing algorithm for ID tokens and backchannel logout. */
    public static function decodeSignedToken(#[\SensitiveParameter] string $token, array $keys): \stdClass
    {
        if (strlen($token) > 16384) throw new \UnexpectedValueException;
        $rsa = array_values(array_filter($keys['keys'] ?? [], fn ($key) => is_array($key)
            && ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256'
            && ($key['use'] ?? 'sig') === 'sig'));

        return JWT::decode($token, JWK::parseKeySet(['keys' => $rsa], 'RS256'));
    }
}
