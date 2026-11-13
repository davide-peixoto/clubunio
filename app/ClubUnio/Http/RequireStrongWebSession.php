<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\AccountSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RequireStrongWebSession
{
    public function handle(Request $request, Closure $next)
    {
        $security = app(AccountSecurity::class);
        if (!$security->enabled() || $request->routeIs('clubunio.security.*', 'filament.clubunio.auth.logout', 'clubunio.portal.logout', 'clubunio.platform.logout')) {
            return $next($request);
        }
        foreach (['clubunio', 'portal'] as $guard) {
            $actor = Auth::guard($guard)->user();
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
