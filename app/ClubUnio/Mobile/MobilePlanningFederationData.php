<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explicit presentation contract for the existing SVS matchcenter. No provider request. */
final class MobilePlanningFederationData
{
    public function query(Account $actor, Tenant $tenant)
    {
        $p = app(TeamPlanningService::class);
        $teams = $p->teams($actor, $tenant, true)->select(['id', 'team_id', 'label']);
        return DB::connection('clubunio')->query()->fromSub($teams, 'ts')
            ->join('clubunio.teams as t', fn ($j) => $j->on('t.id', '=', 'ts.team_id')->where('t.tenant_id', $tenant->id)->where('t.active', true))
            ->join('clubunio.sfv_schedule as sfv', fn ($j) => $j->where('sfv.tenant_id', $tenant->id)
                ->on(fn ($j) => $j->on('sfv.team_a_id', '=', 't.sfv_team_id')->orOn('sfv.team_b_id', '=', 't.sfv_team_id')))
            // Retain removed matches in the join, so they cannot become eligible new imports.
            ->leftJoinSub($p->table($tenant, 'team_events', true), 'event', fn ($j) => $j->on('event.team_season_id', '=', 'ts.id')->on('event.sfv_match_id', '=', 'sfv.match_id'))
            ->select(['ts.id as team_id', 'ts.label as team_label', 'sfv.id as schedule_id', 'sfv.match_id', 'sfv.revision as sfv_revision',
                'sfv.title', 'sfv.host_name', 'sfv.guest_name', 'sfv.starts_at', 'sfv.location', 'sfv.match_type', 'sfv.state',
                'event.id as event_id', 'event.revision as event_revision', 'event.deleted_at as event_deleted_at']);
    }

    public function index(Request $request, Account $actor, Tenant $tenant): array
    {
        $d = $request->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100',
            'TeamSaisonID' => 'nullable|integer|min:1', 'accepted' => 'nullable|boolean', 'includeOlder' => 'nullable|boolean', 'from' => 'nullable|date']);
        $query = $this->query($actor, $tenant);
        if (isset($d['TeamSaisonID'])) { $query->where('ts.id', $d['TeamSaisonID']); }
        if (isset($d['from'])) { $query->where('sfv.starts_at', '>=', Time::parse($d['from'], 'Europe/Zurich')->toIso8601String()); }
        elseif (! ($d['includeOlder'] ?? false)) { $query->where('sfv.starts_at', '>=', now()->toIso8601String()); }
        if (isset($d['accepted'])) {
            if ($d['accepted']) { $query->whereNotNull('event.id')->whereNull('event.deleted_at'); }
            else { $query->where(fn ($q) => $q->whereNull('event.id')->orWhereNotNull('event.deleted_at')); }
        }
        $total = (clone $query)->count();
        $p = app(TeamPlanningService::class);
        $sync = $p->teams($actor, $tenant, true, 'sync')->pluck('id')->all();
        $create = $p->teams($actor, $tenant, true, 'create')->pluck('id')->all();
        $update = $p->teams($actor, $tenant, true, 'update')->pluck('id')->all();
        $rows = $query->orderByRaw('sfv.starts_at ASC NULLS LAST')->orderBy('sfv.match_id')->orderBy('ts.id')
            ->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get()->map(fn ($row) => [
                'matchId' => (int) $row->match_id, 'TeamSaisonID' => (int) $row->team_id, 'TeamSaisonLabel' => $row->team_label,
                'SFVRevision' => (int) $row->sfv_revision, 'EventRevision' => (int) ($row->event_revision ?? 0),
                'teamNameA' => $row->host_name ?? $row->title, 'teamNameB' => $row->guest_name ?? '',
                'matchDate' => $row->starts_at, 'stadiumPlaygroundName' => $row->location ?? '',
                'matchTypeName' => $row->match_type ?? '', 'matchStateName' => $row->state ?? '',
                'Accepted' => $row->event_id !== null && $row->event_deleted_at === null, 'Removed' => $row->event_deleted_at !== null,
                'CanAccept' => $row->starts_at !== null && $row->event_deleted_at === null
                    && in_array((int) $row->team_id, $sync, true)
                    && in_array((int) $row->team_id, $row->event_id ? $update : $create, true),
            ])->all();
        return ['success' => true, 'Verbandsspiele' => $rows, 'totalRecordCount' => $total];
    }
}
