<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\BackofficeReadContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class UseActiveTenant
{
    public function handle(Request $request, Closure $next)
    {
        Filament::setTenant(app(ActiveTenant::class)->resolve($request));

        return app(BackofficeReadContext::class)->during($request, $next);
    }
}
