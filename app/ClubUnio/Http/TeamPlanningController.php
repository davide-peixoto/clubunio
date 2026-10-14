<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\PlanningCalendarService;
use App\ClubUnio\Services\PlanningGuestService;
use App\ClubUnio\Services\PlanningSeriesService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TeamPlanningController
{
    public function __construct(private TeamPlanningService $service) {}

    private function context(Request $request, Tenant $tenant): array
    {
        $actor = $request->user();
        $this->service->enter($actor, $tenant);
        $options = $this->service->options($tenant);
        $teams = $this->service->teams($actor, $tenant)->orderByDesc('season_id')->orderBy('label')->get();
        $managed = $this->service->teams($actor, $tenant, true)->pluck('id')->all();
        $own = app(MemberAccessService::class)->represented($actor, $tenant)->get();
        $colors = $this->service->table($tenant, 'status_styles')->where('active', true)->pluck('color', 'value_key')->all();

        return compact('actor', 'tenant', 'options', 'teams', 'managed', 'own', 'colors');
    }

    private function view(string $name, array $data)
    {
        return response()->view('clubunio.planning.'.$name, $data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Tenant $tenant)
    {
        $data = $this->context($request, $tenant);
        $filters = $request->validate(['team' => 'nullable|integer', 'q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'status' => 'nullable|string|max:40']);
        $filters += ['from' => now('Europe/Zurich')->toDateString()];
        $query = $this->service->table($tenant, 'team_events')->where(fn ($q) => $q->whereIn('team_season_id', $data['teams']->pluck('id'))->orWhereIn('id', $this->service->invitedEvents($data['actor'], $tenant)->select('event_id')));
        if (! empty($filters['team'])) {
            $query->where('team_season_id', $filters['team']);
        }
        if (! empty($filters['q'])) {
            $query->where('title', 'ilike', '%'.$filters['q'].'%');
        }
        if (! empty($filters['from'])) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($filters['from'], 'Europe/Zurich')->toIso8601String());
        }
        if (! empty($filters['to'])) {
            $query->where('starts_at', '<', CarbonImmutable::parse($filters['to'], 'Europe/Zurich')->addDay()->toIso8601String());
        }
        $counts = (clone $query)->selectRaw('status,count(*) AS total')->groupBy('status')->pluck('total', 'status');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $events = $query->orderBy('starts_at')->paginate(25)->withQueryString();
        // Labels for invited events do not expand the authorization query above.
        $data['teams'] = $data['teams']->merge($this->service->table($tenant, 'team_seasons')->whereIn('id', $events->pluck('team_season_id'))->get())->unique('id');

        return $this->view('index', $data + compact('events', 'filters', 'counts'));
    }

    public function calendar(Request $request, Tenant $tenant)
    {
        $context = $this->context($request, $tenant);
        $data = app(PlanningCalendarService::class)->calendar($context['actor'], $tenant, $request->query());

        return $this->view('calendar', array_replace($context, $data));
    }

    public function calendarExport(Request $request, Tenant $tenant)
    {
        $bytes = app(PlanningCalendarService::class)->export($request->user(), $tenant, $request->query());

        return response($bytes, 200, ['Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ClubUnio-Termine.ics"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function form(Request $request, Tenant $tenant, ?int $event = null)
    {
        $data = $this->context($request, $tenant);
        abort_unless(count($data['managed']), 403);
        $record = $event ? $this->service->event($data['actor'], $tenant, $event, true) : null;

        return $this->view('form', $data + compact('record'));
    }

    public function save(Request $request, Tenant $tenant, ?int $event = null)
    {
        $id = $event ? $this->service->saveEvent($request->user(), $tenant, $event, $request->all())
            : $this->service->createSeries($request->user(), $tenant, $request->all())[0];

        return redirect()->route('clubunio.planning.show', [$tenant->slug, $id])->with('notice', 'Termin gespeichert.');
    }

    public function show(Request $request, Tenant $tenant, int $event)
    {
        $data = $this->context($request, $tenant);
        $record = $this->service->event($data['actor'], $tenant, $event);
        $data['teams'] = $data['teams']->merge($this->service->table($tenant, 'team_seasons')->where('id', $record->team_season_id)->get())->unique('id');
        $manager = in_array($record->team_season_id, $data['managed'], true);
        $roster = $this->service->roster($tenant, $record)->get();
        $parts = $this->service->table($tenant, 'team_participations')->where('event_id', $event)->get()->keyBy('member_id');
        $ownIds = $data['own']->pluck('id')->all();
        // Never pass other participants' private comments to the view layer.
        foreach ($parts as $member => $part) {
            $part->comment = ($manager || in_array($member, $ownIds, true)) && $part->comment_encrypted ? Crypt::decryptString($part->comment_encrypted) : '';
            unset($part->comment_encrypted,$part->original_comment_encrypted);
        }
        $locked = $this->service->locked($record);
        $guests = $this->service->table($tenant, 'team_guests')->where('event_id', $event)->whereNull('deleted_at')->orderBy('last_name')->get();
        foreach ($guests as $guest) {
            $guest->comment = $manager && $guest->comment_encrypted ? Crypt::decryptString($guest->comment_encrypted) : '';
            unset($guest->comment_encrypted);
        }
        $invited = $this->service->table($tenant, 'team_invitations')->where('event_id', $event)->pluck('member_id')->all();
        $candidates = collect();
        $search = $request->validate(['member_search' => 'nullable|string|min:2|max:100'])['member_search'] ?? '';
        if ($manager && $search !== '') {
            $candidates = app(MemberAccessService::class)->activeMembers($tenant)->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%'))
                ->whereNotIn('id', $roster->pluck('id'))->limit(20)->get(['id', 'first_name', 'last_name']);
        }

        return $this->view('show', $data + compact('record', 'manager', 'roster', 'parts', 'ownIds', 'locked', 'guests', 'invited', 'candidates', 'search'));
    }

    public function guest(Request $request, Tenant $tenant, int $event, ?int $guest = null)
    {
        app(PlanningGuestService::class)->save($request->user(), $tenant, $event, $guest, $request->all(), $request->isMethod('DELETE'));

        return redirect()->route('clubunio.planning.show', [$tenant->slug, $event])->with('notice', 'Gastspieler gespeichert.');
    }

    public function invite(Request $request, Tenant $tenant, int $event, int $member)
    {
        app(PlanningGuestService::class)->invite($request->user(), $tenant, $event, $member, $request->isMethod('DELETE'));

        return redirect()->route('clubunio.planning.show', [$tenant->slug, $event])->with('notice', 'Einladung aktualisiert.');
    }

    public function series(Request $request, Tenant $tenant, int $event)
    {
        $data = $this->context($request, $tenant);
        $record = $this->service->event($data['actor'], $tenant, $event, true);
        abort_unless($record->series_key, 404);
        $preview = null;
        if ($request->isMethod('POST')) {
            $preview = app(PlanningSeriesService::class)->preview($data['actor'], $tenant, $event, $request->all());
            $request->session()->put('planning.series.'.$tenant->id.'.'.$event, $preview['fingerprint']);
        }

        return $this->view('series', $data + compact('record', 'preview'));
    }

    public function seriesApply(Request $request, Tenant $tenant, int $event)
    {
        $fingerprint = $request->session()->pull('planning.series.'.$tenant->id.'.'.$event);
        abort_unless(is_string($fingerprint), 409);
        $count = app(PlanningSeriesService::class)->apply($request->user(), $tenant, $event, $request->all(), $fingerprint);

        return redirect()->route('clubunio.planning.show', [$tenant->slug, $event])->with('notice', $count.' Serientermine aktualisiert.');
    }

    public function sfv(Request $request, Tenant $tenant)
    {
        $data = $this->context($request, $tenant);
        abort_unless(count($data['managed']), 403);
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $filters['from'] = $filters['from'] ?? now('Europe/Zurich')->toDateString();
        $filters['to'] = $filters['to'] ?? now('Europe/Zurich')->addMonths(3)->toDateString();
        $teamIds = $data['teams']->whereIn('id', $data['managed'])->pluck('team_id');
        $sfvIds = $this->service->table($tenant, 'teams')->whereIn('id', $teamIds)->whereNotNull('sfv_team_id')->pluck('sfv_team_id');
        $matches = $this->service->table($tenant, 'sfv_schedule')->where(fn ($q) => $q->whereIn('team_a_id', $sfvIds)->orWhereIn('team_b_id', $sfvIds))
            ->where('starts_at', '>=', CarbonImmutable::parse($filters['from'], 'Europe/Zurich')->toIso8601String())
            ->where('starts_at', '<', CarbonImmutable::parse($filters['to'], 'Europe/Zurich')->addDay()->toIso8601String())->orderBy('starts_at')->paginate(25)->withQueryString();
        $imported = $this->service->table($tenant, 'team_events')->whereIn('team_season_id', $data['managed'])->whereIn('sfv_match_id', $matches->pluck('match_id'))->get()->groupBy('sfv_match_id');

        return $this->view('sfv', $data + compact('matches', 'filters', 'imported'));
    }

    public function sfvRefresh(Request $request, Tenant $tenant)
    {
        $count = app(SfvPlanningService::class)->refresh($request->user(), $tenant, $request->all());

        return redirect()->route('clubunio.planning.sfv', [$tenant->slug] + $request->only('from', 'to'))->with('notice', $count.' SFV-Spiele abgerufen.');
    }

    public function sfvForm(Request $request, Tenant $tenant, int $match)
    {
        $data = $this->context($request, $tenant);
        $sfv = $this->service->table($tenant, 'sfv_schedule')->where('id', $match)->first();
        abort_unless($sfv, 404);
        $teamIds = $this->service->table($tenant, 'teams')->whereIn('sfv_team_id', [$sfv->team_a_id, $sfv->team_b_id])->pluck('id');
        $data['teams'] = $data['teams']->whereIn('id', $data['managed'])->whereIn('team_id', $teamIds);
        abort_if($data['teams']->isEmpty(), 403);
        $team = $data['teams']->firstWhere('id', (int) $request->query('team')) ?? $data['teams']->first();
        $teamChoices = $data['teams'];
        $data['teams'] = $data['teams']->where('id', $team->id);
        $existing = $this->service->table($tenant, 'team_events')->where('team_season_id', $team->id)->where('sfv_match_id', $sfv->match_id)->first();
        $record = $existing ? clone $existing : (object) ['id' => null, 'revision' => 0, 'team_season_id' => $team->id, 'kind' => 'MEISTERSCHAFT', 'mode' => 'ANMELDEN', 'status' => 'AKTIV', 'capacity' => 18, 'description' => '', 'deadline_at' => null];
        $record->title = $sfv->title;
        if (! $existing) {
            $kind = collect($data['options']['kind'])->first(fn ($option) => $option->active && mb_strtolower($option->name) === mb_strtolower($sfv->match_type));
            $record->kind = $kind->code ?? 'MEISTERSCHAFT';
        }
        $record->location = $sfv->location;
        $record->starts_at = $sfv->starts_at;
        $duration = $existing ? max(1, CarbonImmutable::parse($existing->starts_at)->diffInMinutes(CarbonImmutable::parse($existing->ends_at))) : 120;
        $record->ends_at = $sfv->starts_at ? CarbonImmutable::parse($sfv->starts_at)->addMinutes($duration)->toIso8601String() : null;

        return $this->view('form', $data + compact('record', 'sfv', 'existing', 'teamChoices'));
    }

    public function sfvImport(Request $request, Tenant $tenant, int $match)
    {
        $id = app(SfvPlanningService::class)->import($request->user(), $tenant, $match, $request->all());

        return redirect()->route('clubunio.planning.show', [$tenant->slug, $id])->with('notice', 'SFV-Spiel übernommen.');
    }

    public function participate(Request $request, Tenant $tenant, int $event, int $member)
    {
        $this->service->participate($request->user(), $tenant, $event, $member, $request->all());

        return redirect()->route('clubunio.planning.show', [$tenant->slug, $event])->with('notice', 'Teilnahme gespeichert.');
    }

    public function absences(Request $request, Tenant $tenant)
    {
        $data = $this->context($request, $tenant);
        $absences = $this->service->absences($data['actor'], $tenant)->orderByDesc('starts_on')->paginate(25);
        $members = $this->service->table($tenant, 'members')->whereIn('id', $absences->pluck('member_id'))->get()->keyBy('id');
        foreach ($absences as $absence) {
            $absence->note = $absence->note_encrypted ? Crypt::decryptString($absence->note_encrypted) : '';
            unset($absence->note_encrypted);
        }
        $record = null;
        if ($request->filled('edit')) {
            $id = $request->validate(['edit' => 'required|integer|min:1'])['edit'];
            $record = $this->service->absences($data['actor'], $tenant)->where('id', $id)->first();
            abort_unless($record && $data['own']->contains('id', $record->member_id), 404);
            $record->note = $record->note_encrypted ? Crypt::decryptString($record->note_encrypted) : '';
        }

        return $this->view('absences', $data + compact('absences', 'members', 'record'));
    }

    public function saveAbsence(Request $request, Tenant $tenant, ?int $absence = null)
    {
        $result = $this->service->saveAbsence($request->user(), $tenant, $absence, $request->all(), $request->isMethod('DELETE'));

        return redirect()->route('clubunio.planning.absences', $tenant->slug)->with('notice', 'Absenz gespeichert.'.($result['skipped'] ? ' '.$result['skipped'].' fristgesperrte Termine blieben unverändert. Bitte das Trainerteam kontaktieren.' : ''));
    }

    public function options(Request $request, Tenant $tenant)
    {
        $data = $this->context($request, $tenant);
        abort_unless($data['actor']->canAccessTenant($tenant), 403);

        return $this->view('options', $data);
    }

    public function saveOption(Request $request, Tenant $tenant)
    {
        abort_unless($request->user()->canAccessTenant($tenant), 403);
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:100', 'category' => ['required', Rule::in(['kind', 'mode', 'status', 'participation', 'attendance', 'absence'])],
            'code' => 'required|regex:/^[A-Z_]{2,40}$/', 'active' => 'required|boolean', 'sort_order' => 'required|integer|min:0|max:10000']);
        DB::connection('clubunio')->transaction(function () use ($request, $tenant, $data) {
            $q = $this->service->table($tenant, 'planning_options');
            if (! empty($data['id'])) {
                $old = (clone $q)->where('id', $data['id'])->first();
                abort_unless($old && $old->category === $data['category'] && $old->code === $data['code'], 404);
                $q->where('id', $data['id'])->update(collect($data)->only('name', 'active', 'sort_order')->all());
                $id = $data['id'];
            } else {
                abort_unless(in_array($data['category'], ['kind', 'absence'], true), 422);
                Validator::make($data, ['code' => [Rule::unique('clubunio.clubora.planning_options', 'code')->where('tenant_id', $tenant->id)->where('category', $data['category'])]])->validate();
                unset($data['id']);
                $id = $q->insertGetId($data + ['tenant_id' => $tenant->id]);
            }
            $this->service->table($tenant, 'object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $request->user()->id, 'object_type' => 'planning_options', 'object_id' => $id, 'after_value' => json_encode($data)]);
        });

        return redirect()->route('clubunio.planning.options', $tenant->slug)->with('notice', 'Auswahlwert gespeichert.');
    }
}
