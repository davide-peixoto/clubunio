<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BusKeyboxAccessService;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\MemberAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class BusReservationController
{
    public function __construct(private BusReservationService $service) {}

    private function context(Request $request, Tenant $tenant): array
    {
        $actor = $request->user();
        $this->service->enter($actor, $tenant);
        $teams = $this->service->teams($actor, $tenant)->orderByDesc('season_id')->orderBy('label')->get();
        $buses = $this->service->table($tenant, 'buses')->orderBy('name')->get();
        $statuses = $this->service->table($tenant, 'bus_statuses')->orderBy('id')->get()->keyBy('code');
        $colors = $this->service->table($tenant, 'status_styles')->where('active', true)->pluck('color', 'value_key')->all();

        return compact('actor', 'tenant', 'teams', 'buses', 'statuses', 'colors');
    }

    private function view(string $name, array $data)
    {
        return response()->view('clubunio.bus.'.$name, $data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Tenant $tenant)
    {
        $data = $this->context($request, $tenant);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'team' => 'nullable|integer', 'status' => 'nullable|string|max:40', 'bus' => 'nullable|integer']);
        $filters += ['from' => now('Europe/Zurich')->toDateString()];
        $q = $this->service->visible($data['actor'], $tenant);
        if (! empty($filters['q'])) {
            $q->where(fn ($q) => $q->where('location', 'ilike', '%'.$filters['q'].'%')->orWhere('description', 'ilike', '%'.$filters['q'].'%'));
        }
        if (! empty($filters['from'])) {
            $q->where('ends_at', '>', CarbonImmutable::parse($filters['from'], 'Europe/Zurich')->toIso8601String());
        }
        if (! empty($filters['to'])) {
            $q->where('starts_at', '<', CarbonImmutable::parse($filters['to'], 'Europe/Zurich')->addDay()->toIso8601String());
        }
        if (! empty($filters['team'])) {
            $q->where('team_season_id', $filters['team']);
        }
        if (! empty($filters['bus'])) {
            $q->where('bus_id', $filters['bus']);
        }
        $counts = (clone $q)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        $rows = $q->orderBy('starts_at')->orderBy('id')->paginate(25)->withQueryString();
        $data['teams'] = $data['teams']->merge($this->service->table($tenant, 'team_seasons')->whereIn('id', $rows->pluck('team_season_id'))->get())->unique('id');

        return $this->view('index', $data + compact('rows', 'filters', 'counts'));
    }

    public function create(Request $request, Tenant $tenant)
    {
        $data = $this->context($request, $tenant);
        $data['teams'] = $this->service->reservableTeams($data['actor'], $tenant)->orderByDesc('season_id')->orderBy('label')->get();
        abort_if($data['teams']->isEmpty(), 403);
        $members = ($data['actor']->canAccessTenant($tenant) ? app(MemberAccessService::class)->activeMembers($tenant) : app(MemberAccessService::class)->represented($data['actor'], $tenant, true))->orderBy('last_name')->orderBy('first_name')->get();

        return $this->view('create', $data + compact('members'));
    }

    public function store(Request $request, Tenant $tenant)
    {
        $id = $this->service->create($request->user(), $tenant, $request->all());

        return redirect()->route('clubunio.bus.show', [$tenant->slug, $id])->with('notice', 'Reservation gespeichert.');
    }

    public function show(Request $request, Tenant $tenant, int $reservation)
    {
        $data = $this->context($request, $tenant);
        $row = $this->service->visible($data['actor'], $tenant)->where('id', $reservation)->first();
        abort_unless($row, 404);
        $team = $this->service->table($tenant, 'team_seasons')->where('id', $row->team_season_id)->value('label');
        $member = $this->service->table($tenant, 'members')->where('id', $row->member_id)->first();
        $canCancel = app(MemberAccessService::class)->represented($data['actor'], $tenant, true)->where('id', $row->member_id)->exists();

        $canReschedule = $this->service->canReschedule($data['actor'], $tenant, $row);
        $keybox = app(BusKeyboxAccessService::class)->inspect($data['actor'], $tenant, $reservation);

        return $this->view('show', $data + compact('row', 'team', 'member', 'canCancel', 'canReschedule', 'keybox'));
    }

    public function edit(Request $request, Tenant $tenant, int $reservation)
    {
        $data = $this->context($request, $tenant);
        $row = $this->service->visible($data['actor'], $tenant)->where('id', $reservation)->first();
        abort_unless($row, 404);
        abort_unless($this->service->canReschedule($data['actor'], $tenant, $row), 403);

        return $this->view('edit', $data + compact('row'));
    }

    public function reschedule(Request $request, Tenant $tenant, int $reservation)
    {
        $this->service->reschedule($request->user(), $tenant, $reservation, $request->all());

        return redirect()->route('clubunio.bus.show', [$tenant->slug, $reservation])->with('notice', 'Reservation gespeichert.');
    }

    public function options(Request $request, Tenant $tenant)
    {
        abort_unless($request->user()->canAccessTenant($tenant), 403);

        return $this->view('options', $this->context($request, $tenant));
    }

    public function assignment(Request $request, Tenant $tenant, int $reservation)
    {
        abort_unless($request->user()->fresh()?->canAccessTenant($tenant), 403);
        $data = $this->context($request, $tenant);
        $row = $this->service->visible($data['actor'], $tenant)->where('id', $reservation)->first();
        abort_unless($row && $row->legacy_id && (! $row->member_id || ! $row->team_season_id), 404);
        $data['teams'] = $this->service->table($tenant, 'team_seasons')->orderByDesc('season_id')->orderBy('label')->get();
        $members = $this->service->table($tenant, 'members')->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'legacy_id']);

        return $this->view('assignment', $data + compact('row', 'members'));
    }

    public function assign(Request $request, Tenant $tenant, int $reservation)
    {
        $this->service->assignImported($request->user(), $tenant, $reservation, $request->all());

        return redirect()->route('clubunio.bus.show', [$tenant->slug, $reservation])->with('notice', 'Historische Zuordnung ergänzt.');
    }

    public function saveOption(Request $request, Tenant $tenant)
    {
        $this->service->saveOption($request->user(), $tenant, $request->all());

        return redirect()->route('clubunio.bus.options', $tenant->slug)->with('notice', 'Auswahl gespeichert.');
    }

    public function decide(Request $request, Tenant $tenant, int $reservation)
    {
        $input = $request->validate(['revision' => 'required|integer|min:1', 'status' => 'required|string', 'note' => 'nullable|string|max:1000']);
        $this->service->decide($request->user(), $tenant, $reservation, (int) $input['revision'], $input['status'], $input['note'] ?? null);

        return redirect()->route('clubunio.bus.show', [$tenant->slug, $reservation])->with('notice', 'Entscheid gespeichert.');
    }
}
