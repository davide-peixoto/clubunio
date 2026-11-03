<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, PlanningCalendarService, TeamPlanningService};
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobilePlanningStatistics
{
    public function read(Request $request, Account $actor, Tenant $tenant): array
    {
        $p = app(TeamPlanningService::class);
        $p->enter($actor, $tenant);
        $d = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from',
            'TeamSaisonID' => 'nullable|integer|min:1', 'start' => 'nullable|integer|min:0|max:100000',
            'recperpage' => 'nullable|integer|min:1|max:100']);
        $now = Time::now('Europe/Zurich');
        $from = Time::parse($d['from'], 'Europe/Zurich')->setTimezone('Europe/Zurich')->startOfDay();
        $to = Time::parse($d['to'], 'Europe/Zurich')->setTimezone('Europe/Zurich')->startOfDay()->min($now->startOfDay());
        abort_if($from->greaterThan($to), 422, 'Ungueltiger Statistikzeitraum.');
        // Only completed, visible, active events. Removal scope comes from the shared service.
        $events = app(PlanningCalendarService::class)->visible($actor, $tenant)
            ->where('status', 'AKTIV')->where('ends_at', '<=', $now->toIso8601String())
            ->where('starts_at', '>=', $from->toIso8601String())->where('starts_at', '<', $to->addDay()->toIso8601String())
            ->when(isset($d['TeamSaisonID']), fn ($q) => $q->where('team_season_id', $d['TeamSaisonID']))
            ->select(['id', 'team_season_id', 'kind', 'mode']);
        $db = DB::connection('clubunio');
        // UNION deduplicates current players and historical responses. Guests never enter it.
        $historical = $db->query()->fromSub($events, 'ev')->joinSub($p->table($tenant, 'team_participations'), 'pa', 'pa.event_id', '=', 'ev.id')
            ->select(['ev.team_season_id', 'pa.member_id']);
        $playerTeams = $p->table($tenant, 'team_players')->where('active', true)->select(['team_season_id', 'member_id'])->union($historical);
        $own = app(MemberAccessService::class)->represented($actor, $tenant)->select('clubora.members.id');
        $eligible = $db->query()->fromSub($playerTeams, 'pt')->whereIn('pt.team_season_id', (clone $events)->select('team_season_id'))
            ->where(fn ($q) => $q->whereIn('pt.team_season_id', $p->teams($actor, $tenant, true)->select('id'))->orWhereIn('pt.member_id', $own))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.team_staff as staff')->where('staff.tenant_id', $tenant->id)
                ->where('staff.active', true)->whereColumn('staff.team_season_id', 'pt.team_season_id')->whereColumn('staff.member_id', 'pt.member_id'));
        $members = $p->table($tenant, 'members')->whereIn('id', (clone $eligible)->select('pt.member_id'));
        $total = (clone $members)->count();
        $rows = $members->orderBy('last_name')->orderBy('first_name')->orderBy('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)
            ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($m) => [(int) $m->id => ['MitgliederID' => (int) $m->id,
                'Vorname' => $m->first_name, 'Nachname' => $m->last_name, 'Kategorien' => []]])->all();
        if ($rows) {
            $counts = $db->query()->fromSub($eligible, 'el')->joinSub($events, 'ev', 'ev.team_season_id', '=', 'el.team_season_id')
                ->leftJoinSub($p->table($tenant, 'team_participations'), 'pa', fn ($j) => $j->on('pa.event_id', '=', 'ev.id')->on('pa.member_id', '=', 'el.member_id'))
                ->leftJoinSub($p->table($tenant, 'team_players')->where('active', true)->select(['team_season_id', 'member_id'])->distinct(), 'ro',
                    fn ($j) => $j->on('ro.team_season_id', '=', 'el.team_season_id')->on('ro.member_id', '=', 'el.member_id'))
                ->whereIn('el.member_id', array_keys($rows))->select(['el.member_id', 'ev.kind']);
            $counts->selectRaw("SUM(CASE WHEN pa.status = 'DABEI' OR (pa.member_id IS NULL AND ev.mode = 'ABMELDEN' AND ro.member_id IS NOT NULL) THEN 1 ELSE 0 END) AS \"Zusagen\"");
            $fields = ['Absagen' => ['status', 'ABWESEND'], 'Anwesend' => ['attendance', 'ANWESEND'],
                'Gefehlt' => ['attendance', 'FEHLT'], 'Entschuldigt' => ['attendance', 'ENTSCHULDIGT']];
            foreach ($fields as $key => [$column, $value]) { $counts->selectRaw('SUM(CASE WHEN pa.'.$column.' = ? THEN 1 ELSE 0 END) AS "'.$key.'"', [$value]); }
            foreach ($counts->groupBy('el.member_id', 'ev.kind')->get() as $count) {
                $values = ['Zusagen' => (int) $count->Zusagen];
                foreach (array_keys($fields) as $key) { $values[$key] = (int) $count->{$key}; }
                $rows[$count->member_id]['Kategorien'][$count->kind] = $values;
            }
        }
        return ['success' => true, 'SpielerStatistiken' => array_values($rows), 'totalRecordCount' => $total,
            'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
