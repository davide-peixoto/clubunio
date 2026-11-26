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
            $editable = array_fill_keys($p->absenceMembers($actor, $tenant, 'update')->whereIn('clubunio.members.id', $ids)->pluck('id')->all(), true);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) { throw $e; }
        }
        try {
            $p->authorizeOperation($actor, $tenant, 'deactivate');
            $access = app(MemberAccessService::class);
            $members = app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'planning', 'deactivate')
                ? $access->activeMembers($tenant) : $access->represented($actor, $tenant);
            $deletable = array_fill_keys($members->whereIn('clubunio.members.id', $ids)->pluck('id')->all(), true);
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
        $page = $query->orderBy('id')->offset($start)->limit($limit)->get();
        $members = [];
        foreach (['team_players', 'team_staff'] as $table) {
            foreach ($p->table($tenant, $table)->whereIn('team_season_id', $page->pluck('id'))->where('active', true)->get(['team_season_id','member_id']) as $link) {
                $members[$link->team_season_id][] = $link->member_id;
            }
        }
        $rows = $page->map(function ($team) use ($tenant, $managed, $own, $actor, $members) {
            return ['TeamSaisonID' => (int) $team->id, 'TeamSaisonLabel' => $team->label,
                'CanManage' => in_array((int) $team->id, $managed, true),
                'CanCreate' => $this->capabilities->allows($actor, $tenant, (int) $team->id, 'create'),
                'MeineMitglieder' => $own->whereIn('id', $members[$team->id] ?? [])->map(fn ($m) => ['MitgliederID' => (int) $m->id,
                    'Vorname' => $m->first_name, 'Nachname' => $m->last_name])->values()->all()];
        })->all();
        return ['success' => true, 'Teams' => $rows, 'totalRecordCount' => $total];
    }

    public function events(Request $request, Account $actor, Tenant $tenant): array
    {
        [$start, $limit] = $this->page($request);
        $d = $request->validate(['TeamSaisonID' => 'nullable|integer|min:1', 'MitgliederID' => 'nullable|integer|min:1',
            'Terminart' => 'nullable|string|max:40', 'from' => 'nullable|date', 'to' => 'nullable|date', 'active_only' => 'nullable|boolean']);
        $query = app(PlanningCalendarService::class)->visible($actor, $tenant);
        if ($d['active_only'] ?? false) { $query->where('status', 'AKTIV'); }
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
        $page = $query->orderBy('starts_at')->orderBy('id')->offset($start)->limit($limit)->get();
        $rows = $this->eventRows($actor, $tenant, $page);
        return ['success' => true, 'TeamTermine' => $rows, 'totalRecordCount' => $total];
    }

    /** Only rows from PlanningCalendarService::visible enter this method. */
    private function eventRows(Account $actor, Tenant $tenant, \Illuminate\Support\Collection $events): array
    {
        if ($events->isEmpty()) return [];
        $p = $this->planning; $eventIds = $events->pluck('id'); $teamIds = $events->pluck('team_season_id')->unique();
        $managed = array_fill_keys($p->teams($actor, $tenant, true)->whereIn('id', $teamIds)->pluck('id')->all(), true);
        $own = app(MemberAccessService::class)->represented($actor, $tenant)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $parts = $p->table($tenant, 'team_participations')->whereIn('event_id', $eventIds)->get()->groupBy('event_id');
        $guests = $p->table($tenant, 'team_guests')->whereIn('event_id', $eventIds)->whereNull('deleted_at')->orderBy('id')->get()->groupBy('event_id');
        $invited = $p->table($tenant, 'team_invitations')->whereIn('event_id', $eventIds)->get(['event_id','member_id'])->groupBy('event_id');
        $links = [];
        foreach (['team_players','team_staff'] as $table) {
            $links[$table] = $p->table($tenant, $table)->whereIn('team_season_id', $teamIds)->where('active', true)
                ->get(['team_season_id','member_id'])->groupBy('team_season_id');
        }
        $memberIds = $invited->flatten(1)->pluck('member_id');
        foreach ($links as $byTeam) $memberIds = $memberIds->merge($byTeam->flatten(1)->pluck('member_id'));
        $members = app(MemberAccessService::class)->activeMembers($tenant)->whereKey($memberIds->unique())
            ->orderBy('last_name')->orderBy('first_name')->get(['id','first_name','last_name']);
        $teams = $p->table($tenant, 'team_seasons')->whereIn('id', $teamIds)->get()->keyBy('id');
        $facts = [];
        foreach ($events as $event) {
            $players = ($links['team_players'][$event->team_season_id] ?? collect())->pluck('member_id')->all();
            $staff = ($links['team_staff'][$event->team_season_id] ?? collect())->pluck('member_id')->all();
            $ids = array_merge($players, $staff, ($invited[$event->id] ?? collect())->pluck('member_id')->all());
            $facts[(int) $event->id] = ['manager' => isset($managed[$event->team_season_id]), 'own' => $own,
                'parts' => ($parts[$event->id] ?? collect())->keyBy('member_id'), 'guests' => $guests[$event->id] ?? collect(),
                'roster' => $members->whereIn('id', $ids)->values(), 'staff' => $staff, 'players' => $players,
                'team' => $teams[$event->team_season_id]];
        }
        $metadata = app(MobilePlanningInvitationsData::class)->metadataBatch($actor, $tenant, $events, $this->capabilities, $invited, $facts);
        return $events->map(fn ($event) => $this->renderEvent($actor, $tenant, $event, false,
            $facts[(int) $event->id] + ['invitations' => $metadata[(int) $event->id]]))->all();
    }

    public function event(Account $actor, Tenant $tenant, int $id, bool $seriesSnapshot = true, bool $includePushSnapshot = true): array
    {
        $p = $this->planning;
        $event = $p->event($actor, $tenant, $id);
        return $this->renderEvent($actor, $tenant, $event, $seriesSnapshot, null, $includePushSnapshot);
    }

    private function renderEvent(Account $actor, Tenant $tenant, object $event, bool $seriesSnapshot, ?array $projection = null, bool $includePushSnapshot = true): array
    {
        $p = $this->planning; $id = (int) $event->id;
        $invitationMeta = $projection['invitations'] ?? app(MobilePlanningInvitationsData::class)->metadata($actor, $tenant, $event, $this->capabilities);
        // List/Today projections have no push publication action. A recipient
        // fingerprint is expensive and authoritative only when opening detail.
        $capabilities = $this->capabilities->event($actor, $tenant, $event, $projection === null);
        $pushSnapshot = $includePushSnapshot && $capabilities['CanSendSquadPush'] ? app(MobilePushSquad::class)->snapshot($actor, $tenant, $id) : null;
        $manager = $projection['manager'] ?? $p->teams($actor, $tenant, true)->where('id', $event->team_season_id)->exists();
        $own = $projection['own'] ?? app(MemberAccessService::class)->represented($actor, $tenant)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $parts = $projection['parts'] ?? $p->table($tenant, 'team_participations')->where('event_id', $id)->get()->keyBy('member_id');
        $people = ($projection['roster'] ?? $p->roster($tenant, $event)->get(['id', 'first_name', 'last_name']))->map(function ($member) use ($parts, $event, $manager, $own, $invitationMeta) {
            $part = $parts->get($member->id);
            $private = $manager || in_array((int) $member->id, $own, true);
            return ['TeamTerminTeilnahmeID' => (int) ($part->id ?? 0), 'MitgliederID' => (int) $member->id,
                ...($invitationMeta['members'][$member->id] ?? []),
                'Vorname' => $member->first_name, 'Nachname' => $member->last_name,
                'TeilnahmeStatus' => $part->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN'),
                'AnwesenheitsStatus' => $part->attendance ?? 'OFFEN', 'Revision' => (int) ($part->revision ?? 0),
                'Kommentar' => $private && ($part->comment_encrypted ?? null) ? Crypt::decryptString($part->comment_encrypted) : '',
                'UpdatedAt' => $part->updated_at ?? null,
                'AnwesenheitsKommentar' => $private && ($part->attendance_comment_encrypted ?? null) ? Crypt::decryptString($part->attendance_comment_encrypted) : '',
                'AnwesenheitUpdatedAt' => $part->attendance_updated_at ?? null];
        });
        $guests = ($projection['guests'] ?? $p->table($tenant, 'team_guests')->where('event_id', $id)->whereNull('deleted_at')->orderBy('id')->get())->map(fn ($guest) => [
            'GastID' => (int) $guest->id, 'Vorname' => $guest->first_name, 'Nachname' => $guest->last_name,
            'TeilnahmeStatus' => $guest->status, 'AnwesenheitsStatus' => $guest->attendance,
            'Revision' => (int) $guest->revision, 'Kommentar' => $manager && $guest->comment_encrypted ? Crypt::decryptString($guest->comment_encrypted) : '',
            'AnwesenheitsKommentar' => $manager && ($guest->attendance_comment_encrypted ?? null) ? Crypt::decryptString($guest->attendance_comment_encrypted) : '',
            'AnwesenheitUpdatedAt' => $guest->attendance_updated_at ?? null,
        ]);
        $all = $people->concat($guests)->values();
        $staffIds = $projection['staff'] ?? $p->table($tenant, 'team_staff')->where('team_season_id', $event->team_season_id)->where('active', true)->pluck('member_id')->all();
        $playerIds = $projection['players'] ?? $p->table($tenant, 'team_players')->where('team_season_id', $event->team_season_id)->where('active', true)->pluck('member_id')->all();
        $counted = $all->reject(fn ($person) => isset($person['MitgliederID']) && in_array($person['MitgliederID'], $staffIds, true));
        $pending = $people->filter(fn ($person) => in_array($person['MitgliederID'], $playerIds, true)
            && ! in_array($person['MitgliederID'], $staffIds, true) && $person['TeilnahmeStatus'] === 'OFFEN')->values()->all();
        $team = $projection['team'] ?? $p->table($tenant, 'team_seasons')->where('id', $event->team_season_id)->first();
        return ['TeamTerminID' => (int) $event->id, 'TeamSaisonID' => (int) $event->team_season_id,
            'TeamSaisonLabel' => $team->label, 'Titel' => $event->title, 'Terminart' => $event->kind,
            'DatumVon' => $event->starts_at, 'DatumBis' => $event->ends_at, 'AnmeldeFrist' => $event->deadline_at,
            'TeilnahmeModus' => $event->mode, 'Status' => $event->status, 'Revision' => (int) $event->revision,
            'Ort' => $event->location, 'Beschreibung' => $event->description, 'MaxTeilnehmer' => $event->capacity,
            ...($seriesSnapshot ? ['SeriesRevision' => $manager ? app(MobilePlanningSeries::class)->revision($tenant, $event) : null] : []),
            'SerieID' => $event->series_key, 'CanManage' => $manager,
            ...$capabilities,
            ...($pushSnapshot ? ['PushRevision' => $pushSnapshot['revision'], 'PushRecipientFingerprint' => $pushSnapshot['recipient_fingerprint']] : []),
            'CanAddGuests' => $capabilities['CanAddPeople'], 'CanInviteMembers' => $invitationMeta['CanInviteMembers'],
            'CanAddPeople' => $capabilities['CanAddPeople'] || $invitationMeta['CanInviteMembers'],
            'Teilnahmen' => $all->all(), 'MeineTeilnahmen' => $people->whereIn('MitgliederID', $own)->values()->all(),
            ...($manager && $event->mode === 'ANMELDEN' ? ['NichtAngemeldet' => $pending] : []),
            'StaffDabeiCount' => $people->whereIn('MitgliederID', $staffIds)->where('TeilnahmeStatus', 'DABEI')->count(),
            'DabeiCount' => $counted->where('TeilnahmeStatus', 'DABEI')->count(),
            'AbwesendCount' => $counted->where('TeilnahmeStatus', 'ABWESEND')->count(),
            'VielleichtCount' => $counted->where('TeilnahmeStatus', 'VIELLEICHT')->count(),
            'OffenCount' => $counted->where('TeilnahmeStatus', 'OFFEN')->count(),
            'AnwesendCount' => $counted->where('AnwesenheitsStatus', 'ANWESEND')->count(),
            'OhneAbmeldungCount' => $counted->where('AnwesenheitsStatus', 'FEHLT')->count(),
            'AbgemeldetCount' => $counted->where('AnwesenheitsStatus', 'ENTSCHULDIGT')->count(),
            'AnwesenheitOffenCount' => $counted->where('AnwesenheitsStatus', 'OFFEN')->count()];
    }
}
