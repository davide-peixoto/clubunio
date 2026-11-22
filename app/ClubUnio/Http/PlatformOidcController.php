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
        return config('clubunio-verein-oidc.only')
            || (config('clubunio-platform-oidc.enabled') && !config('clubunio-platform-oidc.legacy_allowed'));
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
        if ($club && $config['enabled'] && $request->routeIs('clubunio.verein.oidc.start', 'clubunio.verein.oidc.callback', 'clubunio.verein.oidc.mobile', 'clubunio.verein.oidc.mobile.*')
            && $domain = app(\App\ClubUnio\Services\VereinOidcDomain::class)->configuration($request)) {
            $config = array_replace($config, $domain);
        }
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
        $platformMigration = $request->routeIs('clubunio.verein.oidc.platform-migration');
        abort_if($platformMigration && !\App\ClubUnio\Services\PlatformAdminKeycloakLink::configured(), 404);
        if ($request->routeIs('clubunio.verein.oidc.mobile.start', 'clubunio.verein.oidc.mobile.native')) {
            abort_unless($config['mobile_enabled'] && $config['invites_enabled'], 404);
            $native = $request->routeIs('clubunio.verein.oidc.mobile.native');
            app(KeycloakMobilePairingController::class)->validPair($request, $native);
            $pair = $request->only(['pair', 'confirmation']) + ['native' => $native];
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
        $request->session()->put($config['guard'].'.oidc.pending', compact('state','nonce','verifier','invitation','pair','platformMigration') + [
            'expires' => time() + 300, 'callback' => $config['callback'], 'client' => $config['client'],
        ]);

        $clubMfa = $config['guard'] === 'clubunio' && config('clubunio-verein-oidc.only');
        return redirect()->away($config['issuer'].'/protocol/openid-connect/auth?'.http_build_query([
            'response_type' => 'code', 'scope' => 'openid', 'client_id' => $config['client'],
            'redirect_uri' => $config['callback'], 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'ui_locales' => app()->getLocale(),
            ...($invitation !== null || $pair !== null || $platformMigration ? ['prompt' => 'login'] : []),
            ...(($pair !== null || $platformMigration || $clubMfa) && ($level = KeycloakMobileAssurance::level()) !== null
                ? ['claims' => json_encode(['id_token' => ['acr' => ['essential' => true, 'values' => [$level]]]], JSON_THROW_ON_ERROR), 'max_age' => 0]
                : []),
        ]))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function mobile(Request $request)
    {
        $config = $this->configuration($request);
        abort_unless(KeycloakMobilePairingController::available($config['origin']), 404);
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
        if (isset($config['tenant']) || isset($pending['callback'])) {
            abort_unless(($pending['callback'] ?? null) === $config['callback']
                && ($pending['client'] ?? null) === $config['client'], 403);
        }
        $claims = app(KeycloakCodeExchange::class)->verify($config, $code, $pending['verifier'], $pending['nonce']);
        $club = $config['guard'] === 'clubunio';
        $clubMfa = $club && config('clubunio-verein-oidc.only') && ($level = KeycloakMobileAssurance::level()) !== null;
        abort_if($clubMfa && !KeycloakMobileAssurance::accepts($claims, $level, time()), 403);
        if ($club && ($pending['platformMigration'] ?? false) === true) {
            $actor = app(\App\ClubUnio\Services\PlatformAdminKeycloakLink::class)->resolve($claims);
            abort_unless($actor, 403);
            Auth::guard('platform')->login($actor);
            $request->session()->regenerate();
            $request->session()->put('platform.oidc.expires', min($claims->exp + 1800, time() + 1800));

            return redirect()->route('clubunio.platform.index')->header('Cache-Control', 'no-store');
        }
        $links = app(KeycloakAccountLinks::class);
        $id = $config['subjects'][$claims->sub] ?? null;
        if ($club && $config['invites_enabled']) {
            if (is_string($pending['invitation'] ?? null)) {
                $id = (int) $links->accept($pending['invitation'], $claims, $clubMfa)->id;
            } elseif ($id === null) {
                $id = $links->accountId($claims->iss, $claims->sub);
                if ($id === null && !isset($config['tenant']) && !is_array($pending['pair'] ?? null)) {
                    $id = app(\App\ClubUnio\Services\PlatformKeycloakMigration::class)->activate($claims, $clubMfa);
                }
            }
        }
        $actor = is_int($id) && $id > 0 ? Account::find($id) : null;
        if (isset($config['tenant'])) {
            abort_unless($actor?->active && ! $actor->api_only && $actor->canAccessTenant($config['tenant']), 403);
        }
        if ($club && is_array($pending['pair'] ?? null)) {
            abort_unless($config['mobile_enabled'] && $config['invites_enabled'] && $actor?->active
                && ! $actor->api_only && $links->accountId($claims->iss, $claims->sub) === (int) $actor->id, 403);
            abort_unless($actor->tenants()->get()->contains(fn ($tenant) => app(MemberAccessService::class)->canEnter($actor, $tenant)), 403);
            $returnSecret = app(KeycloakMobilePairingController::class)->complete($pending['pair'], $actor, $claims, $config['origin']);
            if ($returnSecret !== null) {
                $scheme = $config['mobile_callback_scheme'] ?? 'clubunio-auth';
                return redirect()->away($scheme.'://signin?'.http_build_query([
                    'pair' => $pending['pair']['pair'], 'return_secret' => $returnSecret,
                ]))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
            }

            return response()->view('clubunio.keycloak-mobile', ['pair' => null])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        if ($club && $config['invites_enabled'] && $actor?->active && ! $actor->api_only && ! $actor->hasBackofficeTenant()) {
            $local = app(AccountSecurity::class);
            abort_if(!$clubMfa && $local->requiresStrongLogin($actor), 403);
            $tenant = $actor->tenants()->get()->first(fn ($tenant) => app(MemberAccessService::class)->canEnter($actor, $tenant));
            abort_unless($tenant, 403);
            Auth::guard('portal')->login($actor);
            $request->session()->forget(['clubunio.emergency.portal', 'clubunio.federated.portal']);
            $request->session()->regenerate();
            if ($clubMfa) {
                $request->session()->put('clubunio.federated.portal', [
                    'id' => (int) $actor->id, 'subject' => $claims->sub, 'stamp' => $local->stamp($actor),
                    'expires' => min($claims->exp + 1800, time() + 1800),
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
            $request->session()->put('clubunio.oidc.origin', $config['origin']);
        }
        return ($club ? redirect('/') : redirect()->route('clubunio.platform.index'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
