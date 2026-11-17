<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\AccountSecurity;
use App\ClubUnio\Services\KeycloakAccountLinks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RequireStrongWebSession
{
    public function handle(Request $request, Closure $next)
    {
        if (PlatformOidcController::guard($request) === 'platform' && $request->routeIs('clubunio.platform.*', 'clubunio.support.platform-intake')) {
            return $next($request);
        }
        if ($request->routeIs('clubunio.verein.oidc.*')) return $next($request);
        $security = app(AccountSecurity::class);
        if ($request->routeIs('clubunio.security.*', 'filament.clubunio.auth.logout', 'clubunio.portal.logout', 'clubunio.platform.logout')) {
            return $next($request);
        }
        foreach (['clubunio', 'portal'] as $guard) {
            $actor = Auth::guard($guard)->user();
            if ($guard === 'clubunio' && $actor && (config('clubunio-verein-oidc.enabled') || config('clubunio-verein-oidc.only'))) {
                $id = $request->session()->get('clubunio.oidc.account');
                if (config('clubunio-verein-oidc.enabled') && $actor->active && !$actor->api_only && $id === (int) $actor->id && (int) $request->session()->get('clubunio.oidc.expires') > time()
                    && $request->isSecure() && $request->getSchemeAndHttpHost() === config('clubunio-verein-oidc.origin')
                    && (in_array($id, (array) config('clubunio-verein-oidc.subjects'), true)
                        || (config('clubunio-verein-oidc.invites_enabled')
                            && is_string($subject = $request->session()->get('clubunio.oidc.subject'))
                            && app(KeycloakAccountLinks::class)->accountId(
                                config('clubunio-verein-oidc.issuer'), $subject,
                            ) === $id))) continue;
                if ($id !== null || PlatformOidcController::requiresClubOidc($actor)) {
                    Auth::guard($guard)->logout();
                    $request->session()->forget(['clubunio.oidc.expires', 'clubunio.oidc.account', 'clubunio.oidc.subject']);
                    if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
                    return redirect()->route('clubunio.verein.oidc.start');
                }
            }
            if ($guard === 'portal' && $actor && (config('clubunio-verein-oidc.only') || $request->session()->has('portal.oidc.account'))) {
                $subject = $request->session()->get('portal.oidc.subject');
                if (config('clubunio-verein-oidc.enabled') && $request->isSecure()
                    && $request->getSchemeAndHttpHost() === config('clubunio-verein-oidc.origin')
                    && $request->session()->get('portal.oidc.account') === (int) $actor->id
                    && (int) $request->session()->get('portal.oidc.expires') > time()
                    && is_string($subject) && (config('clubunio-verein-oidc.subjects')[$subject] ?? null) === (int) $actor->id
                    && $actor->active && !$actor->api_only) continue;
                Auth::guard('portal')->logout();
                $request->session()->forget(['portal.oidc.account', 'portal.oidc.subject', 'portal.oidc.expires']);
                if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
                return redirect()->route('clubunio.portal.login');
            }
            if (!$actor || !$security->requiresStrongLogin($actor)) continue;
            $stamp = $request->session()->get('clubunio.security.'.$guard);
            if (is_string($stamp) && hash_equals($security->stamp($actor), $stamp)) continue;
            Auth::guard($guard)->logout();
            $request->session()->regenerate();
            if (!$request->isMethodSafe() || $request->expectsJson()) abort(401);
            return redirect()->route('clubunio.security.show', [
                'mode' => $guard === 'clubunio' && $request->routeIs('clubunio.platform.*') ? 'platform' : $guard,
                'setup' => $security->mustEnroll($actor) ? '1' : null,
            ]);
        }
        return $next($request);
    }
}
