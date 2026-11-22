<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\EventCheckinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileEventCheckinController
{
    public function context(Request $r) { return $this->run($r, fn () => app(EventCheckinService::class)->context($r->attributes->get('mobile.actor'), (string)$r->route('token'))); }
    public function show(Request $r) { return $this->run($r, fn () => app(EventCheckinService::class)->show($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (string)$r->route('token'))); }
    public function confirm(Request $r)
    {
        return $this->run($r, fn () => DB::connection('clubunio')->transaction(function () use ($r) {
            $tenant = $r->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            return app(EventCheckinService::class)->confirm($actor, $tenant, (string)$r->route('token'), $r->all());
        }));
    }
    private function run(Request $request, callable $operation)
    {
        $languages = app(\App\ClubUnio\Services\LanguagePreferences::class);
        $locale = $languages->resolve($request->attributes->get('mobile.actor')?->preferred_locale,
            $request->getPreferredLanguage(array_keys($languages->enabled())), $request->attributes->get('mobile.tenant')?->default_locale);
        app()->setLocale($locale);
        try { $response = response()->json(['data' => $operation()]); }
        catch (\Throwable $error) {
            $response = app(\App\ClubUnio\Api\ApiErrors::class)->render($error, $request);
            $data = $response->getData(true); $data['error']['message'] = __($data['error']['message']);
            $response->setData($data);
        }
        return $response->header('Cache-Control', 'private, no-store')->header('Content-Language', $locale);
    }
}
