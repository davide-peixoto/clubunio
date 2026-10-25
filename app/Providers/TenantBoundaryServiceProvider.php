<?php

namespace App\Providers;

use App\ClubUnio\Http\BindTenantDomain;
use App\ClubUnio\Services\TenantDomainService;
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
                    if (str_starts_with($route->getName() ?? '', 'filament.clubunio.')) {
                        $action = $route->getAction();
                        $action['middleware'] = [BindTenantDomain::class, ...($action['middleware'] ?? [])];
                        $route->setAction($action);
                    }
                }
            }
            // Closures read request-local branding; never mutate a panel per tenant request.
            $panel = Filament::getPanel('clubunio');
            $panel->brandName(fn () => app(TenantDomainService::class)->brand()['name'] ?? 'ClubUnio')
                ->brandLogo(fn () => app(TenantDomainService::class)->brand()['logo'] ?? asset('brand/blue-v1/clubunio-logo-horizontal.svg'))
                ->darkModeBrandLogo(fn () => app(TenantDomainService::class)->brand()['logo'] ?? asset('brand/blue-v1/clubunio-logo-horizontal-white.svg'))
                ->favicon(fn () => app(TenantDomainService::class)->brand()['logo'] ?? asset('brand/blue-v1/clubunio-symbol.svg'))
                ->colors(fn () => ['primary' => ($brand = app(TenantDomainService::class)->brand()) ? Color::hex($brand['color'])
                    : array_replace(Color::hex('#164B8A'), [400 => Color::convertToOklch('#48A9E8'), 600 => Color::convertToOklch('#164B8A')])]);
        });
    }
}
