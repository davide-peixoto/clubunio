<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\PlatformAdministrationService;
use Firebase\JWT\{JWK, JWT};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Http};

final class PlatformOidcController
{
    public static function requiresClubOidc(Account $account): bool
    {
        $config = config('clubunio-verein-oidc');
        return $config['enabled'] && !$config['legacy_allowed']
            && is_array($config['subjects']) && in_array((int) $account->id, $config['subjects'], true);
    }

    public static function required(): bool
    {
        return config('clubunio-platform-oidc.enabled') && !config('clubunio-platform-oidc.legacy_allowed');
    }

    public static function guard(Request $request): string
    {
        return self::required() || (config('clubunio-platform-oidc.enabled') && $request->isSecure()
            && $request->getSchemeAndHttpHost() === config('clubunio-platform-oidc.origin')
            && $request->hasSession() && (int) $request->session()->get('platform.oidc.expires') > time()
            && $request->user('platform')) ? 'platform' : 'clubunio';
    }

    private function configuration(Request $request): array
    {
        $club = $request->routeIs('clubunio.verein.oidc.*');
        $config = config($club ? 'clubunio-verein-oidc' : 'clubunio-platform-oidc');
        abort_unless($config['enabled'] && $request->isSecure() && $request->getSchemeAndHttpHost() === $config['origin'], 404);
        abort_unless($config['client'] && $config['secret'] && is_array($config['subjects']) && $config['subjects'], 503);
        return $config + ['guard' => $club ? 'clubunio' : 'platform',
            'callback' => $config['origin'].($club ? '/vereinslogin/oidc/callback' : '/platform/oidc/callback')];
    }

    public function start(Request $request)
    {
        $config = $this->configuration($request);
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $request->session()->put($config['guard'].'.oidc.pending', compact('state','nonce','verifier') + ['expires' => time() + 300]);

        return redirect()->away($config['issuer'].'/protocol/openid-connect/auth?'.http_build_query([
            'response_type' => 'code', 'scope' => 'openid', 'client_id' => $config['client'],
            'redirect_uri' => $config['callback'], 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'ui_locales' => app()->getLocale(),
        ]))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, PlatformAdministrationService $platform)
    {
        $config = $this->configuration($request);
        $pending = $request->session()->pull($config['guard'].'.oidc.pending');
        $state = $request->query('state');
        $code = $request->query('code');
        abort_unless(is_array($pending) && $pending['expires'] >= time() && is_string($state)
            && hash_equals($pending['state'], $state) && is_string($code) && $code !== '' && strlen($code) <= 4096, 403);
        abort_if($request->query('iss') !== null && $request->query('iss') !== $config['issuer'], 403);
        $endpoint = $config['issuer'].'/protocol/openid-connect/';
        try {
            $http = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
            $token = (clone $http)->asForm()->withBasicAuth($config['client'], $config['secret'])->post($endpoint.'token', [
                'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $pending['verifier'],
                'redirect_uri' => $config['callback'],
            ])->throw()->json('id_token');
            if (!is_string($token) || strlen($token) > 16384) throw new \UnexpectedValueException;
            $keys = (clone $http)->get($endpoint.'certs')->throw()->json();
            $rsa = array_values(array_filter($keys['keys'] ?? [], fn ($key) => is_array($key)
                && ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256'
                && ($key['use'] ?? 'sig') === 'sig'));
            $claims = JWT::decode($token, JWK::parseKeySet(['keys' => $rsa], 'RS256'));
        } catch (\Throwable) {
            abort(403);
        }
        $audience = (array) ($claims->aud ?? []);
        abort_unless(($claims->iss ?? null) === $config['issuer'] && in_array($config['client'], $audience, true)
            && (count($audience) === 1 || isset($claims->azp)) && ($claims->azp ?? $config['client']) === $config['client']
            && ($claims->nonce ?? null) === $pending['nonce'] && is_int($claims->exp ?? null) && $claims->exp > time()
            && is_int($claims->iat ?? null) && $claims->iat >= time() - 300 && $claims->iat <= time() + 10
            && is_string($claims->sub ?? null), 403);
        $id = $config['subjects'][$claims->sub] ?? null;
        $actor = is_int($id) && $id > 0 ? Account::find($id) : null;
        $club = $config['guard'] === 'clubunio';
        abort_unless($club ? ($actor?->active && !$actor->api_only && $actor->hasBackofficeTenant()) : $platform->allowed($actor), 403);
        Auth::guard($config['guard'])->login($actor);
        $request->session()->regenerate();
        $request->session()->put($config['guard'].'.oidc.expires', min($claims->exp + 1800, time() + 1800));
        if ($club) $request->session()->put('clubunio.oidc.account', (int) $actor->id);
        return ($club ? redirect('/') : redirect()->route('clubunio.platform.index'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
