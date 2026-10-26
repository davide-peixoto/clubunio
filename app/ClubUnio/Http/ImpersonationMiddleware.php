<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationMiddleware
{
    public function safeRoute(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $name = $request->route()?->getName() ?? '';
        $record = $request->route('record');
        if ($record !== null && ! ctype_digit((string) $record)) {
            return false;
        }
        if (in_array($name, ['filament.clubunio.pages.dashboard', 'clubunio.portal.members',
            'clubunio.portal.invoices', 'clubunio.portal.invoice', 'clubunio.portal.expenses',
            'clubunio.portal.expense', 'clubunio.portal.banks', 'clubunio.portal.announcements',
            'clubunio.portal.announcement'], true)) {
            return true;
        }
        // Explicit resource allowlist: never expose credentials, access profiles or provider actions.
        return (bool) preg_match('/^filament\.clubunio\.resources\.(members|invoices|expenses|seasons|teams|team-seasons|team-players|team-staff|registrations|competitions|training-slots|field-availabilities|families|club-events|customer-credits|credit-notes|refunds)\.(index|view)$/D', $name);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(ImpersonationService::KEY)
            || $request->routeIs('clubunio.support.stop')) {
            return $next($request);
        }
        $service = app(ImpersonationService::class);
        $session = $service->resolve($request);
        $tenant = Tenant::findOrFail($session->tenant_id);
        $routeTenant = $request->route('tenant');
        $sameTenant = ! $routeTenant || ($routeTenant instanceof Tenant ? $routeTenant->is($tenant)
            : in_array((string) $routeTenant, [(string) $tenant->id, $tenant->slug], true));
        $allowed = $sameTenant && $this->safeRoute($request);
        $service->audit($session->tenant_id, $session->actor_id, $session->target_id, $allowed ? 'read' : 'blocked',
            ['route' => $request->route()?->getName(), 'method' => $request->method()], $session->id);
        $target = Account::findOrFail($session->target_id);
        $banner = view('clubunio.support.banner', ['target' => $target, 'expires' => $session->expires_at])->render();
        if (! $allowed) {
            return response($banner.'<main><h1>Nur-Lesen-Simulation</h1><p>Diese Aktion ist in der Simulation gesperrt.</p></main>', 403)
                ->header('Cache-Control', 'private, no-store');
        }
        $request->attributes->set('clubunio_impersonation', $session);
        $originals = [];
        foreach (['clubunio', 'portal'] as $guard) {
            $originals[$guard] = Auth::guard($guard)->user();
            Auth::guard($guard)->setUser($target);
        }
        try {
            $response = $next($request);
            if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                $html = $response->getContent();
                $response->setContent(preg_match('/<body\b[^>]*>/i', $html)
                    ? preg_replace_callback('/<body\b[^>]*>/i', fn ($match) => $match[0].$banner, $html, 1) : $banner.$html);
            }
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-ClubUnio-Impersonation', 'read-only');
            return $response;
        } finally {
            foreach ($originals as $guard => $original) {
                $original ? Auth::guard($guard)->setUser($original) : Auth::guard($guard)->forgetUser();
            }
            $request->attributes->remove('clubunio_impersonation');
        }
    }
}
