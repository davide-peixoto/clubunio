<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Competition, Tenant, TrainingSlot};
use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Mobile\MobileSeasonScope;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PitchPlanningService
{
    public function __construct(private PitchPlanningProjection $projection) {}

    public function canView(Account $actor, Tenant $tenant): bool
    {
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'competitions', 'view')
            || app(MemberAccessService::class)->canEnter($actor, $tenant);
    }

    private function office(Account $actor, Tenant $tenant, string $module = 'competitions', string $operation = 'update'): bool
    {
        $role = $this->projection->table('tenant_accounts', $tenant)->where('account_id', $actor->id)->value('role');
        return in_array($role, ['admin', 'office'], true)
            && app(ModulePermissionService::class)->allows($actor, $tenant, $module, $operation);
    }

    private function ready(): void
    {
        abort_unless($this->projection->ready(), 503, __('Die Platzplanung ist noch nicht verfügbar.'));
    }

    private function teams(Account $actor, Tenant $tenant): array
    {
        $access = app(MemberAccessService::class);
        $role = $access->membership($actor, $tenant)?->role;
        if ($role === 'trainer' && $access->canEnter($actor, $tenant)) {
            return app(MemberTeamAccessService::class)->trainerTeams($actor, $tenant)->distinct()->pluck('assignment.team_season_id')->map(fn ($id) => (int) $id)->all();
        }
        return $role === 'technical_lead' ? array_map('intval', $access->responsibleTeams($actor, $tenant)) : [];
    }

    private function capabilities(Account $actor, Tenant $tenant): array
    {
        $manage = $this->office($actor, $tenant);
        return ['view' => true, 'manage' => $manage, 'approve' => $manage,
            'override_field_status' => $manage && $this->canOverrideFieldStatus($actor, $tenant),
            'request' => ! request()->attributes->get('clubunio_impersonation') && ($manage || $this->teams($actor, $tenant) !== [])];
    }

    private function canOverrideFieldStatus(Account $actor, Tenant $tenant): bool
    {
        return ! request()->attributes->get('clubunio_impersonation')
            && $this->projection->table('tenant_accounts', $tenant)->where('account_id', $actor->id)->value('role') === 'admin'
            && $this->office($actor, $tenant);
    }

    public function options(Account $actor, Tenant $tenant): array
    {
        abort_unless($this->canView($actor, $tenant), 403);
        $this->ready();
        return ['fields' => $this->projection->fields($tenant), 'teams' => $this->teamOptions($actor, $tenant),
            'capabilities' => $this->capabilities($actor, $tenant)];
    }

    private function teamOptions(Account $actor, Tenant $tenant): array
    {
        // Scope only selectable options, never historical calendar entries or write authorization.
        $season = app(MobileSeasonScope::class)->current($tenant);
        if (! $season) return [];
        $allowed = $this->office($actor, $tenant) ? null : $this->teams($actor, $tenant);
        $teams = $this->projection->table('team_seasons', $tenant)->where('active', true)->where('season_id', $season->id)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('id')->get(['id', 'team_id']);
        $names = $this->projection->table('teams', $tenant)->where('active', true)
            ->whereIn('id', $teams->pluck('team_id')->unique())->pluck('name', 'id');

        return $teams->filter(fn ($team) => $names->has($team->team_id))
            ->map(fn ($team) => ['id' => (int) $team->id, 'name' => $names[$team->team_id].' ('.$season->name.')'])
            ->values()->all();
    }

    public function calendar(Account $actor, Tenant $tenant, array $filters): array
    {
        abort_unless($this->canView($actor, $tenant), 403);
        $this->ready();
        $data = Validator::make($filters, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'period_id' => 'nullable|integer|min:1', 'team_season_id' => 'nullable|integer|min:1'])->validate();
        if (Time::parse($data['from'])->diffInDays(Time::parse($data['to'])) > 41) throw ValidationException::withMessages(['to' => __('Bitte höchstens 42 Tage auswählen.')]);
        if (! empty($data['period_id'])) abort_unless($this->projection->table('training_options', $tenant)->where('category', 'period')->where('active', true)->where('id', $data['period_id'])->exists(), 404);
        $fields = $this->projection->fields($tenant);
        $entries = $this->projection->entries($tenant, $data['from'], $data['to'], $data['period_id'] ?? null);
        $capabilities = $this->capabilities($actor, $tenant);
        foreach ($entries as &$entry) {
            $entry['conflicts'] = PitchPlanningGeometry::conflicts($entry, $entries, $fields);
            $entry['editable'] = $capabilities['manage'] && ! in_array($entry['status'], ['rejected', 'cancelled'], true);
            if (! $capabilities['manage'] && ($entry['requester_account_id'] ?? null) !== (int) $actor->id) unset($entry['requester_account_id']);
        }
        unset($entry);
        if (! empty($data['team_season_id'])) $entries = array_values(array_filter($entries, fn ($entry) => in_array((int) $data['team_season_id'], $entry['team_season_ids'], true)));
        return ['from' => $data['from'], 'to' => $data['to'], 'timezone' => 'Europe/Zurich', 'fields' => $fields, 'entries' => $entries, 'capabilities' => $capabilities];
    }

    public function requests(Account $actor, Tenant $tenant, array $filters = []): array
    {
        abort_unless($this->canView($actor, $tenant), 403);
        $all = $this->office($actor, $tenant, 'competitions', 'view');
        $access = app(MemberAccessService::class);
        abort_unless($all || (in_array($access->membership($actor, $tenant)?->role, ['trainer', 'technical_lead'], true)
            && $access->canEnter($actor, $tenant)
            && in_array('Platzverfugbarkeit', app(MobileModuleSettings::class)->visibleKeys($actor, $tenant), true)), 403);
        abort_if(! $all && array_key_exists('status', $filters), 403);
        $this->ready();
        $data = Validator::make($filters, [
            'page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|required|in:pending,approved,rejected,cancelled',
        ])->validate();
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? 30);
        $rows = $this->projection->table('pitch_requests', $tenant)
            ->when(! $all, fn ($query) => $query->where('requester_account_id', $actor->id)
                ->where('date', '>=', now('Europe/Zurich')->toDateString()))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('id')->offset(($page - 1) * $size)->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size);
        $fields = $this->projection->requestFields($tenant, $rows->pluck('field_id')->unique()->all());
        $teams = $this->teamOptions($actor, $tenant);
        $capabilities = $this->capabilities($actor, $tenant);
        $capabilities['request'] = $capabilities['request'] && $teams !== [];
        $entries = [];
        foreach ($rows as $row) {
            $entry = $this->projection->stored('request', $row);
            // Inbox conflicts stay empty; calendar and fresh mutation checks are authoritative.
            $entry['editable'] = $capabilities['manage'] && ! in_array($entry['status'], ['rejected', 'cancelled'], true);
            unset($entry['requester_account_id']);
            $entries[] = $entry;
        }
        return ['timezone' => 'Europe/Zurich', 'fields' => $fields, 'teams' => $teams, 'entries' => $entries,
            'capabilities' => $capabilities, 'can_filter_status' => $all, 'page' => $page, 'has_more' => $hasMore];
    }

    private function write(Account $actor, Tenant $tenant, \Closure $action): mixed
    {
        $this->ready();
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $action) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            // Mutations can be invoked inside a GET/render frame; never reuse its facts.
            $frames = [];
            foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
                $frames[$key] = request()->attributes->get($key);
                request()->attributes->remove($key);
            }
            try {
                abort_if(request()->attributes->get('clubunio_impersonation'), 403);
                $fresh = $actor->fresh();
                abort_unless($fresh?->active && $this->canView($fresh, $tenant), 403);
                return $action($fresh);
            } finally {
                foreach ($frames as $key => $value) if ($value !== null) request()->attributes->set($key, $value);
            }
        });
    }

    private function placement(Tenant $tenant, array $input, bool $block = false): array
    {
        $data = Validator::make($input + ['duration_minutes' => 120, 'area_id' => null], [
            'field_id' => 'required|integer|min:1', 'area_id' => 'nullable|integer|min:1', 'date' => 'required|date_format:Y-m-d',
            'starts_at' => 'required|date_format:H:i', 'duration_minutes' => 'required|integer|min:1|max:'.($block ? '44640' : '1440'),
        ])->validate();
        abort_unless($this->projection->table('training_options', $tenant)->where('id', $data['field_id'])->where('category', 'field')->where('active', true)->exists(), 404);
        if ($data['area_id']) abort_unless($this->projection->table('pitch_areas', $tenant)->where('id', $data['area_id'])->where('field_id', $data['field_id'])->exists(), 404);
        return $data;
    }

    private function activeTeam(Tenant $tenant, int $id): void
    {
        $team = $this->projection->table('team_seasons', $tenant)->where('id', $id)->where('active', true)->first();
        abort_unless($team && $this->projection->table('teams', $tenant)->where('id', $team->team_id)->where('active', true)->exists(), 404);
    }

    public function requestFriendly(Account $actor, Tenant $tenant, array $input): array
    {
        return $this->createRequest($actor, $tenant, $input, false);
    }

    public function authorizeFriendlyRequest(Account $actor, Tenant $tenant, int $teamSeasonId): void
    {
        // An idempotent replay must use the same fresh authorization as a new request.
        $this->write($actor, $tenant, function ($actor) use ($tenant, $teamSeasonId) {
            $this->activeTeam($tenant, $teamSeasonId);
            abort_unless($this->office($actor, $tenant, 'competitions', 'create')
                || in_array($teamSeasonId, $this->teams($actor, $tenant), true), 403);
        });
    }

    public function createManual(Account $actor, Tenant $tenant, array $input): array
    {
        return $this->createRequest($actor, $tenant, $input, true);
    }

    private function createRequest(Account $actor, Tenant $tenant, array $input, bool $approved): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $input, $approved) {
            $office = $this->office($actor, $tenant, 'competitions', 'create');
            abort_unless(! $approved || $office, 403);
            $data = Validator::make($input, ['team_season_id' => 'required|integer|min:1', 'opponent' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000'])->validate();
            $this->activeTeam($tenant, (int) $data['team_season_id']);
            abort_unless($office || in_array((int) $data['team_season_id'], $this->teams($actor, $tenant), true), 403);
            $data += $this->placement($tenant, $input);
            $data += ['requester_account_id' => $actor->id, 'status' => $approved ? 'approved' : 'pending', 'revision' => 1];
            $entry = $this->projection->stored('request', (object) ($data + ['id' => 0]));
            if ($approved) $this->ensureFree($tenant, $entry);
            else $entry['conflicts'] = $this->conflicts($tenant, $entry);
            $id = $this->insert($tenant, 'pitch_requests', $data);
            $after = $this->projection->stored('request', $this->row($tenant, 'pitch_requests', $id));
            $after['conflicts'] = $this->conflicts($tenant, $after);
            $this->audit($actor, $tenant, null, $after, $approved ? 'created' : 'requested');
            return $after;
        });
    }

    public function decide(Account $actor, Tenant $tenant, int $requestId, array $input): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $requestId, $input) {
            abort_unless($this->office($actor, $tenant), 403);
            $data = Validator::make($input, ['revision' => 'required|string', 'decision' => 'required|in:approved,rejected',
                'override_field_status' => 'sometimes|boolean'])->validate();
            $override = (bool) ($data['override_field_status'] ?? false);
            abort_if($override && $data['decision'] !== 'approved', 422);
            abort_unless(! $override || $this->canOverrideFieldStatus($actor, $tenant), 403);
            $row = $this->row($tenant, 'pitch_requests', $requestId);
            $before = $this->projection->stored('request', $row);
            $this->revision($before, $data);
            abort_unless($row->status === 'pending', 409, __('Über diesen Antrag wurde bereits entschieden.'));
            $this->activeTeam($tenant, (int) $row->team_season_id);
            $after = array_replace($before, ['status' => $data['decision'], 'revision' => (string) ($row->revision + 1)]);
            if ($data['decision'] === 'approved') $this->ensureFree($tenant, $after, $override);
            if ($override) $after['override_field_status'] = true;
            $this->update($tenant, 'pitch_requests', $requestId, ['status' => $data['decision'], 'decided_by' => $actor->id, 'revision' => $row->revision + 1]);
            $this->audit($actor, $tenant, $before, $after, $data['decision']);
            return $after;
        });
    }

    public function move(Account $actor, Tenant $tenant, string $key, array $input): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $key, $input) {
            $before = $this->resolve($tenant, $key);
            $module = match ($before['source']) { 'training' => 'training_slots', 'block' => 'facilities', default => 'competitions' };
            abort_unless($this->office($actor, $tenant, $module), 403);
            abort_if(in_array($before['status'], ['cancelled', 'rejected'], true), 409);
            $this->revision($before, $input);
            $data = $this->placement($tenant, $input, $before['source'] === 'block');
            if (array_intersect_key($before, $data) == $data) return $before;
            $after = array_replace($before, $data, $this->times($data));
            $this->ensureFree($tenant, $after);
            $this->persistPlacement($tenant, $before, $data);
            $after = $this->resolve($tenant, $key);
            $this->audit($actor, $tenant, $before, $after, 'moved');
            return $after;
        });
    }

    public function cancel(Account $actor, Tenant $tenant, string $key, array $input): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $key, $input) {
            $before = $this->resolve($tenant, $key);
            abort_unless(in_array($before['source'], ['training', 'request', 'block'], true), 422);
            $module = match ($before['source']) { 'training' => 'training_slots', 'block' => 'facilities', default => 'competitions' };
            abort_unless($this->office($actor, $tenant, $module, 'deactivate'), 403);
            $this->revision($before, $input);
            abort_if($before['status'] === 'cancelled', 409);
            $placement = array_intersect_key($before, array_flip(['field_id', 'area_id', 'date', 'starts_at', 'duration_minutes']));
            if ($before['source'] === 'training') $this->persistPlacement($tenant, $before, $placement, true);
            else $this->update($tenant, $before['source'] === 'block' ? 'pitch_blocks' : 'pitch_requests', $before['source_id'], ['status' => 'cancelled', 'revision' => (int) $before['revision'] + 1]);
            $after = $this->resolve($tenant, $key);
            $this->audit($actor, $tenant, $before, $after, 'cancelled');
            return $after;
        });
    }

    public function saveBlock(Account $actor, Tenant $tenant, array $input): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $input) {
            abort_unless($this->office($actor, $tenant, 'facilities', empty($input['id']) ? 'create' : 'update'), 403);
            $data = Validator::make($input, ['reason' => 'required|string|max:255'])->validate() + $this->placement($tenant, $input, true);
            $before = empty($input['id']) ? null : $this->resolve($tenant, 'block:'.(int) $input['id']);
            if ($before) $this->revision($before, $input);
            $data += ['revision' => $before ? (int) $before['revision'] + 1 : 1, 'status' => 'approved'];
            // Closures may cover existing bookings; retain those bookings and flag them.
            $id = $before ? $before['source_id'] : $this->insert($tenant, 'pitch_blocks', $data);
            if ($before) $this->update($tenant, 'pitch_blocks', $id, $data);
            $after = $this->resolve($tenant, 'block:'.$id);
            $this->audit($actor, $tenant, $before, $after, $before ? 'moved' : 'created');
            return $after;
        });
    }

    public function saveArea(Account $actor, Tenant $tenant, array $input): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $input) {
            abort_unless($this->office($actor, $tenant, 'facilities', empty($input['id']) ? 'create' : 'update'), 403);
            $data = Validator::make($input, ['field_id' => 'required|integer|min:1', 'name_de' => 'required|string|max:160', 'name_en' => 'required|string|max:160',
                'x' => 'required|numeric|decimal:0,6|min:0|max:1', 'y' => 'required|numeric|decimal:0,6|min:0|max:1',
                'width' => 'required|numeric|decimal:0,6|gt:0|max:1', 'height' => 'required|numeric|decimal:0,6|gt:0|max:1'])->validate();
            if ($data['x'] + $data['width'] > 1 || $data['y'] + $data['height'] > 1) throw ValidationException::withMessages(['width' => __('Die Teilfläche muss innerhalb des Spielfelds liegen.')]);
            abort_unless($this->projection->table('training_options', $tenant)->where('id', $data['field_id'])->where('category', 'field')->where('active', true)->exists(), 404);
            $before = empty($input['id']) ? null : $this->row($tenant, 'pitch_areas', (int) $input['id']);
            if ($before) {
                $this->revision(['revision' => (string) $before->revision], $input);
                // Geometry referenced by bookings is immutable. Rename only; define a new
                // area and move bookings explicitly to avoid introducing hidden conflicts.
                $used = false;
                if (app(PitchWeeklyAreas::class)->ready()) $used = $this->projection->table('training_slots', $tenant)->where('area_id', $before->id)->exists();
                foreach (['pitch_training_exceptions', 'pitch_requests', 'pitch_blocks', 'pitch_competition_settings'] as $table) $used = $used || $this->projection->table($table, $tenant)->where('area_id', $before->id)->exists();
                foreach (['field_id', 'x', 'y', 'width', 'height'] as $field) if ($used && (float) $data[$field] !== (float) $before->$field) abort(409, __('Eine belegte Teilfläche kann nicht verändert werden. Bitte eine neue Teilfläche anlegen.'));
            }
            $data['revision'] = ($before?->revision ?? 0) + 1;
            $id = $before?->id ?? $this->insert($tenant, 'pitch_areas', $data);
            if ($before) $this->update($tenant, 'pitch_areas', $id, $data);
            $after = $this->projection->area($this->row($tenant, 'pitch_areas', $id));
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'pitch_areas', 'object_id' => $id, 'before_value' => $before ? json_encode($this->projection->area($before), JSON_THROW_ON_ERROR) : null,
                'after_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
            return $after;
        });
    }

    /** Explicit team mapping, never inferred from names; duration/area are local metadata. */
    public function configureCompetition(Account $actor, Tenant $tenant, int $id, array $input): array
    {
        return $this->write($actor, $tenant, function ($actor) use ($tenant, $id, $input) {
            abort_unless($this->office($actor, $tenant), 403);
            $before = $this->resolve($tenant, 'competition:'.$id);
            $this->revision($before, $input);
            $data = Validator::make($input, ['team_season_ids' => 'present|array|max:20', 'team_season_ids.*' => 'integer|distinct|min:1'])->validate();
            foreach ($data['team_season_ids'] as $team) $this->activeTeam($tenant, (int) $team);
            $this->projection->table('pitch_competition_teams', $tenant)->where('competition_id', $id)->delete();
            foreach ($data['team_season_ids'] as $team) $this->projection->table('pitch_competition_teams', $tenant)->insert(['tenant_id' => $tenant->id, 'competition_id' => $id, 'team_season_id' => $team]);
            $settings = $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $id)->first();
            $this->saveSettings($tenant, $id, ['revision' => ($settings?->revision ?? 0) + 1]);
            $after = $this->resolve($tenant, 'competition:'.$id);
            $this->audit($actor, $tenant, $before, $after, 'configured');
            return $after;
        });
    }

    /** Called only by the reviewed SFV importer inside its tenant-locked transaction. */
    public function sfvUpdated(Account $actor, Tenant $tenant, Competition $competition, ?array $before, bool $withdrawn): void
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0 && (int) $competition->tenant_id === (int) $tenant->id, 403);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'sync');
        $settings = $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $competition->id)->first();
        $area = $settings?->area_id;
        if ($area && ! $this->projection->table('pitch_areas', $tenant)->where('id', $area)->where('field_id', $competition->field_id)->exists()) $area = null;
        $this->saveSettings($tenant, $competition->id, ['area_id' => $area, 'withdrawn' => $withdrawn, 'revision' => ($settings?->revision ?? 0) + 1]);
        $match = $this->projection->table('sfv_schedule', $tenant)->where('match_id', $competition->association_match_id)->first();
        $teamIds = array_filter([(int) ($match?->team_a_id ?? 0), (int) ($match?->team_b_id ?? 0)]);
        if ($teamIds) {
            $teams = DB::connection('clubunio')->table('clubora.team_seasons as ts')
                ->join('clubora.teams as t', fn ($join) => $join->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'))
                ->join('clubora.seasons as s', fn ($join) => $join->on('s.id', '=', 'ts.season_id')->on('s.tenant_id', '=', 'ts.tenant_id'))
                ->where('ts.tenant_id', $tenant->id)->where('ts.active', true)->where('t.active', true)->where('s.active', true)
                ->whereIn('t.sfv_team_id', $teamIds)->where('s.starts_on', '<=', $competition->plays_on->toDateString())->where('s.ends_on', '>=', $competition->plays_on->toDateString())
                ->pluck('ts.id');
            $this->projection->table('pitch_competition_teams', $tenant)->where('competition_id', $competition->id)->delete();
            foreach ($teams as $team) $this->projection->table('pitch_competition_teams', $tenant)->insert(['tenant_id' => $tenant->id, 'competition_id' => $competition->id, 'team_season_id' => $team]);
        }
        $after = $this->resolve($tenant, 'competition:'.$competition->id);
        $after['conflicts'] = $this->conflicts($tenant, $after);
        // Federation placements are authoritative, including when a local booking overlaps.
        $changed = $before && array_intersect_key($before, array_flip(['date', 'starts_at', 'field_id', 'status'])) !== array_intersect_key($after, array_flip(['date', 'starts_at', 'field_id', 'status']));
        $action = $withdrawn ? 'cancelled' : (! $before ? 'created' : ($changed ? 'sfv_moved' : 'sfv_updated'));
        $this->audit($actor, $tenant, $before, $after, $action);
    }

    public function resolve(Tenant $tenant, string $key): array
    {
        abort_unless(preg_match('/^(training|competition|request|block):([1-9][0-9]*)(?::(\d{4}-\d{2}-\d{2}))?$/D', $key, $parts), 404);
        [$source, $id] = [$parts[1], (int) $parts[2]];
        if ($source === 'training') {
            abort_unless(isset($parts[3]), 404);
            Validator::make(['original_date' => $parts[3]], ['original_date' => 'required|date_format:Y-m-d'])->validate();
            $slot = TrainingSlot::where('tenant_id', $tenant->id)->where('active', true)->with(['weekday', 'teamSeason.team', 'teamSeason.season'])->findOrFail($id);
            abort_unless($this->projection->occurs($slot, $parts[3]), 404);
            $exception = $this->projection->table('pitch_training_exceptions', $tenant)->where('training_slot_id', $id)->where('original_date', $parts[3])->first();
            $entry = $this->projection->training($slot, $parts[3], $exception);
            abort_unless($entry, 422);
            if ($exception?->cancelled) $entry['status'] = 'cancelled';
            return $entry;
        }
        abort_if(isset($parts[3]), 404);
        if ($source === 'competition') {
            $row = Competition::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($id);
            abort_unless($row->field_id && $row->starts_at, 422);
            return $this->projection->competition($row, $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $id)->first(),
                $this->projection->table('pitch_competition_teams', $tenant)->where('competition_id', $id)->pluck('team_season_id')->all());
        }
        return $this->projection->stored($source, $this->row($tenant, $source === 'request' ? 'pitch_requests' : 'pitch_blocks', $id));
    }

    private function persistPlacement(Tenant $tenant, array $before, array $data, bool $cancel = false): void
    {
        $id = $before['source_id'];
        if ($before['source'] === 'training') {
            $row = $this->projection->table('pitch_training_exceptions', $tenant)->where('training_slot_id', $id)->where('original_date', $before['original_date'])->first();
            $data += ['training_slot_id' => $id, 'original_date' => $before['original_date'], 'cancelled' => $cancel, 'revision' => ($row?->revision ?? 0) + 1];
            if ($row) $this->update($tenant, 'pitch_training_exceptions', $row->id, $data);
            else $this->insert($tenant, 'pitch_training_exceptions', $data);
        } elseif ($before['source'] === 'competition') {
            $row = Competition::where('tenant_id', $tenant->id)->findOrFail($id);
            $row->forceFill(['plays_on' => $data['date'], 'starts_at' => $data['starts_at'], 'field_id' => $data['field_id'], 'revision' => $row->revision + 1])->save();
            $settings = $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $id)->first();
            $this->saveSettings($tenant, $id, ['area_id' => $data['area_id'], 'duration_minutes' => $data['duration_minutes'], 'revision' => ($settings?->revision ?? 0) + 1]);
        } else {
            $this->update($tenant, $before['source'] === 'request' ? 'pitch_requests' : 'pitch_blocks', $id, $data + ['revision' => (int) $before['revision'] + 1]);
        }
    }

    private function saveSettings(Tenant $tenant, int $id, array $data): void
    {
        $row = $this->projection->table('pitch_competition_settings', $tenant)->where('competition_id', $id)->first();
        if ($row) $this->update($tenant, 'pitch_competition_settings', $row->id, $data);
        else $this->insert($tenant, 'pitch_competition_settings', $data + ['competition_id' => $id]);
    }

    private function conflicts(Tenant $tenant, array $entry): array
    {
        $to = Time::parse($entry['ends_at_iso'])->toDateString();
        return PitchPlanningGeometry::conflicts($entry, $this->projection->entries($tenant, $entry['date'], $to), $this->projection->fields($tenant));
    }

    private function ensureFree(Tenant $tenant, array $entry, bool $overrideFieldStatus = false): void
    {
        if ($overrideFieldStatus) {
            // An absent/archived field is not a temporary availability status.
            abort_unless($this->projection->table('training_options', $tenant)->where('id', $entry['field_id'])
                ->where('category', 'field')->where('active', true)->exists(), 404);
        }
        $conflicts = $this->conflicts($tenant, $entry);
        if ($overrideFieldStatus) $conflicts = array_filter($conflicts, fn ($conflict) => $conflict['type'] !== 'field_unavailable');
        abort_if($conflicts !== [], 409, __('Das ausgewählte Spielfeld oder die Teilfläche ist nicht verfügbar.'));
    }

    private function times(array $data): array
    {
        $start = Time::parse($data['date'].' '.$data['starts_at'], 'Europe/Zurich');
        $end = $start->addMinutes($data['duration_minutes']);
        return ['ends_at' => $end->format('H:i'), 'starts_at_iso' => $start->toIso8601String(), 'ends_at_iso' => $end->toIso8601String()];
    }

    private function revision(array $entry, array $input): void
    {
        abort_unless(isset($input['revision']) && hash_equals($entry['revision'], (string) $input['revision']), 409, __('Die Belegung wurde geändert. Bitte die Platzplanung neu laden.'));
    }

    private function row(Tenant $tenant, string $table, int $id): object
    {
        $row = $this->projection->table($table, $tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function insert(Tenant $tenant, string $table, array $data): int
    {
        return $this->projection->table($table, $tenant)->insertGetId($data + ['tenant_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function update(Tenant $tenant, string $table, int $id, array $data): void
    {
        $this->projection->table($table, $tenant)->where('id', $id)->update($data + ['updated_at' => now()]);
    }

    private function audit(Account $actor, Tenant $tenant, ?array $before, array $after, string $action): void
    {
        $teams = array_merge($before['team_season_ids'] ?? [], $after['team_season_ids']);
        if ($after['source'] === 'block') {
            $areas = [];
            foreach ($this->projection->fields($tenant) as $field) foreach ($field['areas'] as $area) $areas[$area['id']] = $area;
            foreach (array_filter([$before, $after]) as $interval) {
                foreach ($this->projection->entries($tenant, $interval['date'], Time::parse($interval['ends_at_iso'])->toDateString()) as $entry) {
                    if ($entry['status'] === 'approved' && PitchPlanningGeometry::overlaps($interval, $entry, $areas)) $teams = array_merge($teams, $entry['team_season_ids']);
                }
            }
        }
        $event = ['event_key' => $after['key'].':'.$after['revision'].':'.$action, 'action' => $action, 'actor_id' => (int) $actor->id,
            'requester_account_id' => $after['requester_account_id'] ?? $before['requester_account_id'] ?? null,
            'team_season_ids' => array_values(array_unique($teams)), 'before' => $before, 'after' => $after];
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'pitch_planning', 'object_id' => $after['source_id'], 'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_value' => json_encode($event, JSON_THROW_ON_ERROR)]);
        if (in_array($action, ['requested', 'moved', 'approved', 'rejected', 'sfv_moved', 'created', 'cancelled'], true)) app(PitchPlanningNotificationService::class)->record($tenant, $event);
    }

}
