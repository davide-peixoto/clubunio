<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\MemberKey;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\FinanceExportService;
use App\ClubUnio\Services\InvoiceAccessService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MembershipReportService;
use App\ClubUnio\Services\ReportExportService;
use Illuminate\Http\Request;

final class ApiReportController
{
    public const REPORTS = ['members' => 'members', 'passive' => 'members', 'unassigned' => 'members',
        'players' => 'teams', 'staff' => 'teams', 'families' => 'families', 'keys' => 'members'];

    public function index(Request $request, string $kind)
    {
        /** @var ApiContext $c */
        $c = $request->attributes->get('clubunio.api.context');
        $module = self::REPORTS[$kind] ?? abort(404);
        $c->authorize('reports:read', 'reports', 'view');
        $c->authorize($module.':read', $module, 'view');
        $input = $this->input($request);
        $query = $this->query($c, $kind, $input['season_id'] ?? null);
        $fields = match ($kind) {
            'members', 'passive', 'unassigned' => ['id', 'member_number', 'first_name', 'last_name', 'company', 'status_id', 'type_id'],
            'players' => ['id', 'member_id', 'team_season_id'], 'staff' => ['id', 'member_id', 'team_season_id', 'staff_function_id'],
            'families' => ['id', 'member_id', 'family_id'], 'keys' => ['id', 'member_id'],
        };
        $perPage = $input['per_page'] ?? 30;
        $page = $input['page'] ?? 1;
        $rows = $query->select($fields)->orderBy('id')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

        return response()->json(['data' => $rows->take($perPage)->map(fn ($row) => $row->only($fields))->values(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $rows->count() > $perPage]]);
    }

    public function export(Request $request, string $kind)
    {
        $c = $request->attributes->get('clubunio.api.context');
        if (in_array($kind, ['invoices', 'payments'], true)) {
            $module = $kind === 'invoices' ? 'invoices' : 'expenses';
            $c->authorize($module.':export', $module, 'export');
            $c->authorize($module.':read', $module, 'view');
            $input = $this->input($request);
            $query = $kind === 'invoices' ? app(InvoiceAccessService::class)->visible($c->actor, $c->tenant)
                : app(ExpenseService::class)->visible($c->actor, $c->tenant);
            if (isset($input['season_id'])) {
                $query->where('season_id', $input['season_id']);
            }

            return app(FinanceExportService::class)->download($c->actor, $c->tenant, $kind, $query);
        }
        $module = self::REPORTS[$kind] ?? abort(404);
        $c->authorize('reports:export', 'reports', 'export');
        $c->authorize($module.':read', $module, 'view');
        $input = $this->input($request);
        $query = $this->query($c, $kind, $input['season_id'] ?? null);

        return app(ReportExportService::class)->download($c->actor, $c->tenant,
            $kind === 'members' ? 'member_reports' : $kind, $query, $input['season_id'] ?? null);
    }

    private function input(Request $request): array
    {
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page', 'season_id']), 422);

        return $request->validate(['page' => 'sometimes|integer|min:1|max:10000',
            'per_page' => 'sometimes|integer|min:1|max:100', 'season_id' => 'sometimes|integer|min:1']);
    }

    private function query(ApiContext $c, string $kind, ?int $season)
    {
        $reports = app(MembershipReportService::class);
        if ($kind === 'members') {
            return $reports->members($c->actor, $c->tenant);
        }
        if ($kind === 'passive') {
            return $reports->passive($c->actor, $c->tenant);
        }
        if ($kind === 'unassigned') {
            return $reports->withoutTeam($reports->teamCandidates($c->actor, $c->tenant), $c->tenant, $season);
        }
        $model = match ($kind) {
            'players' => TeamPlayer::class, 'staff' => TeamStaff::class, 'families' => FamilyMember::class, 'keys' => MemberKey::class
        };
        $query = $model::withoutGlobalScopes()->where('tenant_id', $c->tenant->id)->where('active', true)
            ->whereIn('member_id', app(MemberAccessService::class)->activeMembers($c->tenant)->select('clubora.members.id'));
        if ($season && in_array($kind, ['players', 'staff'], true)) {
            $query->whereHas('teamSeason', fn ($q) => $q->where('tenant_id', $c->tenant->id)->where('season_id', $season));
        }

        return $query;
    }
}
