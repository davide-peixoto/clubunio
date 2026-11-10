<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ExpenseService, MemberAccessService, ModulePermissionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only options shared with the separately gated native expense editor. */
final class MobileExpenseOptions
{
    public function index(Request $request)
    {
        if (config('clubunio-mobile.native_expenses_enabled', false)) {
            return app(MobileExpenseController::class)->options($request);
        }
        return response()->json(['data' => $this->read($request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }

    public function read(Account $actor, Tenant $tenant): array
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        abort_unless(app(ExpenseService::class)->portalAllowed($actor, $tenant)
            || app(ModulePermissionService::class)->allows($actor, $tenant, 'expenses', 'view'), 403);
        $db = DB::connection('clubunio');
        $own = app(MemberAccessService::class)->represented($actor, $tenant, true)->get(['id', 'first_name', 'last_name']);
        $teams = $db->table('clubunio.team_seasons as ts')->where('ts.tenant_id', $tenant->id)->where('ts.active', true)
            ->whereIn('ts.id', $db->table('clubunio.team_staff')->where('tenant_id', $tenant->id)->where('active', true)
                ->whereIn('member_id', $own->pluck('id'))->select('team_season_id'))
            ->join('clubunio.teams as t', fn ($j) => $j->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'))->where('t.active', true);
        app(MobileSeasonScope::class)->teams($teams, $tenant, 'ts.season_id');
        $season = app(MobileSeasonScope::class)->current($tenant);
        $catalog = fn ($table) => $db->table('clubunio.'.$table)->where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->orderBy('id');
        $teamRows = $teams->orderBy('ts.label')->orderBy('ts.id')->get(['ts.id', 'ts.label', 'ts.team_id', 't.laundry_allowance']);

        return ['members' => $own->map(fn ($m) => ['id' => (int) $m->id, 'name' => trim($m->first_name.' '.$m->last_name)])->all(),
            'seasons' => $season ? [['id' => (int) $season->id, 'label' => $season->name]] : [],
            'teams' => $teamRows
                ->map(fn ($t) => ['id' => (int) $t->id, 'label' => $t->label, 'team_id' => (int) $t->team_id, 'laundry' => $t->laundry_allowance])->all(),
            'rounds' => $catalog('expense_rounds')->get(['id', 'code', 'name'])->all(),
            'types' => $catalog('expense_types')->get(['id', 'name', 'receipt_required', 'amount_source'])->all(),
            'game_types' => $catalog('game_types')->get(['id', 'code', 'name'])->all(),
            'institutions' => $catalog('banks')->get(['id', 'name'])->all(),
            'capabilities' => ['upload' => (bool) config('clubunio-mobile.uploads_enabled'), 'ai' => false,
                'create' => $own->isNotEmpty() && $teamRows->isNotEmpty()]];
    }
}
