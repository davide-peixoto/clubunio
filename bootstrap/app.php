<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('portal/*', 'planning/*') ? '/portal/login' : '/login');
        $middleware->trustProxies(at: ['192.168.2.105']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['value']);
    })->create();
