<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\PlanningCalendarService;
use App\ClubUnio\Services\TeamPlanningService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;

/** Explicit SVS presentation DTOs over existing personal planning policies. */
final class MobilePlanningData
{
    public function __construct(private TeamPlanningService $planning, private MobilePlanningCapabilities $capabilities) {}

    private function page(Request $request): array
    {
        $d = $request->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100']);
        return [(int) ($d['start'] ?? 0), (int) ($d['recperpage'] ?? 20)];
    }

    public function absences(Request $request, Account $actor, Tenant $tenant): array
    {
        [$start, $limit] = $this->page($request);
        $d = $request->validate(['MitgliederID' => 'nullable|integer|min:1']);
        $q = $this->planning->absences($actor, $tenant);
        if (isset($d['MitgliederID'])) { $q->where('member_id', $d['MitgliederID']); }
        $total = (clone $q)->count();
        $rows = $this->absenceRows($actor, $tenant, $q->orderByDesc('starts_on')->orderBy('id')->offset($start)->limit($limit)->get());
        return ['success' => true, 'TeamAbsenzen' => $rows, 'totalRecordCount' => $total];
    }

    public function absence(Account $actor, Tenant $tenant, int $id): array
    {
        $p = $this->planning;
        $row = $p->absences($actor, $tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        return $this->absenceRows($actor, $tenant, collect([$row]))[0];
    }

    private function absenceRows(Account $actor, Tenant $tenant, \Illuminate\Support\Collection $rows): array
    {
        if ($rows->isEmpty()) { return []; }
        $p = $this->planning;
        $ids = $rows->pluck('member_id')->unique()->all();
        $names = $p->table($tenant, 'members')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $editable = $deletable = [];
        try {
            $editable = array_fill_keys($p->absenceMembers($actor, $tenant, 'update')->whereIn('clubora.members.id', $ids)->pluck('id')->all(), true);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) { throw $e; }
        }
        try {
            $p->authorizeOperation($actor, $tenant, 'deactivate');
            $access = app(MemberAccessService::class);
            $members = app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'planning', 'deactivate')
                ? $access->activeMembers($tenant) : $access->represented($actor, $tenant);
            $deletable = array_fill_keys($members->whereIn('clubora.members.id', $ids)->pluck('id')->all(), true);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) { throw $e; }
        }
        return $rows->map(function ($row) use ($names, $editable, $deletable) {
            $member = $names->get($row->member_id);
            return ['AbsenzID' => (int) $row->id, 'MitgliederID' => (int) $row->member_id,
                'Vorname' => $member?->first_name ?? '', 'Nachname' => $member?->last_name ?? '',
                'DatumVon' => $row->starts_at ?? \Carbon\CarbonImmutable::parse($row->starts_on, 'Europe/Zurich')->startOfDay()->toIso8601String(),
                'DatumBis' => $row->ends_at ?? \Carbon\CarbonImmutable::parse($row->ends_on, 'Europe/Zurich')->endOfDay()->toIso8601String(),
                'Absenzart' => $row->kind, 'Bemerkung' => $row->note_encrypted ? Crypt::decryptString($row->note_encrypted) : '',
                'Revision' => (int) $row->revision, 'CanEdit' => isset($editable[$row->member_id]), 'CanDelete' => isset($deletable[$row->member_id])];
        })->values()->all();
    }

    public function teams(Request $request, Account $actor, Tenant $tenant): array
    {
        [$start, $limit] = $this->page($request);
        $p = $this->planning;
        $query = $p->teams($actor, $tenant);
        $total = (clone $query)->count();
        $managed = $p->teams($actor, $tenant, true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $own = app(MemberAccessService::class)->represented($actor, $tenant)->get(['id', 'first_name', 'last_name']);
        $rows = $query->orderBy('id')->offset($start)->limit($limit)->get()->map(function ($team) use ($p, $tenant, $managed, $own, $actor) {
            $members = collect();
            foreach (['team_players', 'team_staff'] as $table) {
                $members = $members->merge($p->table($tenant, $table)->where('team_season_id', $team->id)->where('active', true)->pluck('member_id'));
            }
            return ['TeamSaisonID' => (int) $team->id, 'TeamSaisonLabel' => $team->label,
                'CanManage' => in_array((int) $team->id, $managed, true),
                'CanCreate' => $this->capabilities->allows($actor, $tenant, (int) $team->id, 'create'),
                'MeineMitglieder' => $own->whereIn('id', $members)->map(fn ($m) => ['MitgliederID' => (int) $m->id,
                    'Vorname' => $m->first_name, 'Nachname' => $m->last_name])->values()->all()];
        })->all();
        return ['success' => true, 'Teams' => $rows, 'totalRecordCount' => $total];
    }

    public function events(Request $request, Account $actor, Tenant $tenant): array
    {
        [$start, $limit] = $this->page($request);
        $d = $request->validate(['TeamSaisonID' => 'nullable|integer|min:1', 'MitgliederID' => 'nullable|integer|min:1',
            'Terminart' => 'nullable|string|max:40', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $query = app(PlanningCalendarService::class)->visible($actor, $tenant);
        if (isset($d['TeamSaisonID'])) { $query->where('team_season_id', $d['TeamSaisonID']); }
        if (isset($d['Terminart'])) { $query->where('kind', $d['Terminart']); }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (isset($d[$key])) { $query->where('starts_at', $operator, \Carbon\CarbonImmutable::parse($d[$key])->toIso8601String()); }
        }
        if (isset($d['MitgliederID'])) {
            $member = (int) $d['MitgliederID'];
            // Filter cannot widen event visibility; includes explicitly invited players.
            $query->where(function ($q) use ($tenant, $member) {
                $p = $this->planning;
                $q->whereIn('id', $p->table($tenant, 'team_invitations')->where('member_id', $member)->select('event_id'));
                foreach (['team_players', 'team_staff'] as $table) {
                    $q->orWhereIn('team_season_id', $p->table($tenant, $table)->where('member_id', $member)->where('active', true)->select('team_season_id'));
                }
            });
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('starts_at')->orderBy('id')->offset($start)->limit($limit)->get()
            ->map(fn ($event) => $this->event($actor, $tenant, (int) $event->id, false))->all();
        return ['success' => true, 'TeamTermine' => $rows, 'totalRecordCount' => $total];
    }

    public function event(Account $actor, Tenant $tenant, int $id, bool $seriesSnapshot = true): array
    {
        $p = $this->planning;
        $event = $p->event($actor, $tenant, $id);
        $manager = $p->teams($actor, $tenant, true)->where('id', $event->team_season_id)->exists();
        $own = app(MemberAccessService::class)->represented($actor, $tenant)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $parts = $p->table($tenant, 'team_participations')->where('event_id', $id)->get()->keyBy('member_id');
        $people = $p->roster($tenant, $event)->get(['id', 'first_name', 'last_name'])->map(function ($member) use ($parts, $event, $manager, $own) {
            $part = $parts->get($member->id);
            $private = $manager || in_array((int) $member->id, $own, true);
            return ['TeamTerminTeilnahmeID' => (int) ($part->id ?? 0), 'MitgliederID' => (int) $member->id,
                'Vorname' => $member->first_name, 'Nachname' => $member->last_name,
                'TeilnahmeStatus' => $part->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN'),
                'AnwesenheitsStatus' => $part->attendance ?? 'OFFEN', 'Revision' => (int) ($part->revision ?? 0),
                'Kommentar' => $private && ($part->comment_encrypted ?? null) ? Crypt::decryptString($part->comment_encrypted) : '',
                'UpdatedAt' => $part->updated_at ?? null,
                'AnwesenheitsKommentar' => $private && ($part->attendance_comment_encrypted ?? null) ? Crypt::decryptString($part->attendance_comment_encrypted) : '',
                'AnwesenheitUpdatedAt' => $part->attendance_updated_at ?? null];
        });
        $guests = $p->table($tenant, 'team_guests')->where('event_id', $id)->whereNull('deleted_at')->orderBy('id')->get()->map(fn ($guest) => [
            'GastID' => (int) $guest->id, 'Vorname' => $guest->first_name, 'Nachname' => $guest->last_name,
            'TeilnahmeStatus' => $guest->status, 'AnwesenheitsStatus' => $guest->attendance,
            'Revision' => (int) $guest->revision, 'Kommentar' => $manager && $guest->comment_encrypted ? Crypt::decryptString($guest->comment_encrypted) : '',
            'AnwesenheitsKommentar' => $manager && ($guest->attendance_comment_encrypted ?? null) ? Crypt::decryptString($guest->attendance_comment_encrypted) : '',
            'AnwesenheitUpdatedAt' => $guest->attendance_updated_at ?? null,
        ]);
        $all = $people->concat($guests)->values();
        $team = $p->table($tenant, 'team_seasons')->where('id', $event->team_season_id)->first();
        return ['TeamTerminID' => (int) $event->id, 'TeamSaisonID' => (int) $event->team_season_id,
            'TeamSaisonLabel' => $team->label, 'Titel' => $event->title, 'Terminart' => $event->kind,
            'DatumVon' => $event->starts_at, 'DatumBis' => $event->ends_at, 'AnmeldeFrist' => $event->deadline_at,
            'TeilnahmeModus' => $event->mode, 'Status' => $event->status, 'Revision' => (int) $event->revision,
            'Ort' => $event->location, 'Beschreibung' => $event->description, 'MaxTeilnehmer' => $event->capacity,
            ...($seriesSnapshot ? ['SeriesRevision' => $manager ? app(MobilePlanningSeries::class)->revision($tenant, $event) : null] : []),
            'SerieID' => $event->series_key, 'CanManage' => $manager,
            ...$this->capabilities->event($actor, $tenant, $event),
            'Teilnahmen' => $all->all(), 'MeineTeilnahmen' => $people->whereIn('MitgliederID', $own)->values()->all(),
            'DabeiCount' => $all->where('TeilnahmeStatus', 'DABEI')->count(),
            'AbwesendCount' => $all->where('TeilnahmeStatus', 'ABWESEND')->count(),
            'VielleichtCount' => $all->where('TeilnahmeStatus', 'VIELLEICHT')->count(),
            'OffenCount' => $all->where('TeilnahmeStatus', 'OFFEN')->count(),
            'AnwesendCount' => $all->where('AnwesenheitsStatus', 'ANWESEND')->count(),
            'OhneAbmeldungCount' => $all->where('AnwesenheitsStatus', 'FEHLT')->count(),
            'AbgemeldetCount' => $all->where('AnwesenheitsStatus', 'ENTSCHULDIGT')->count(),
            'AnwesenheitOffenCount' => $all->where('AnwesenheitsStatus', 'OFFEN')->count()];
    }
}
