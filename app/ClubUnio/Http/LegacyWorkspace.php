<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\ActiveTenant;
use Closure;
use Illuminate\Http\Request;

class LegacyWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user('clubunio');
        $tenant = $request->route('tenant');
        if (! $actor || ! $actor->canAccessTenant($tenant)) {
            return $next($request);
        }
        abort_unless($request->isMethodSafe(), 419, 'Bitte die Seite neu laden.');
        $selected = app(ActiveTenant::class)->resolve($request);
        abort_unless($selected->is($tenant), 403, 'Bitte zuerst den passenden Verein auswählen.');
        $parameters = $request->route()->parameters();
        unset($parameters['tenant']);

        return redirect()->route(str_replace('clubunio.', 'clubunio.workspace.', $request->route()->getName()), $parameters + $request->query());
    }
}
