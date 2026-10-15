<?php

namespace App\Providers;

use App\ClubUnio\Http\LegacyWorkspace;
use App\ClubUnio\Http\UseActiveTenant;
use App\ClubUnio\Http\UseClubWorkspace;
use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\SessionUrlGenerator;
use App\ClubUnio\WorkspaceRoutes;
use Filament\Facades\Filament;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\ServiceProvider;
use Livewire\EventBus;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TenantNavigationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);
            $url = new SessionUrlGenerator($routes, $app->rebinding('request', function ($app, $request) {
                $app['url']->setRequest($request);
            }), $app['config']['app.asset_url']);
            $url->setSessionResolver(fn () => $app['session']);
            $url->setKeyResolver(fn () => [$app['config']['app.key'], ...($app['config']['app.previous_keys'] ?? [])]);

            return $url;
        });
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            if ($this->app->routesAreCached()) {
                return;
            }
            // Adapt only our panel's generated routes, retaining all Filament middleware/scopes.
            $routes = new RouteCollection;
            // Register clean workspace routes before legacy tenant-slug routes.
            foreach ($this->app['router']->getRoutes() as $route) {
                $documentUri = WorkspaceRoutes::DOCUMENTS[$route->getName() ?? ''] ?? null;
                if (! $documentUri && ! preg_match('~^(bus|planning)/\{tenant(?::slug)?\}(/|$)~', $route->uri())) {
                    continue;
                }
                $workspace = clone $route;
                $workspace->setUri($documentUri ?? preg_replace('~/\{tenant(?::slug)?\}~', '', $route->uri()));
                $action = $workspace->getAction();
                $action['prefix'] = $documentUri ? '' : preg_replace('~/\{tenant(?::slug)?\}~', '', $action['prefix'] ?? '');
                if ($route->getName()) {
                    $action['as'] = str_replace('clubunio.', 'clubunio.workspace.', $route->getName());
                }
                $action['middleware'] = array_values(array_filter($action['middleware'] ?? [], fn ($item) => $item !== 'auth:clubunio,portal'));
                $action['middleware'][] = 'auth:clubunio';
                $action['middleware'][] = SetUpPanel::class.':clubunio';
                $action['middleware'][] = UseActiveTenant::class;
                $action['middleware'][] = UseClubWorkspace::class;
                $workspace->setAction($action);
                $routes->add($workspace);
                $route->middleware(LegacyWorkspace::class);
            }
            foreach ($this->app['router']->getRoutes() as $route) {
                if (str_starts_with($route->getName() ?? '', 'filament.clubunio.')) {
                    if ($route->getName() === 'filament.clubunio.tenant') {
                        continue;
                    }
                    $route->setUri(preg_replace('~^\{tenant(?::[^}]+)?\}/?~', '', $route->uri()) ?: '/');
                    $action = $route->getAction();
                    $action['prefix'] = preg_replace('~^\{tenant(?::[^}]+)?\}/?~', '', trim($action['prefix'] ?? '', '/'));
                    $route->setAction($action);
                }
                $routes->add($route);
            }
            $routes->refreshNameLookups();
            $routes->refreshActionLookups();
            $this->app['router']->setRoutes($routes);
            $this->app->instance('routes', $routes);
            $this->app['url']->setRoutes($routes);
        });

        app(EventBus::class)->on('dehydrate', function ($component, $context) {
            if (Filament::getTenant() && request()->hasSession() && request()->user('clubunio')) {
                $context->addMemo('clubunioContext', request()->session()->get(app(ActiveTenant::class)->key(request())));
            }
        });
        // Runs after checksum verification but before persistent middleware and model hydration.
        app(EventBus::class)->before('snapshot-verified', function ($snapshot) {
            if (! app(HandleRequests::class)->isLivewireRoute()) {
                return;
            }
            $context = $snapshot['memo']['clubunioContext'] ?? null;
            if (is_array($context)) {
                app(ActiveTenant::class)->assertSnapshot(request(), $context);

                return;
            }
            $path = $snapshot['memo']['path'] ?? '';
            abort_if(preg_match('~^(clubunio|clubora)(/|$)~', $path) && ! in_array($path, ['clubunio/login', 'clubora/login'], true), 419);
            try {
                $route = app('router')->getRoutes()->match(Request::create('/'.ltrim($path, '/')));
            } catch (HttpExceptionInterface) {
                abort(419);
            }
            abort_if(in_array(UseActiveTenant::class, $route->gatherMiddleware(), true)
                || in_array(UseClubWorkspace::class, $route->gatherMiddleware(), true), 419);
        });
    }
}
