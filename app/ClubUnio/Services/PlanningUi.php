<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanningUi
{
    public static function available(Request $request): bool
    {
        return $request->attributes->get('clubunioWorkspace')
            && config('clubunio-planning-ui.enabled', false)
            && is_file(public_path('css/clubunio-team-planning.css'))
            && $request->routeIs('clubunio.workspace.planning.index', 'clubunio.workspace.planning.calendar');
    }

    public static function key(Request $request, int $tenant): string
    {
        return 'clubunio.planning.design.'.$request->user()->getAuthIdentifier().'.'.$tenant;
    }

    public static function modern(Request $request, int $tenant): bool
    {
        return self::available($request) && $request->session()->get(self::key($request, $tenant), 'modern') === 'modern';
    }

    public static function select(Request $request, int $tenant): bool
    {
        if (! self::available($request) || ! $request->query->has('planning_design')) {
            return false;
        }
        $value = $request->validate(['planning_design' => ['required', Rule::in(['modern', 'classic'])]])['planning_design'];
        $request->session()->put(self::key($request, $tenant), $value);

        return true;
    }
}
