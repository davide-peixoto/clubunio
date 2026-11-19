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
        $middleware->trimStrings(except: ['account_password', 'operator_password']);
        $middleware->web(append: [\App\ClubUnio\Http\UseLanguage::class, \App\ClubUnio\Http\ProtectApiAdminResponse::class, \App\ClubUnio\Http\RequireStrongWebSession::class, \App\ClubUnio\Http\ImpersonationMiddleware::class]);
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\ClubUnio\Http\ImpersonationMiddleware::class);
        $middleware->prependToPriorityList(\App\ClubUnio\Http\ImpersonationMiddleware::class, \App\ClubUnio\Http\RequireStrongWebSession::class);
        $middleware->redirectGuestsTo(fn (Request $request) => ! $request->routeIs('clubunio.workspace.*') && $request->is('portal/*', 'planning/*') ? '/portal/login' : '/login');
        $middleware->trustProxies(at: ['192.168.2.105']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['value', 'text', 'password', 'account_password', 'operator_password', 'storage_key', 'ahv', 'invitation', 'code', 'confirmation', 'proof', 'proof_hash', 'client', 'authenticator', 'signature', 'attestation']);
        $exceptions->dontReportWhen(fn (\Throwable $error) => request()->is('api/v1', 'api/v1/*', 'api/mobile/*', 'chat-data/*', 'account/security/*'));
        $exceptions->render(function (\Throwable $error, Request $request) {
            if ($request->is('api/v1', 'api/v1/*', 'api/mobile/*', 'chat-data/*', 'account/security/*')) {
                return app(\App\ClubUnio\Api\ApiErrors::class)->render($error, $request);
            }
        });
    })->create();
