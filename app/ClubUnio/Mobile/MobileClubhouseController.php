<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ClubhouseAccess;
use App\ClubUnio\Services\ClubhouseHours;
use App\ClubUnio\Services\ClubhouseMenus;
use App\ClubUnio\Services\ClubhouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileClubhouseController
{
    public function index(Request $request)
    {
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        app(ClubhouseService::class)->view($actor, $tenant);

        return response()->json(['data' => app(ClubhouseService::class)->overview($tenant, 'app')
            + ['capabilities' => app(ClubhouseAccess::class)->capabilities($actor, $tenant)]]);
    }

    public function manage(Request $request)
    {
        return response()->json(['data' => app(ClubhouseService::class)->management(
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'), $request->only(['page', 'status', 'search']))]);
    }

    private function write(Request $request, callable $operation)
    {
        return DB::connection('clubunio')->transaction(function () use ($request, $operation) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')
                ->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);

            return response()->json(['data' => $operation($actor, $tenant)]);
        });
    }

    public function opening(Request $request)
    {
        return $this->write($request, fn ($a, $t) => ['id' => app(ClubhouseHours::class)->save($a, $t,
            $request->route('opening') ? (int) $request->route('opening') : null, $request->all())]);
    }

    public function exception(Request $request)
    {
        return $this->write($request, fn ($a, $t) => ['id' => app(ClubhouseHours::class)->saveException($a, $t, $request->all())]);
    }

    public function removeException(Request $request)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return $this->write($request, function ($a, $t) use ($request, $data) {
            app(ClubhouseHours::class)->removeException($a, $t, (int) $request->route('exception'), $data['revision']);

            return ['removed' => true];
        });
    }

    public function menu(Request $request)
    {
        return $this->write($request, fn ($a, $t) => ['id' => app(ClubhouseMenus::class)->save($a, $t,
            $request->route('menu') ? (int) $request->route('menu') : null, $request->all())]);
    }

    public function transition(Request $request)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return $this->write($request, fn ($a, $t) => app(ClubhouseMenus::class)->transition($a, $t,
            (int) $request->route('menu'), $data['revision'], $request->route('operation')));
    }

    public function upload(Request $request)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'file' => 'required|file|max:10240']);

        return $this->write($request, fn ($a, $t) => app(ClubhouseMenus::class)->upload($a, $t,
            (int) $request->route('menu'), $data['revision'], $data['file']));
    }

    public function removeDocument(Request $request)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return $this->write($request, fn ($a, $t) => app(ClubhouseMenus::class)->removeDocument($a, $t,
            (int) $request->route('menu'), $data['revision']));
    }

    public function document(Request $request)
    {
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        $id = (int) $request->route('menu');
        app(ClubhouseService::class)->view($actor, $tenant);
        $bytes = $request->boolean('draft') ? app(ClubhouseMenus::class)->document($actor, $tenant, $id)
            : app(ClubhouseMenus::class)->publishedDocument($tenant, $id, 'app');

        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="menu-'.$id.'.pdf"');
    }
}
