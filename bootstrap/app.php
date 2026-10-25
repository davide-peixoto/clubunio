<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [\App\ClubUnio\Http\ProtectApiAdminResponse::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => ! $request->routeIs('clubunio.workspace.*') && $request->is('portal/*', 'planning/*') ? '/portal/login' : '/login');
        $middleware->trustProxies(at: ['192.168.2.105']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['value']);
        $exceptions->dontReportWhen(fn (\Throwable $error) => request()->is('api/v1', 'api/v1/*'));
        $exceptions->render(function (\Throwable $error, Request $request) {
            if ($request->is('api/v1', 'api/v1/*')) {
                return app(\App\ClubUnio\Api\ApiErrors::class)->render($error, $request);
            }
        });
    })->create();
