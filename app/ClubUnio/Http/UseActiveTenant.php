<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\ActiveTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class UseActiveTenant
{
    public function handle(Request $request, Closure $next)
    {
        Filament::setTenant(app(ActiveTenant::class)->resolve($request));

        return $next($request);
    }
}
