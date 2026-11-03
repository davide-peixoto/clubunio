<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Services\ExpenseService;
use Illuminate\Http\Request;

/** Read-only list; native editing/upload activation remains separately gated. */
final class MobileExpenseIndex
{
    public function index(Request $request)
    {
        $d = $request->validate(['status' => 'nullable|in:O,F,Z,B,R', 'start' => 'nullable|integer|min:0|max:100000', 'limit' => 'nullable|integer|min:1|max:100']);
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        app(MobileAccess::class)->enter($actor, $tenant);
        $service = app(ExpenseService::class);
        $query = $service->visible($actor, $tenant)->when(isset($d['status']), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('code', $d['status'])));
        $total = (clone $query)->count();
        $rows = $query->with(['member', 'teamSeason.team', 'season', 'round', 'status'])
            ->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['limit'] ?? 20)->get()->map(fn ($e) => [
                'SpesenID' => (int) $e->id, 'Revision' => (int) $e->revision, 'MitgliederID' => (int) $e->member_id,
                'TeamID' => (int) ($e->teamSeason?->team_id ?? 0), 'TeamSaisonID' => (int) $e->team_season_id,
                'BankkontoStaffID' => (int) $e->bank_account_id, '_TeamLabel' => $e->teamSeason?->team?->name ?? '',
                '_ReceiptTitle' => $e->teamSeason?->label ?? '', 'Saison' => $e->season?->name ?? '',
                'Vorname' => $e->member?->first_name ?? '', 'Nachname' => $e->member?->last_name ?? '',
                'Status' => $e->status?->code ?? '', 'Runde' => $e->round?->code ?? '', 'Bemerkungen' => $e->notes ?? '',
                'CanManagePositions' => $service->editable($actor, $tenant, $e),
                'CanAddPosition' => $service->editable($actor, $tenant, $e, 'create'),
                'CanDeletePosition' => $service->editable($actor, $tenant, $e, 'deactivate'),
                'AllowedStatusCodes' => array_values(array_diff($service->targets($actor, $tenant, $e), ['B'])),
            ]);

        return response()->json(['data' => ['rows' => $rows, 'total' => $total]]);
    }
}
