<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ClubhouseAccess;
use App\ClubUnio\Services\ClubhouseMenus;
use App\ClubUnio\Services\ClubhouseService;
use App\ClubUnio\Services\LanguagePreferences;
use App\ClubUnio\Services\TenantDomainService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

final class ClubhouseController
{
    private function publicTenant(Request $request, Tenant $tenant): void
    {
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_if($bound && ! $bound->is($tenant), 404);
        abort_unless(app(ClubhouseService::class)->publicEnabled($tenant), 404);
        $locale = $request->query('lang');
        abort_if($locale !== null && (! is_string($locale) || ! array_key_exists($locale, config('clubunio-languages.enabled'))), 422);
        app()->setLocale(app(LanguagePreferences::class)->resolve($locale, $tenant->default_locale));
    }

    public function public(Request $request, Tenant $tenant)
    {
        $this->publicTenant($request, $tenant);

        return response()->view('clubunio.clubhouse.public', ['tenant' => $tenant,
            'overview' => app(ClubhouseService::class)->overview($tenant, 'web')])
            ->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function data(Request $request, Tenant $tenant)
    {
        $this->publicTenant($request, $tenant);
        $overview = app(ClubhouseService::class)->overview($tenant, 'web');
        foreach ($overview['menus'] as &$menu) {
            $menu['document_url'] = $menu['has_document'] ? route('clubunio.clubhouse.public.pdf', ['tenant' => $tenant->slug, 'menu' => $menu['id']]) : null;
        }

        return response()->json(['data' => $overview])->header('Cache-Control', 'no-store')
            ->header('Access-Control-Allow-Origin', '*')->header('X-Content-Type-Options', 'nosniff');
    }

    public function publicDocument(Request $request, Tenant $tenant, int $menu)
    {
        $this->publicTenant($request, $tenant);

        return $this->pdf(app(ClubhouseMenus::class)->publishedDocument($tenant, $menu, 'web'), $menu);
    }

    public function document(Request $request, int $menu)
    {
        $tenant = Filament::getTenant();

        app(ClubhouseAccess::class)->authorize($request->user('clubunio'), $tenant, 'view');
        $channel = $request->query('channel');
        abort_if($channel !== null && ! in_array($channel, ['web', 'app'], true), 422);

        return $this->pdf($channel ? app(ClubhouseMenus::class)->publishedDocument($tenant, $menu, $channel)
            : app(ClubhouseMenus::class)->document($request->user('clubunio'), $tenant, $menu), $menu);
    }

    public function portal(Request $request, Tenant $tenant)
    {
        $actor = $request->user('portal');
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_if($bound && ! $bound->is($tenant), 404);
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'view');
        app()->setLocale(app(LanguagePreferences::class)->resolve($actor->preferred_locale,
            $request->session()->get('clubunio.locale'), $tenant->default_locale));

        return response()->view('clubunio.clubhouse.portal', ['tenant' => $tenant, 'actor' => $actor,
            'tenants' => collect([$tenant]), 'portalTitle' => trans('clubhouse.title'), 'portalModule' => 'clubhouse'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function portalDocument(Request $request, Tenant $tenant, int $menu)
    {
        $bound = app(TenantDomainService::class)->resolve($request);
        abort_if($bound && ! $bound->is($tenant), 404);

        app(ClubhouseAccess::class)->authorize($request->user('portal'), $tenant, 'view');
        $channel = $request->query('channel');
        abort_if($channel !== null && ! in_array($channel, ['web', 'app'], true), 422);

        return $this->pdf($channel ? app(ClubhouseMenus::class)->publishedDocument($tenant, $menu, $channel)
            : app(ClubhouseMenus::class)->document($request->user('portal'), $tenant, $menu), $menu);
    }

    private function pdf(string $bytes, int $id)
    {
        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="menu-'.$id.'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
