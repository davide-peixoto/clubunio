<?php

namespace App\Providers;

use App\ClubUnio\Http\BindTenantDomain;
use App\ClubUnio\Http\BindApiTenantDomain;
use App\ClubUnio\Services\TenantDomainService;
use App\ClubUnio\Services\BackendTheme;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\ServiceProvider;

class TenantBoundaryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', BindTenantDomain::class);
        $this->app->booted(function () {
            if (! $this->app->routesAreCached()) {
                foreach ($this->app['router']->getRoutes() as $route) {
                    if (str_starts_with($route->getName() ?? '', 'clubunio.api.')) {
                        // After bearer authentication, inside its audit/error envelope.
                        $route->middleware(BindApiTenantDomain::class);
                    }
                    if (str_starts_with($route->getName() ?? '', 'filament.clubunio.')) {
                        $action = $route->getAction();
                        $action['middleware'] = [BindTenantDomain::class, ...($action['middleware'] ?? [])];
                        $route->setAction($action);
                    }
                }
            }
            // Closures read request-local branding; never mutate a panel per tenant request.
            $branding = fn () => app(BackendTheme::class)->brand() ?? app(TenantDomainService::class)->brand();
            $panel = Filament::getPanel('clubunio');
            $panel->brandName(fn () => $branding()['name'] ?? 'ClubUnio')
                ->brandLogo(fn () => $branding()['logo'] ?? asset('brand/blue-v1/clubunio-logo-horizontal.svg'))
                ->darkModeBrandLogo(fn () => $branding()['logo'] ?? asset('brand/blue-v1/clubunio-logo-horizontal-white.svg'))
                ->favicon(fn () => $branding()['logo'] ?? asset('brand/blue-v1/clubunio-symbol.svg'))
                ->colors(fn () => ['primary' => ($brand = $branding()) ? Color::hex($brand['color'])
                    : array_replace(Color::hex('#164B8A'), [400 => Color::convertToOklch('#48A9E8'), 600 => Color::convertToOklch('#164B8A')])]);
        });
    }
}
