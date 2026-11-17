<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\KeycloakAccountLinks;
use App\ClubUnio\Services\PlatformAdministrationService;
use App\ClubUnio\Services\KeycloakCodeExchange;
use App\ClubUnio\Services\KeycloakMobileAssurance;
use App\ClubUnio\Services\AccountSecurity;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        abort_unless($config['client'] && $config['secret'] && is_array($config['subjects'])
            && ($config['subjects'] || ($club && $config['invites_enabled'])), 503);
        return $config + ['guard' => $club ? 'clubunio' : 'platform',
            'callback' => $config['origin'].($club ? '/vereinslogin/oidc/callback' : '/platform/oidc/callback')];
    }

    public function start(Request $request)
    {
        $config = $this->configuration($request);
        $invitation = null;
        $pair = null;
        if ($request->routeIs('clubunio.verein.oidc.mobile.native')) {
            app(KeycloakMobilePairingController::class)->validPair($request, true);
            $pair = $request->only(['pair', 'confirmation']) + ['native' => true];
        } elseif ($request->routeIs('clubunio.verein.oidc.mobile.start')) {
            abort_unless($config['mobile_enabled'] && $config['invites_enabled'], 404);
            app(StrongLoginController::class)->validPair($request);
            $pair = $request->only(['pair', 'confirmation']);
        }
        if ($request->routeIs('clubunio.verein.oidc.invite.accept')) {
            abort_unless($config['invites_enabled'], 404);
            $secret = $request->input('invitation');
            abort_unless(is_string($secret) && preg_match('/\A[0-9a-f]{64}\z/', $secret), 403);
            $invitation = hash('sha256', $secret);
        }
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $request->session()->put($config['guard'].'.oidc.pending', compact('state','nonce','verifier','invitation','pair') + ['expires' => time() + 300]);

        $level = $pair !== null ? KeycloakMobileAssurance::level() : ($config['web_mfa_acr'] ?? null);

        return redirect()->away($config['issuer'].'/protocol/openid-connect/auth?'.http_build_query([
            'response_type' => 'code', 'scope' => 'openid', 'client_id' => $config['client'],
            'redirect_uri' => $config['callback'], 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'ui_locales' => app()->getLocale(),
            ...($invitation !== null || $pair !== null || $level !== null ? ['prompt' => 'login'] : []),
            ...($level !== null
                ? ['claims' => json_encode(['id_token' => ['acr' => ['essential' => true, 'values' => [$level]]]], JSON_THROW_ON_ERROR), 'max_age' => 0]
                : []),
        ]))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function mobile(Request $request)
    {
        $config = $this->configuration($request);
        abort_unless($config['mobile_enabled'] && $config['invites_enabled'], 404);
        $pair = $request->validate(['pair' => 'required|uuid'])['pair'];

        return response()->view('clubunio.keycloak-mobile', compact('pair'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function invitation(Request $request)
    {
        abort_unless($this->configuration($request)['invites_enabled'], 404);

        return response()->view('clubunio.keycloak-invitation')
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
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
        $claims = app(KeycloakCodeExchange::class)->verify($config, $code, $pending['verifier'], $pending['nonce']);
        $club = $config['guard'] === 'clubunio';
        $webLevel = $config['web_mfa_acr'] ?? null;
        $webMfa = is_string($webLevel) && KeycloakMobileAssurance::accepts($claims, $webLevel, time());
        abort_if($webLevel !== null && !$webMfa && !is_array($pending['pair'] ?? null), 403);
        $links = app(KeycloakAccountLinks::class);
        $id = $config['subjects'][$claims->sub] ?? null;
        if ($club && $config['invites_enabled']) {
            if (is_string($pending['invitation'] ?? null)) {
                $id = (int) $links->accept($pending['invitation'], $claims)->id;
            } elseif ($id === null) {
                $id = $links->accountId($claims->iss, $claims->sub);
            }
        }
        $actor = is_int($id) && $id > 0 ? Account::find($id) : null;
        if ($club && is_array($pending['pair'] ?? null)) {
            if (($pending['pair']['native'] ?? false) === true) {
                abort_unless($actor?->active && !$actor->api_only && $actor->tenants()->get()
                    ->contains(fn ($tenant) => app(MemberAccessService::class)->canEnter($actor, $tenant)), 403);
                $secret = app(KeycloakMobilePairingController::class)->complete($pending['pair'], $actor, $claims);

                return redirect()->away('clubunio-auth://signin?'.http_build_query([
                    'pair' => $pending['pair']['pair'], 'return_secret' => $secret,
                ]))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
            }
            abort_unless($config['mobile_enabled'] && $config['invites_enabled'] && $actor?->active
                && ! $actor->api_only && $links->accountId($claims->iss, $claims->sub) === (int) $actor->id, 403);
            abort_unless($actor->tenants()->get()->contains(fn ($tenant) => app(MemberAccessService::class)->canEnter($actor, $tenant)), 403);
            app(StrongLoginController::class)->completeKeycloakPair($pending['pair'], $actor, $claims);

            return response()->view('clubunio.keycloak-mobile', ['pair' => null])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        if ($club && ($webMfa || $config['invites_enabled']) && $actor?->active && ! $actor->api_only && ! $actor->hasBackofficeTenant()) {
            abort_if(!$webMfa && app(AccountSecurity::class)->requiresStrongLogin($actor), 403);
            $tenant = $actor->tenants()->get()->first(fn ($tenant) => app(MemberAccessService::class)->canEnter($actor, $tenant));
            abort_unless($tenant, 403);
            Auth::guard('portal')->login($actor);
            $request->session()->regenerate();
            if ($webMfa) {
                $request->session()->put([
                    'portal.oidc.account' => (int) $actor->id,
                    'portal.oidc.subject' => $claims->sub,
                    'portal.oidc.expires' => min($claims->exp + 1800, time() + 1800),
                ]);
            }

            return redirect()->route('clubunio.portal.members', ['tenant' => $tenant->slug])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        abort_unless($club ? ($actor?->active && !$actor->api_only && $actor->hasBackofficeTenant()) : $platform->allowed($actor), 403);
        Auth::guard($config['guard'])->login($actor);
        $request->session()->regenerate();
        $request->session()->put($config['guard'].'.oidc.expires', min($claims->exp + 1800, time() + 1800));
        if ($club) {
            $request->session()->put('clubunio.oidc.account', (int) $actor->id);
            $request->session()->put('clubunio.oidc.subject', $claims->sub);
        }
        return ($club ? redirect('/') : redirect()->route('clubunio.platform.index'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
