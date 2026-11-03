<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{FieldAvailability, Tenant};
use App\ClubUnio\Services\{FacilityService, ModulePermissionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileFacilitiesController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileFacilitiesReady::class]; }
    public function index(Request $request)
    {
        return response()->json(['data' => app(MobileFacilitiesData::class)->read($request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }
    public function update(Request $request)
    {
        return DB::connection('clubunio')->transaction(function () use ($request) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'field_availability', 'update');
            $d = $request->validate(['revision' => 'required|integer|min:1', 'status_id' => 'required|integer|min:1', 'status_revision' => 'required|integer|min:1']);
            $record = FieldAvailability::where('tenant_id', $tenant->id)->where('active', true)->findOrFail((int) $request->route('field'));
            abort_unless(DB::connection('clubunio')->table('clubora.facility_statuses')->where('tenant_id', $tenant->id)->where('active', true)
                ->where('id', $d['status_id'])->where('revision', $d['status_revision'])->exists(), 409, 'Statusauswahl wurde geaendert.');
            app(FacilityService::class)->save($actor, $tenant, FieldAvailability::class, (int) $record->id,
                ['revision' => $d['revision'], 'field_id' => $record->field_id, 'active' => (bool) $record->active, 'status_id' => $d['status_id'], 'note' => $record->note]);
            return response()->json(['data' => app(MobileFacilitiesData::class)->read($actor, $tenant)]);
        });
    }
}
