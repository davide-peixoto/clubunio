<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\SponsorRunEvent;
use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Models\SponsorRunPledge;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SponsorRunService
{
    public function db()
    {
        return DB::connection('clubunio');
    }

    public function allows(Account $actor, Tenant $tenant, string $function = 'view'): bool
    {
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'sponsor_runs', $function);
    }

    public function authorize(Account $actor, Tenant $tenant, string $function = 'view'): void
    {
        abort_unless($this->allows($actor, $tenant, $function), 403);
    }

    public function choices(Account $actor, Tenant $tenant, ?int $season = null): array
    {
        $this->authorize($actor, $tenant);

        return ['seasons' => $this->db()->table('clubora.seasons')->where('tenant_id', $tenant->id)->where('active', true)->orderByDesc('id')->pluck('name', 'id')->all(),
            'statuses' => $this->db()->table('clubora.sponsor_run_statuses')->where('tenant_id', $tenant->id)->where('active', true)->orderBy('sort_order')->pluck('name', 'code')->all(),
            'teams' => $season ? $this->teams($tenant, $season)->orderBy('st.label')->pluck('st.label', 'st.id')->all() : []];
    }

    private function teams(Tenant $tenant, int $season)
    {
        return $this->db()->table('clubora.team_seasons as st')->join('clubora.teams as t', fn ($j) => $j->on('t.id', '=', 'st.team_id')->on('t.tenant_id', '=', 'st.tenant_id'))
            ->where('st.tenant_id', $tenant->id)->where('st.season_id', $season)->where('st.active', true)->where('t.active', true);
    }

    public function saveEvent(Account $actor, Tenant $tenant, ?int $id, array $input): SponsorRunEvent
    {
        return $this->db()->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant, $id ? 'update' : 'create');
            $data = Validator::make($input, [
                'season_id' => 'required|integer|min:1', 'title' => 'required|string|max:160', 'place' => 'required|string|max:160',
                'event_date' => 'required|date_format:Y-m-d', 'registration_deadline' => 'required|date_format:Y-m-d|before_or_equal:event_date',
                'status_code' => ['required', Rule::in(['draft', 'open', 'closed', 'cancelled'])],
                'team_ids' => 'required|array|min:1|max:500', 'team_ids.*' => 'required|integer|min:1|distinct',
                'revision' => 'required|integer|min:0', 'reason' => ($id ? 'required' : 'nullable').'|string|max:1000',
            ])->validate();
            abort_unless($this->db()->table('clubora.seasons')->where('tenant_id', $tenant->id)->where('id', $data['season_id'])->where('active', true)->exists(), 422);
            abort_unless($this->db()->table('clubora.sponsor_run_statuses')->where('tenant_id', $tenant->id)->where('code', $data['status_code'])->where('active', true)->exists(), 422);
            $ids = array_map('intval', $data['team_ids']);
            abort_unless($this->teams($tenant, (int) $data['season_id'])->whereIn('st.id', $ids)->count() === count($ids), 422);
            $event = $id ? $this->event($tenant, $id, true) : new SponsorRunEvent;
            $before = $event->exists ? $event->toArray() : null;
            if ($id) {
                $this->revision($event, (int) $data['revision']);
                abort_if($event->status_code === 'cancelled', 409, 'Absage ist endgültig.');
                abort_unless((int) $event->season_id === (int) $data['season_id'], 409, 'Saison ist unveraenderlich.');
                abort_if($event->status_code !== 'draft' && $data['status_code'] === 'draft', 409);
                if ($data['status_code'] === 'cancelled') {
                    $this->authorize($actor, $tenant, 'cancel');
                }
                // Past rosters and event dates cannot be rewritten to reopen operations.
                if ($event->event_date->toDateString() < $this->today() || SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $id)->whereNotNull('approved_at')->exists()) {
                    $oldIds = $this->db()->table('clubora.sponsor_run_event_teams')->where('tenant_id', $tenant->id)->where('event_id', $id)->orderBy('team_season_id')->pluck('team_season_id')->map(fn ($v) => (int) $v)->all();
                    sort($ids);
                    abort_unless($oldIds === $ids && $event->event_date->toDateString() === $data['event_date'] && $event->registration_deadline->toDateString() === $data['registration_deadline'], 409);
                }
            } else {
                abort_unless($data['revision'] === 0 || $data['revision'] === '0', 409);
                if ($data['status_code'] === 'cancelled') {
                    $this->authorize($actor, $tenant, 'cancel');
                }
            }
            $event->forceFill(collect($data)->only(['season_id', 'title', 'place', 'event_date', 'registration_deadline', 'status_code'])->all() +
                ['tenant_id' => $tenant->id, 'revision' => $id ? $event->revision + 1 : 1, 'created_by' => $id ? $event->created_by : $actor->id])->save();
            if (! $id || $event->event_date->toDateString() >= $this->today()) {
                if (! SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $event->id)->whereNotNull('approved_at')->exists()) {
                    $this->snapshotRoster($tenant, $event, $ids);
                }
            }
            $this->audit($event, $actor, $id ? 'event_changed' : 'event_created', $before, $event->toArray(), $data['reason'] ?? null);

            return $event;
        });
    }

    private function snapshotRoster(Tenant $tenant, SponsorRunEvent $event, array $ids): void
    {
        $db = $this->db();
        $db->table('clubora.sponsor_run_event_teams')->where('tenant_id', $tenant->id)->where('event_id', $event->id)->delete();
        $db->table('clubora.sponsor_run_event_teams')->insert(array_map(fn ($id) => ['tenant_id' => $tenant->id, 'event_id' => $event->id, 'team_season_id' => $id], $ids));
        $roster = $db->table('clubora.team_players')->where('tenant_id', $tenant->id)->where('active', true)->whereIn('team_season_id', $ids)
            ->whereIn('member_id', app(MemberAccessService::class)->activeMembers($tenant)->select('clubora.members.id'))
            ->selectRaw('member_id, min(team_season_id) as team_season_id')->groupBy('member_id')->get();
        $existing = SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $event->id)->lockForUpdate()->get()->keyBy('member_id');
        foreach ($roster as $row) {
            $p = $existing->get($row->member_id) ?? new SponsorRunParticipant;
            if (! $p->exists || ! $p->required || (int) $p->team_season_id !== (int) $row->team_season_id) {
                $p->forceFill(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'member_id' => $row->member_id,
                    'team_season_id' => $row->team_season_id, 'required' => true, 'revision' => $p->exists ? $p->revision + 1 : 1])->save();
            }
        }
        $retired = $existing->whereNotIn('member_id', $roster->pluck('member_id'))->where('required', true)->pluck('id');
        if ($retired->isNotEmpty()) {
            SponsorRunParticipant::where('tenant_id', $tenant->id)->whereIn('id', $retired)->update(['required' => false, 'revision' => $db->raw('revision+1'), 'updated_at' => now()]);
        }
    }

    public function cancelEvent(Account $actor, Tenant $tenant, int $id, int $revision, string $reason): SponsorRunEvent
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:1000'])->validate();

        return $this->db()->transaction(function () use ($actor, $tenant, $id, $revision, $reason) {
            $event = $this->event($tenant, $id, true);
            $this->authorize($actor, $tenant, 'cancel');
            $this->revision($event, $revision);
            abort_if($event->status_code === 'cancelled', 409);
            $before = $event->toArray();
            $event->forceFill(['status_code' => 'cancelled', 'revision' => $event->revision + 1])->save();
            $this->audit($event, $actor, 'event_cancelled', $before, $event->toArray(), trim($reason));

            return $event;
        });
    }

    public function event(Tenant $tenant, int $id, bool $lock = false): SponsorRunEvent
    {
        return SponsorRunEvent::where('tenant_id', $tenant->id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function events(Account $actor, Tenant $tenant, array $filters = [])
    {
        $manage = $this->allows($actor, $tenant);
        abort_unless($manage || app(MemberAccessService::class)->canEnter($actor, $tenant), 403);
        $query = SponsorRunEvent::where('tenant_id', $tenant->id);
        if (! $manage) {
            $query->where('status_code', '!=', 'draft');
        }
        if (! $manage || ! in_array($filters['scope'] ?? 'all', ['all', 'manage'], true)) {
            $query->whereIn('id', $this->visibleParticipants($actor, $tenant, $filters['scope'] ?? 'mine')->select('p.event_id'));
        }

        return $query->when($filters['season_id'] ?? null, fn ($q, $id) => $q->where('season_id', $id))
            ->when($filters['status_code'] ?? null, fn ($q, $code) => $q->where('status_code', $code))
            ->when(trim($filters['search'] ?? ''), fn ($q, $s) => $q->where('title', 'ilike', '%'.mb_substr($s, 0, 100).'%'))->orderByDesc('event_date')->orderByDesc('id');
    }

    private function helper(Account $actor, Tenant $tenant, int $eventId, ?string $right = null): bool
    {
        if (! app(MemberAccessService::class)->membership($actor, $tenant)) {
            return false;
        }

        return $this->db()->table('clubora.sponsor_run_helpers')->where('tenant_id', $tenant->id)->where('event_id', $eventId)->where('account_id', $actor->id)
            ->where('valid_from', '<=', now()->toIso8601String())->where('valid_until', '>=', now()->toIso8601String())
            ->when($right, fn ($q) => $q->where($right, true), fn ($q) => $q->where(fn ($q) => $q->where('can_checkin', true)->orWhere('can_record_laps', true)))->exists();
    }

    private function visibleParticipants(Account $actor, Tenant $tenant, string $scope = 'all')
    {
        abort_unless(in_array($scope, ['all', 'manage', 'mine', 'teams', 'helper'], true), 422);
        $access = app(MemberAccessService::class);
        $q = $this->db()->table('clubora.sponsor_run_participants as p')->where('p.tenant_id', $tenant->id);
        if (in_array($scope, ['all', 'manage'], true) && $this->allows($actor, $tenant)) {
            return $q;
        }
        abort_unless($access->canEnter($actor, $tenant), 403);
        if ($scope === 'manage') {
            abort(403);
        }
        $q->whereExists(fn ($q) => $q->selectRaw('1')->from('clubora.sponsor_run_events as e')->whereColumn('e.id', 'p.event_id')->whereColumn('e.tenant_id', 'p.tenant_id')->where('e.status_code', '!=', 'draft'));

        return $q->where(function ($q) use ($scope, $access, $actor, $tenant) {
            $q->whereRaw('1=0');
            if (in_array($scope, ['all', 'mine'], true)) {
                $q->orWhereIn('p.member_id', $access->represented($actor, $tenant)->select('clubora.members.id'));
            }
            if (in_array($scope, ['all', 'teams'], true)) {
                $q->orWhereIn('p.member_id', $this->db()->table('clubora.team_players')->where('tenant_id', $tenant->id)->where('active', true)
                    ->whereIn('team_season_id', $access->responsibleTeams($actor, $tenant))->whereIn('team_season_id', $this->db()->table('clubora.sponsor_run_event_teams as et')->whereColumn('et.event_id', 'p.event_id')->where('et.tenant_id', $tenant->id)->select('et.team_season_id'))->select('member_id'));
            }
            if (in_array($scope, ['all', 'helper'], true)) {
                $q->orWhereIn('p.event_id', $this->db()->table('clubora.sponsor_run_helpers')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
                    ->where('valid_from', '<=', now()->toIso8601String())->where('valid_until', '>=', now()->toIso8601String())->where(fn ($q) => $q->where('can_checkin', true)->orWhere('can_record_laps', true))->select('event_id'));
            }
        });
    }

    public function participants(Account $actor, Tenant $tenant, int $eventId, array $filters = [])
    {
        $event = $this->event($tenant, $eventId);
        $q = $this->visibleParticipants($actor, $tenant, $filters['scope'] ?? 'all')->where('p.event_id', $eventId)
            ->join('clubora.members as m', fn ($j) => $j->on('m.id', '=', 'p.member_id')->on('m.tenant_id', '=', 'p.tenant_id'))
            ->join('clubora.team_seasons as t', fn ($j) => $j->on('t.id', '=', 'p.team_season_id')->on('t.tenant_id', '=', 'p.tenant_id'))
            ->select('p.id', 'p.member_id', 'p.team_season_id', 'p.required', 'p.checked_in_at', 'p.draft_laps', 'p.final_laps', 'p.approved_at', 'p.revision', 'm.first_name', 'm.last_name', 't.label as team_label');

        return $q->when($filters['team_season_id'] ?? null, fn ($q, $id) => $q->where('p.team_season_id', $id))
            ->when(trim($filters['search'] ?? ''), fn ($q, $s) => $q->whereRaw("concat_ws(' ',m.first_name,m.last_name) ILIKE ?", ['%'.mb_substr($s, 0, 100).'%']))->orderBy('m.last_name')->orderBy('m.first_name')->orderBy('p.id');
    }

    public function canViewPledges(Account $actor, Tenant $tenant, SponsorRunParticipant $p): bool
    {
        if ($this->allows($actor, $tenant)) {
            return true;
        }

        return $this->visibleParticipants($actor, $tenant, 'mine')->where('p.id', $p->id)->exists()
            || $this->visibleParticipants($actor, $tenant, 'teams')->where('p.id', $p->id)->exists();
    }

    public function summaries(Account $actor, Tenant $tenant, array $participantIds): array
    {
        abort_if(count($participantIds) > 100, 422);
        $visible = $this->visibleParticipants($actor, $tenant, $this->allows($actor, $tenant) ? 'manage' : 'all')->whereIn('p.id', $participantIds)->pluck('p.id')->all();
        // Helpers are deliberately excluded even when they can see the operational roster.
        if (! $this->allows($actor, $tenant)) {
            $visible = $this->visibleParticipants($actor, $tenant, 'mine')->whereIn('p.id', $visible)->pluck('p.id')->merge(
                $this->visibleParticipants($actor, $tenant, 'teams')->whereIn('p.id', $visible)->pluck('p.id'))->unique()->all();
        }
        $sum = $this->db()->table('clubora.sponsor_run_pledges as g')->join('clubora.sponsor_run_participants as p', fn ($j) => $j->on('p.id', '=', 'g.participant_id')->on('p.tenant_id', '=', 'g.tenant_id'))
            ->where('g.tenant_id', $tenant->id)->whereIn('g.participant_id', $visible)->groupBy('g.participant_id')
            ->selectRaw("g.participant_id, count(*) as pledge_count, sum(CASE WHEN amount_type='per_lap' THEN amount_cents ELSE 0 END) as per_lap_cents,
                sum(CASE WHEN amount_type='fixed' THEN least(amount_cents,coalesce(cap_cents,amount_cents)) ELSE 0 END) as fixed_cents,
                count(cap_cents) as capped_count, CASE WHEN count(p.final_laps)=count(*) THEN
                sum(least(CASE WHEN amount_type='per_lap' THEN amount_cents*p.final_laps ELSE amount_cents END,coalesce(cap_cents,9223372036854775807))) ELSE NULL END as total_cents")
            ->get()->keyBy('participant_id')->map(fn ($r) => (array) $r)->all();
        foreach ($visible as $id) {
            $sum[$id] ??= ['participant_id' => $id, 'pledge_count' => 0, 'per_lap_cents' => 0, 'fixed_cents' => 0, 'capped_count' => 0, 'total_cents' => null];
        }

        return $sum;
    }

    public function pledges(Account $actor, Tenant $tenant, int $participantId)
    {
        $p = SponsorRunParticipant::where('tenant_id', $tenant->id)->findOrFail($participantId);
        abort_unless($this->canViewPledges($actor, $tenant, $p), 403);

        return SponsorRunPledge::where('tenant_id', $tenant->id)->where('participant_id', $p->id)
            ->select('id', 'participant_id', 'sponsor_name', 'amount_type', 'amount_cents', 'cap_cents', 'payment_mode', 'created_at')->orderBy('id');
    }

    public function operations(Account $actor, Tenant $tenant, int $participantId, int $revision, string $action, mixed $value): SponsorRunParticipant
    {
        abort_unless(in_array($action, ['checkin', 'laps', 'approve'], true), 422);

        return $this->db()->transaction(function () use ($actor, $tenant, $participantId, $revision, $action, $value) {
            [$e, $p] = $this->lockedParticipant($tenant, $participantId);
            if ($action === 'approve') {
                $this->authorize($actor, $tenant, 'approve');
            } else {
                abort_unless($this->allows($actor, $tenant, 'update') || $this->helper($actor, $tenant, $e->id, $action === 'checkin' ? 'can_checkin' : 'can_record_laps'), 403);
            }
            $this->revision($p, $revision);
            abort_unless($p->required && in_array($e->status_code, ['open', 'closed'], true) && $e->event_date->toDateString() <= $this->today() && $p->final_laps === null, 409);
            $before = $p->toArray();
            if ($action === 'checkin') {
                Validator::make(['value' => $value], ['value' => 'required|boolean'])->validate();
                $p->forceFill(['checked_in_at' => $value ? now() : null, 'checked_in_by' => $value ? $actor->id : null]);
            } else {
                $laps = self::integer($value, 0, 10000);
                if ($action === 'approve') {
                    abort_unless($p->draft_laps !== null && $p->draft_laps === $laps, 409, 'Aktuellen Rundenentwurf explizit bestätigen.');
                    $p->forceFill(['final_laps' => $laps, 'approved_at' => now(), 'approved_by' => $actor->id]);
                } else {
                    $p->forceFill(['draft_laps' => $laps]);
                }
            }
            $p->revision++;
            $p->save();
            $this->audit($e, $actor, $action, $before, $p->toArray());

            return $p;
        });
    }

    public function saveHelper(Account $actor, Tenant $tenant, int $eventId, array $input): void
    {
        $data = Validator::make($input, ['account_id' => 'required|integer|min:1', 'can_checkin' => 'required|boolean', 'can_record_laps' => 'required|boolean',
            'valid_from' => 'required|date', 'valid_until' => 'required|date|after_or_equal:valid_from', 'revision' => 'required|integer|min:0'])->validate();
        foreach (['valid_from', 'valid_until'] as $field) {
            $data[$field] = CarbonImmutable::parse($data[$field], 'Europe/Zurich')->toIso8601String();
        }
        $this->db()->transaction(function () use ($actor, $tenant, $eventId, $data) {
            $e = $this->event($tenant, $eventId, true);
            $this->authorize($actor, $tenant, 'update');
            abort_if($e->status_code === 'cancelled', 409);
            $candidate = Account::whereKey($data['account_id'])->where('active', true)->firstOrFail();
            abort_unless(app(MemberAccessService::class)->represented($candidate, $tenant, true)->exists(), 422);
            $table = $this->db()->table('clubora.sponsor_run_helpers')->where('tenant_id', $tenant->id)->where('event_id', $eventId)->where('account_id', $candidate->id);
            $old = (clone $table)->lockForUpdate()->first();
            abort_unless((int) ($old->revision ?? 0) === (int) $data['revision'], 409);
            $row = $data + ['tenant_id' => $tenant->id, 'event_id' => $eventId];
            $row['revision'] = (int) $data['revision'] + 1;
            $old ? $table->update($row) : $this->db()->table('clubora.sponsor_run_helpers')->insert($row);
            $this->audit($e, $actor, 'helper_changed', $old ? (array) $old : null, $row);
        });
    }

    public function helpers(Account $actor, Tenant $tenant, int $eventId): array
    {
        $this->authorize($actor, $tenant);
        $this->event($tenant, $eventId);

        return $this->db()->table('clubora.sponsor_run_helpers as h')->join('clubora.accounts as a', 'a.id', '=', 'h.account_id')->where('h.tenant_id', $tenant->id)->where('h.event_id', $eventId)
            ->select('h.*', 'a.display_name')->orderBy('a.display_name')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function capabilities(Account $actor, Tenant $tenant, int $eventId): array
    {
        $e = $this->event($tenant, $eventId);
        $operational = in_array($e->status_code, ['open', 'closed'], true) && $e->event_date->toDateString() <= $this->today();

        return ['checkin' => $operational && ($this->allows($actor, $tenant, 'update') || $this->helper($actor, $tenant, $eventId, 'can_checkin')),
            'laps' => $operational && ($this->allows($actor, $tenant, 'update') || $this->helper($actor, $tenant, $eventId, 'can_record_laps')),
            'approve' => $operational && $this->allows($actor, $tenant, 'approve')];
    }

    public function lockedParticipant(Tenant $tenant, int $id): array
    {
        $initial = SponsorRunParticipant::where('tenant_id', $tenant->id)->findOrFail($id);
        $event = $this->event($tenant, $initial->event_id, true);
        $p = SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $event->id)->lockForUpdate()->findOrFail($id);

        return [$event, $p];
    }

    public function requireOpen(SponsorRunEvent $event, SponsorRunParticipant $p): void
    {
        abort_unless($event->status_code === 'open' && $event->registration_deadline->toDateString() >= $this->today() && $p->required && $p->approved_at === null, 409, 'Zusagefrist oder Teilnahme ist geschlossen.');
    }

    public function canAddPledge(Account $actor, Tenant $tenant, SponsorRunParticipant $p): bool
    {
        return $this->allows($actor, $tenant, 'create') || (app(MemberAccessService::class)->canEnter($actor, $tenant)
            && $this->visibleParticipants($actor, $tenant, 'mine')->where('p.id', $p->id)->exists());
    }

    public function addPledge(Account $actor, Tenant $tenant, int $participantId, array $input): SponsorRunPledge
    {
        $data = self::pledgeInput($input);

        return $this->db()->transaction(function () use ($actor, $tenant, $participantId, $data) {
            [$e, $p] = $this->lockedParticipant($tenant, $participantId);
            abort_unless($this->canAddPledge($actor, $tenant, $p), 403);
            $this->requireOpen($e, $p);

            return $this->storePledge($tenant, $e, $p, $data, $actor);
        });
    }

    public static function integer(mixed $value, int $min, int $max): int
    {
        abort_unless(is_int($value) || (is_string($value) && preg_match('/^\d+$/D', $value)), 422);
        $int = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($int !== false && $int >= $min && $int <= $max, 422);

        return $int;
    }

    public static function pledgeInput(array $input): array
    {
        $data = Validator::make($input, ['request_key' => 'required|string|regex:/^[A-Za-z0-9-]{16,80}$/D',
            'sponsor_name' => 'required|string|max:160', 'payment_mode' => ['required', Rule::in(['invoice', 'collective'])],
            'amount_type' => ['required', Rule::in(['fixed', 'per_lap'])], 'amount_cents' => 'required', 'cap_cents' => 'nullable',
            'recipient_type' => ['required', Rule::in(['person', 'company'])], 'collector_confirmed' => 'nullable|boolean',
            'company' => 'nullable|string|max:160|required_if:recipient_type,company', 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'street' => 'required|string|max:160', 'house_number' => 'nullable|string|max:20', 'postal_code' => 'required|string|max:20', 'city' => 'required|string|max:100',
            'country' => 'required|string|regex:/^[A-Z]{2}$/D', 'email' => 'required|string|email:rfc|max:254'])->validate();
        $data['amount_cents'] = self::integer($data['amount_cents'], 1, 10000000);
        $data['cap_cents'] = isset($data['cap_cents']) && $data['cap_cents'] !== '' ? self::integer($data['cap_cents'], 1, 10000000) : null;
        $data['collector_confirmed'] = (bool) ($data['collector_confirmed'] ?? false);
        abort_if($data['payment_mode'] === 'collective' && ! $data['collector_confirmed'], 422);
        foreach (['company', 'house_number'] as $key) {
            $data[$key] ??= '';
        }
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                abort_if(preg_match('/[\x00-\x1f]/', $value), 422);
                $data[$key] = trim($value);
            }
        }
        $data['email'] = mb_strtolower($data['email']);
        ksort($data);

        return $data;
    }

    public function storePledge(Tenant $tenant, SponsorRunEvent $event, SponsorRunParticipant $p, array $data, ?Account $actor): SponsorRunPledge
    {
        // Caller holds event -> participant locks and has checked access/open gates.
        abort_unless($this->db()->transactionLevel() > 0 && ($actor || $data['payment_mode'] === 'invoice'), 409);
        $this->db()->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sponsor-run-pledge|'.$tenant->id.'|'.$data['request_key']]);
        $hash = hash('sha256', json_encode([$p->id, $actor?->id, $data], JSON_THROW_ON_ERROR));
        $old = SponsorRunPledge::where('tenant_id', $tenant->id)->where('request_key', $data['request_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Anfragekennung bereits verwendet.');

            return $old;
        }
        $recipient = collect($data)->only(['company', 'first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city', 'country', 'email'])->all();
        $pledge = new SponsorRunPledge;
        $pledge->forceFill(collect($data)->only(['sponsor_name', 'payment_mode', 'amount_type', 'amount_cents', 'cap_cents', 'recipient_type', 'request_key'])->all() +
            ['tenant_id' => $tenant->id, 'event_id' => $event->id, 'participant_id' => $p->id, 'payload_hash' => $hash,
                'recipient_encrypted' => Crypt::encryptString(json_encode($recipient, JSON_THROW_ON_ERROR)), 'created_by' => $actor?->id,
                'collector_account_id' => $data['payment_mode'] === 'collective' ? $actor->id : null,
                'collector_confirmed_at' => $data['payment_mode'] === 'collective' ? now() : null, 'revision' => 1])->save();
        $this->audit($event, $actor, 'pledge_created', null, ['pledge_id' => $pledge->id, 'participant_id' => $p->id]);

        return $pledge;
    }

    public static function totalCents(object|array $pledge, ?int $finalLaps): ?int
    {
        if ($finalLaps === null) {
            return null;
        }
        $row = (array) ($pledge instanceof SponsorRunPledge ? $pledge->getAttributes() : $pledge);
        $laps = self::integer($finalLaps, 0, 10000);
        $amount = self::integer($row['amount_cents'], 1, 10000000);
        $total = $row['amount_type'] === 'per_lap' ? $amount * $laps : $amount;

        return isset($row['cap_cents']) ? min($total, self::integer($row['cap_cents'], 1, 10000000)) : $total;
    }

    public function billingSource(Account $actor, Tenant $tenant, int $pledgeId): array
    {
        // An outer billing transaction retains these locks through invoice snapshot insertion.
        return $this->db()->transaction(function () use ($actor, $tenant, $pledgeId) {
            $this->authorize($actor, $tenant, 'approve');
            $initial = SponsorRunPledge::where('tenant_id', $tenant->id)->findOrFail($pledgeId);
            [$e, $p] = $this->lockedParticipant($tenant, $initial->participant_id);
            $g = SponsorRunPledge::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($pledgeId);
            abort_unless(in_array($e->status_code, ['open', 'closed'], true) && $p->required && $p->final_laps !== null && $p->approved_at !== null && $e->event_date->toDateString() <= $this->today(), 409);
            abort_unless($g->payment_mode === 'invoice' || ($g->collector_account_id && $g->collector_confirmed_at), 409);

            return ['tenant_id' => (int) $tenant->id, 'pledge_id' => (int) $g->id, 'event_id' => (int) $e->id, 'season_id' => (int) $e->season_id,
                'participant_id' => (int) $p->id, 'event_title' => $e->title, 'title' => $e->title,
                'description' => $g->sponsor_name.' / '.$e->title.' / '.$p->final_laps.' Runden', 'event_date' => $e->event_date->toDateString(),
                'payment_mode' => $g->payment_mode, 'amount_type' => $g->amount_type, 'amount_cents' => $g->amount_cents, 'cap_cents' => $g->cap_cents,
                'recipient_type' => $g->recipient_type, 'recipient' => ['type' => $g->recipient_type] + json_decode(Crypt::decryptString($g->recipient_encrypted), true, 512, JSON_THROW_ON_ERROR),
                'collector_account_id' => $g->collector_account_id, 'collector_confirmed_at' => $g->collector_confirmed_at,
                'final_laps' => $p->final_laps, 'approved_at' => $p->approved_at->toIso8601String(), 'total_cents' => self::totalCents($g, $p->final_laps),
                'event_revision' => (int) $e->revision, 'participant_revision' => (int) $p->revision, 'pledge_revision' => (int) $g->revision];
        });
    }

    public function billablePledges(Account $actor, Tenant $tenant, int $eventId): Collection
    {
        $this->authorize($actor, $tenant, 'approve');
        $e = $this->event($tenant, $eventId);
        if (! in_array($e->status_code, ['open', 'closed'], true) || $e->event_date->toDateString() > $this->today()) {
            return collect();
        }

        return $this->db()->table('clubora.sponsor_run_pledges as g')->join('clubora.sponsor_run_participants as p', fn ($j) => $j->on('p.id', '=', 'g.participant_id')->on('p.tenant_id', '=', 'g.tenant_id'))
            ->where('g.tenant_id', $tenant->id)->where('g.event_id', $e->id)->where('p.required', true)->whereNotNull('p.final_laps')->whereNotNull('p.approved_at')
            ->where(fn ($q) => $q->where('g.payment_mode', 'invoice')->orWhere(fn ($q) => $q->whereNotNull('g.collector_account_id')->whereNotNull('g.collector_confirmed_at')))
            ->select('g.id', 'g.sponsor_name', 'g.amount_type', 'g.amount_cents', 'g.cap_cents', 'p.final_laps')->orderBy('g.id')->get()
            ->map(fn ($g) => ['id' => (int) $g->id, 'label' => $g->sponsor_name.' (#'.$g->id.')', 'total_cents' => self::totalCents($g, (int) $g->final_laps)]);
    }

    public function revision(object $row, int $revision): void
    {
        abort_unless((int) $row->revision === $revision, 409, 'Zwischenzeitlich geändert. Bitte neu laden.');
    }

    public function today(): string
    {
        return now('Europe/Zurich')->toDateString();
    }

    public function audit(SponsorRunEvent $event, ?Account $actor, string $action, ?array $before, array $after, ?string $reason = null): void
    {
        $this->db()->table('clubora.sponsor_run_audits')->insert(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'actor_id' => $actor?->id,
            'action' => $action, 'reason' => $reason, 'before_value' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_value' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
