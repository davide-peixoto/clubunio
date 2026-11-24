<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Request;

class PlanningUi
{
    public static function available(Request $request): bool
    {
        return $request->attributes->get('clubunioWorkspace')
            && is_file(public_path('css/clubunio-team-planning.css'))
            && $request->routeIs('clubunio.workspace.planning.index', 'clubunio.workspace.planning.calendar');
    }

    public static function modern(Request $request, int $tenant): bool
    {
        return self::available($request) && app(ReactUi::class)->enabled($tenant);
    }
}
