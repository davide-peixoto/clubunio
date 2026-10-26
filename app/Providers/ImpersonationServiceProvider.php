<?php

namespace App\Providers;

use App\ClubUnio\Http\ImpersonationMiddleware;
use Illuminate\Support\ServiceProvider;

class ImpersonationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            if (! $this->app->routesAreCached()) {
                foreach ($this->app['router']->getRoutes() as $route) {
                    if (str_starts_with($route->getName() ?? '', 'filament.clubunio.')) {
                        $route->middleware(ImpersonationMiddleware::class);
                    }
                }
            }
        });
        $this->loadRoutesFrom(base_path('routes/clubunio-support.php'));
    }
}
