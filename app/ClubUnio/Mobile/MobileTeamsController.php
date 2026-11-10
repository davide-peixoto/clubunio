<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Tenant, TeamPlayer, TeamStaff};
use App\ClubUnio\Services\{MemberAccessService, ModulePermissionService, TeamAssignmentService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileTeamsController
{
    public function index(Request $r) {
        return response()->json(['data' => app(MobileTeamsData::class)->summaries($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]);
    }
    public function show(Request $r) {
        return response()->json(['data' => app(MobileTeamsData::class)->details($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('team'))]);
    }
    public function trainings(Request $r) {
        return response()->json(['data' => app(MobileTeamsData::class)->trainings($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('team'))]);
    }
    public function registrations(Request $r) {
        return response()->json(['data'=>['Registrations'=>app(MobileTeamsData::class)->registrations($r->attributes->get('mobile.actor'),$r->attributes->get('mobile.tenant'),(int)$r->route('team'))]]);
    }
    public function registration(Request $r) {
        return response()->json(['data'=>['Registration'=>app(MobileTeamsData::class)->registration($r->attributes->get('mobile.actor'),$r->attributes->get('mobile.tenant'),(int)$r->route('team'),(int)$r->route('registration'))]]);
    }
    public function members(Request $r) {
        $actor = $r->attributes->get('mobile.actor'); $tenant = $r->attributes->get('mobile.tenant');
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'create');
        $d = $r->validate(['q' => 'nullable|string|max:120']);
        $q = trim($d['q'] ?? '');
        $contacts = app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view');
        $rows = app(MemberAccessService::class)->activeMembers($tenant)->when($q !== '', fn ($b) => $b->where(fn ($b) => $b->where('first_name', 'ilike', '%'.$q.'%')->orWhere('last_name', 'ilike', '%'.$q.'%')))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(20)->get(['id', 'first_name', 'last_name', ...($contacts ? ['email', 'mobile', 'postal_code', 'city'] : [])])
            ->map(fn ($m) => ['MitgliederID' => (int) $m->id, 'Vorname' => $m->first_name, 'Nachname' => $m->last_name]
                + ($contacts ? ['Email' => $m->email, 'Mobile' => $m->mobile, 'PLZ' => $m->postal_code, 'Ort' => $m->city] : []))->all();
        return response()->json(['data' => $rows]);
    }
    public function functions(Request $r) {
        $actor = $r->attributes->get('mobile.actor'); $tenant = $r->attributes->get('mobile.tenant');
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'teams', 'create');
        $d = $r->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100']);
        $q = app(MobileTeamsData::class)->table($tenant, 'staff_functions')->where('active', true);
        $total = (clone $q)->count();
        return response()->json(['data' => ['Functions' => $q->orderBy('name')->orderBy('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 100)->get(['id', 'name'])
            ->map(fn ($f) => ['TeamSatffFunktionID' => (int) $f->id, 'Bezeichnung' => $f->name])->all(), 'totalRecordCount' => $total]]);
    }
    public function assignment(Request $r) {
        return DB::connection('clubunio')->transaction(function () use ($r) {
            $tenant = $r->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            $team = (int) $r->route('team');
            $data = app(MobileTeamsData::class);
            $data->team($actor, $tenant, $team);
            $kind = $r->route('kind');
            $model = $kind === 'staff' ? TeamStaff::class : TeamPlayer::class;
            $id = $r->route('assignment') ? (int) $r->route('assignment') : null;
            $delete = $r->isMethod('DELETE');
            $d = $r->validate(['MitgliederID' => $delete ? 'prohibited' : 'required|integer|min:1',
                'TeamSatffFunktionID' => ! $delete && $kind === 'staff' ? 'required|integer|min:1' : 'prohibited',
                '_version' => $delete ? 'required|regex:/^[0-9a-f]{64}$/' : 'prohibited']);
            $old = $delete ? $model::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('team_season_id', $team)->where('active', true)->whereKey($id)->firstOrFail() : null;
            $input = ['team_season_id' => $team, 'member_id' => $old->member_id ?? $d['MitgliederID'], 'active' => ! $delete];
            if ($kind === 'staff') { $input['staff_function_id'] = $old->staff_function_id ?? $d['TeamSatffFunktionID'] ?? null; }
            if ($delete) { $input['_version'] = $d['_version']; }
            $row = app(TeamAssignmentService::class)->save($actor, $tenant, $model, $id, $input);
            return response()->json(['data' => $delete ? ['removed' => true, 'id' => (int) $row->id] : ['Assignment' => $data->assignment($row->refresh(), $kind)]]);
        });
    }
}
