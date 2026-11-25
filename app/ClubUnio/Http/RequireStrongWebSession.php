<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\AccountSecurity;
use App\ClubUnio\Services\EmergencyPasswordLogin;
use App\ClubUnio\Services\KeycloakAccountLinks;
use App\ClubUnio\Services\KeycloakWebAssurance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RequireStrongWebSession
{
    public function handle(Request $request, Closure $next)
    {
        if (PlatformOidcController::guard($request) === 'platform' && $request->routeIs('clubunio.platform.*', 'clubunio.support.platform-intake')) {
            $actor = $request->user('platform');
            if ($actor && !$request->routeIs('clubunio.platform.oidc.*', 'clubunio.platform.logout')
                && !KeycloakWebAssurance::acceptsSession($actor, $request->session()->get('platform.oidc.assurance'), true)) {
                Auth::guard('platform')->logout();
                $request->session()->forget(['platform.oidc.expires', 'platform.oidc.assurance']);
                if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
                return redirect()->route('clubunio.platform.oidc.start');
            }
            return $next($request);
        }
        if ($request->routeIs('clubunio.verein.oidc.*')) return $next($request);
        $security = app(AccountSecurity::class);
        if ($request->routeIs('filament.clubunio.auth.logout', 'clubunio.portal.logout', 'clubunio.platform.logout')) {
            return $next($request);
        }
        foreach (['clubunio', 'portal'] as $guard) {
            $actor = Auth::guard($guard)->user();
            if ($guard === 'portal' && $request->session()->has('clubunio.emergency.portal')) {
                $stamp = $request->session()->get('clubunio.emergency.portal');
                $active = $actor ? app(EmergencyPasswordLogin::class)->stamp($request, $actor) : null;
                if (is_string($stamp) && $active !== null && hash_equals($active, $stamp)) continue;
                Auth::guard($guard)->logout();
                $request->session()->forget('clubunio.emergency.portal');
                $request->session()->regenerate();
                if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
                return redirect()->route('clubunio.portal.login');
            }
            if ($guard === 'portal' && $request->session()->has('clubunio.federated.portal')) {
                $proof = $request->session()->get('clubunio.federated.portal');
                $subject = $proof['subject'] ?? null;
                if ($actor && $actor->active && !$actor->api_only && ($proof['id'] ?? null) === (int) $actor->id
                    && ($proof['expires'] ?? 0) > time() && is_string($subject) && is_string($proof['stamp'] ?? null)
                    && $request->isSecure() && $request->getSchemeAndHttpHost() === config('clubunio-verein-oidc.origin')
                    && KeycloakWebAssurance::acceptsSession($actor, $request->session()->get('portal.oidc.assurance'))
                    && hash_equals($security->stamp($actor), $proof['stamp'])
                    && (((array) config('clubunio-verein-oidc.subjects'))[$subject] ?? app(KeycloakAccountLinks::class)
                        ->accountId(config('clubunio-verein-oidc.issuer'), $subject)) === (int) $actor->id) continue;
                Auth::guard($guard)->logout();
                $request->session()->forget('clubunio.federated.portal');
                $request->session()->regenerate();
                if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
                return redirect()->route('clubunio.verein.oidc.start');
            }
            if ($guard === 'clubunio' && $actor && config('clubunio-verein-oidc.enabled')) {
                $id = $request->session()->get('clubunio.oidc.account');
                $origin = config('clubunio-verein-oidc.origin');
                $domain = app(\App\ClubUnio\Services\VereinOidcDomain::class)->configuration($request);
                if ($domain && $actor->canAccessTenant($domain['tenant'])) {
                    $origin = $domain['origin'];
                }
                if ($id === (int) $actor->id && (int) $request->session()->get('clubunio.oidc.expires') > time()
                    && KeycloakWebAssurance::acceptsSession($actor, $request->session()->get('clubunio.oidc.assurance'))
                    && $request->isSecure() && $request->getSchemeAndHttpHost() === $origin
                    && $request->session()->get('clubunio.oidc.origin', config('clubunio-verein-oidc.origin')) === $origin
                    && (in_array($id, (array) config('clubunio-verein-oidc.subjects'), true)
                        || (config('clubunio-verein-oidc.invites_enabled')
                            && is_string($subject = $request->session()->get('clubunio.oidc.subject'))
                            && app(KeycloakAccountLinks::class)->accountId(
                                config('clubunio-verein-oidc.issuer'), $subject,
                            ) === $id))) continue;
                if ($id !== null || PlatformOidcController::requiresClubOidc($actor)) {
                    Auth::guard($guard)->logout();
                    $request->session()->forget(['clubunio.oidc.expires', 'clubunio.oidc.account', 'clubunio.oidc.subject', 'clubunio.oidc.origin']);
                    if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
                    return redirect()->route('clubunio.verein.oidc.start');
                }
            }
            if (!$actor || !$security->requiresStrongLogin($actor)) continue;
            $stamp = $request->session()->get('clubunio.security.'.$guard);
            if (is_string($stamp) && hash_equals($security->stamp($actor), $stamp)) continue;
            Auth::guard($guard)->logout();
            $request->session()->regenerate();
            if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
            if (config('clubunio-verein-oidc.enabled')) {
                return redirect()->route('clubunio.verein.oidc.start');
            }
            abort(403);
        }
        return $next($request);
    }
}
