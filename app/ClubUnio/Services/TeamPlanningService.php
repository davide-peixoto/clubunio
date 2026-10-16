<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamPlanningService
{
    public static function seedOptions(int $tenant): void
    {
        $groups = [
            'kind' => ['TRAINING' => 'Training', 'MEISTERSCHAFT' => 'Meisterschaft', 'FREUNDSCHAFTSSPIEL' => 'Freundschaftsspiel', 'CUP' => 'Cup', 'TURNIER' => 'Turnier', 'EVENT' => 'Veranstaltung'],
            'mode' => ['ANMELDEN' => 'Anmelden', 'ABMELDEN' => 'Abmelden'],
            'status' => ['AKTIV' => 'Aktiv', 'ABGESAGT' => 'Abgesagt'],
            'participation' => ['DABEI' => 'Dabei', 'ABWESEND' => 'Abwesend', 'OFFEN' => 'Offen', 'NICHT_AUFGEBOT' => 'Nicht aufgeboten'],
            'attendance' => ['OFFEN' => 'Offen', 'ANWESEND' => 'Anwesend', 'FEHLT' => 'Fehlt', 'ENTSCHULDIGT' => 'Entschuldigt'],
            'absence' => ['ABWESEND' => 'Abwesend', 'KRANK' => 'Krank', 'FERIEN' => 'Ferien', 'SCHULE' => 'Schule', 'ARBEIT' => 'Arbeit', 'VERLETZT' => 'Verletzt', 'ANDERES' => 'Anderes'],
        ];
        foreach ($groups as $category => $values) {
            foreach ($values as $code => $name) {
                DB::connection('clubunio')->table('clubora.planning_options')->insertOrIgnore(compact('category', 'code', 'name') + ['tenant_id' => $tenant]);
            }
        }
    }

    public function table(Tenant $tenant, string $table)
    {
        return DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id);
    }

    public function enter(Account $actor, Tenant $tenant): void
    {
        $this->authorizeOperation($actor, $tenant, 'view');
    }

    public function authorizeOperation(Account $actor, Tenant $tenant, string $function): void
    {
        abort_unless(array_key_exists($function, PermissionCatalog::functions('planning')), 403);
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'planning', $function)) {
            return;
        }
        abort_if(app(ModulePermissionService::class)->isProfile($actor, $tenant), 403);
        $fresh = $actor->fresh();
        $access = app(MemberAccessService::class);
        abort_unless($fresh?->active
            && in_array($access->membership($fresh, $tenant)?->role, ['member', 'parent', 'trainer', 'technical_lead'], true)
            && $access->canEnter($fresh, $tenant), 403);
    }

    public function teams(Account $actor, Tenant $tenant, bool $manage = false, string $function = 'update')
    {
        $this->enter($actor, $tenant);
        $q = $this->table($tenant, 'team_seasons')->where('active', true);
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'planning', $manage ? $function : 'view')) {
            return $q;
        }
        if (app(MemberAccessService::class)->membership($actor->fresh(), $tenant)?->role === 'profile') {
            return $q->whereRaw('1=0');
        }
        $access = app(MemberAccessService::class);
        $responsible = $access->responsibleTeams($actor, $tenant);
        $own = $manage ? null : $access->represented($actor, $tenant)->select('clubora.members.id');

        return $q->where(function ($q) use ($tenant, $responsible, $own, $manage) {
            $q->whereIn('id', $responsible);
            if (! $manage) {
                foreach (['team_players', 'team_staff'] as $table) {
                    $q->orWhereIn('id', $this->table($tenant, $table)->where('active', true)->whereIn('member_id', $own)->select('team_season_id'));
                }
            }
        });
    }

    public function event(Account $actor, Tenant $tenant, int $id, bool $manage = false, string $function = 'update'): object
    {
        $this->enter($actor, $tenant);
        $row = $this->table($tenant, 'team_events')->where('id', $id)->first();
        $invited = ! $manage && $this->invitedEvents($actor, $tenant)->where('event_id', $id)->exists();
        abort_unless($row && ($invited || $this->teams($actor, $tenant, $manage, $function)->where('id', $row->team_season_id)->exists()), 404);

        return $row;
    }

    public function invitedEvents(Account $actor, Tenant $tenant)
    {
        $this->enter($actor, $tenant);
        if (app(MemberAccessService::class)->membership($actor->fresh(), $tenant)?->role === 'profile') {
            return $this->table($tenant, 'team_invitations')->whereRaw('1=0');
        }
        return $this->table($tenant, 'team_invitations')->whereIn('member_id', app(MemberAccessService::class)->represented($actor, $tenant)->select('clubora.members.id'));
    }

    public function options(Tenant $tenant): array
    {
        return $this->table($tenant, 'planning_options')->orderBy('sort_order')->orderBy('name')->get()->groupBy('category')->map(fn ($rows) => $rows->keyBy('code'))->all();
    }

    private function optionRule(Tenant $tenant, string $category): array
    {
        return ['required', Rule::in($this->table($tenant, 'planning_options')->where('category', $category)->where('active', true)->pluck('code')->all())];
    }

    public function conflict(?object $row, array $input): void
    {
        if ($row && (int) ($input['revision'] ?? 0) !== $row->revision) {
            throw ValidationException::withMessages(['revision' => 'Dieser Eintrag wurde inzwischen geändert. Bitte neu öffnen.']);
        }
    }

    public function lock(Tenant $tenant): void
    {
        // All event, participation and absence writes share a lock to make reconciliation atomic.
        DB::connection('clubunio')->table('clubora.tenants')->where('id', $tenant->id)->lockForUpdate()->firstOrFail();
    }

    public function saveEvent(Account $actor, Tenant $tenant, ?int $id, array $input, bool $seriesWrite = false): int
    {
        $this->authorizeOperation($actor, $tenant, $id ? 'update' : 'create');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input, $seriesWrite) {
            $this->lock($tenant);
            $this->authorizeOperation($actor, $tenant, $id ? 'update' : 'create');
            $old = $id ? $this->event($actor, $tenant, $id, true) : null;
            if (($input['status'] ?? null) === 'ABGESAGT' && $old?->status !== 'ABGESAGT') {
                $this->authorizeOperation($actor, $tenant, 'cancel');
            }
            if ($old?->status === 'ABGESAGT' && ($input['status'] ?? null) === 'AKTIV') {
                $this->authorizeOperation($actor, $tenant, 'restore');
            }
            $this->conflict($old, $input);
            $data = Validator::make($input, [
                'team_season_id' => ['required', 'integer', Rule::in($this->teams($actor, $tenant, true, $id ? 'update' : 'create')->pluck('id')->all())],
                'title' => 'required|string|max:255', 'kind' => $this->optionRule($tenant, 'kind'),
                'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at',
                'deadline_at' => 'required|date_format:Y-m-d\TH:i|before_or_equal:starts_at',
                'location' => 'nullable|string|max:255', 'description' => 'nullable|string|max:4000',
                'mode' => $this->optionRule($tenant, 'mode'), 'status' => $this->optionRule($tenant, 'status'),
                'capacity' => 'nullable|integer|min:1|max:1000',
            ])->validate();
            if ($old && (int) $old->team_season_id !== (int) $data['team_season_id']) {
                throw ValidationException::withMessages(['team_season_id' => 'Für eine andere Mannschaft bitte einen neuen Termin erstellen.']);
            }
            foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) {
                $data[$field] = Time::parse($data[$field], 'Europe/Zurich')->toIso8601String();
            }
            $data['revision'] = ($old->revision ?? 0) + 1;
            $data['updated_at'] = now()->toIso8601String();
            if ($old?->series_key && ! $seriesWrite) {
                $data['series_exception'] = true;
            }
            if ($old) {
                $this->table($tenant, 'team_events')->where('id', $id)->update($data);
            } else {
                $id = $this->table($tenant, 'team_events')->insertGetId($data + ['tenant_id' => $tenant->id]);
            }
            $event = $this->table($tenant, 'team_events')->where('id', $id)->first();
            $this->reconcile($tenant, $event);
            $this->audit($actor, $tenant, 'team_events', $id, $old?->revision, $data['revision']);
            $this->message($tenant, $event, $data['status'] === 'ABGESAGT' ? 'team.cancelled' : ($old ? 'team.changed' : 'team.created'));

            return $id;
        });
    }

    public function createSeries(Account $actor, Tenant $tenant, array $input): array
    {
        $this->authorizeOperation($actor, $tenant, 'create');
        $count = Validator::make($input, ['repeat_count' => 'nullable|integer|min:1|max:26'])->validate()['repeat_count'] ?? 1;

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $input, $count) {
            $ids = [];
            $key = $count > 1 ? (string) Str::uuid() : null;
            // Advance in Swiss calendar weeks, not fixed UTC hours, across daylight-saving changes.
            for ($week = 0; $week < $count; $week++) {
                $row = $input;
                if ($week > 0) {
                    foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) {
                        $row[$field] = Time::parse($input[$field], 'Europe/Zurich')->addWeeks($week)->format('Y-m-d\TH:i');
                    }
                }
                $ids[] = $this->saveEvent($actor, $tenant, null, $row);
            }
            $this->table($tenant, 'team_events')->whereIn('id', $ids)->update(['series_key' => $key]);

            return $ids;
        });
    }

    public function roster(Tenant $tenant, object $event)
    {
        return app(MemberAccessService::class)->activeMembers($tenant)->where(function ($q) use ($tenant, $event) {
            $q->whereIn('id', $this->table($tenant, 'team_invitations')->where('event_id', $event->id)->select('member_id'));
            foreach (['team_players', 'team_staff'] as $table) {
                $q->orWhereIn('id', $this->table($tenant, $table)->where('team_season_id', $event->team_season_id)->where('active', true)->select('member_id'));
            }
        })->orderBy('last_name')->orderBy('first_name');
    }

    public function locked(object $event): bool
    {
        return $event->status !== 'AKTIV' || Time::parse($event->starts_at)->isPast()
            || ($event->deadline_at && Time::parse($event->deadline_at)->isPast());
    }

    public function participate(Account $actor, Tenant $tenant, int $id, int $member, array $input): void
    {
        $this->authorizeOperation($actor, $tenant, 'update');
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $member, $input) {
            $this->lock($tenant);
            $this->authorizeOperation($actor, $tenant, 'update');
            $event = $this->event($actor, $tenant, $id);
            $manager = $this->teams($actor, $tenant, true)->where('id', $event->team_season_id)->exists();
            abort_unless($this->roster($tenant, $event)->whereKey($member)->exists(), 404);
            abort_unless($manager || app(MemberAccessService::class)->represented($actor, $tenant)->whereKey($member)->exists(), 403);
            abort_unless($event->status === 'AKTIV', 409);
            if (! $manager && $this->locked($event)) {
                throw ValidationException::withMessages(['status' => 'Die Frist ist abgelaufen. Bitte das Trainerteam kontaktieren.']);
            }
            $query = $this->table($tenant, 'team_participations')->where('event_id', $id)->where('member_id', $member);
            $old = (clone $query)->first();
            $this->conflict($old, $input);
            if (! $old && (int) ($input['revision'] ?? 0) !== 0) {
                abort(409);
            }
            if (! $manager && $old?->status === 'NICHT_AUFGEBOT') {
                abort(403);
            }
            $data = Validator::make($input, ['status' => $this->optionRule($tenant, 'participation'),
                'attendance' => $manager ? $this->optionRule($tenant, 'attendance') : 'prohibited',
                'comment' => 'nullable|string|max:1000'])->validate();
            if (! $manager && ! in_array($data['status'], ['DABEI', 'ABWESEND'], true)) {
                abort(403);
            }
            if ($data['status'] === 'DABEI' && $event->capacity) {
                $count = $this->effectiveCount($tenant, $event, $member);
                if ($count >= $event->capacity) {
                    throw ValidationException::withMessages(['status' => 'Die maximale Teilnehmerzahl ist erreicht.']);
                }
            }
            $data['comment_encrypted'] = empty($data['comment']) ? null : Crypt::encryptString($data['comment']);
            unset($data['comment']);
            $data += ['source' => $manager ? 'trainer' : 'manual', 'original_status' => null, 'original_comment_encrypted' => null,
                'revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()->toIso8601String()];
            if ($old) {
                $query->update($data);
            } else {
                $query->insert($data + ['tenant_id' => $tenant->id, 'event_id' => $id, 'member_id' => $member]);
            }
            $this->audit($actor, $tenant, 'team_participations', $old->id ?? $id, $old?->revision, $data['revision']);
        });
    }

    public function effectiveCount(Tenant $tenant, object $event, ?int $except = null): int
    {
        $rows = $this->table($tenant, 'team_participations')->where('event_id', $event->id)->get()->keyBy('member_id');

        return $this->roster($tenant, $event)->pluck('id')->filter(fn ($id) => $id !== $except && ($rows[$id]->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN')) === 'DABEI')->count()
            + $this->table($tenant, 'team_guests')->where('event_id', $event->id)->whereNull('deleted_at')->where('status', 'DABEI')->count();
    }

    public function absences(Account $actor, Tenant $tenant)
    {
        $this->enter($actor, $tenant);
        $access = app(MemberAccessService::class);
        $own = $access->membership($actor->fresh(), $tenant)?->role === 'profile' ? collect() : $access->represented($actor, $tenant)->pluck('id');
        $teams = $this->teams($actor, $tenant, true, 'view')->pluck('id');

        return $this->table($tenant, 'team_absences')->whereNull('deleted_at')->where(function ($q) use ($tenant, $own, $teams) {
            $q->whereIn('member_id', $own);
            foreach (['team_players', 'team_staff'] as $table) {
                $q->orWhereIn('member_id', $this->table($tenant, $table)->where('active', true)->whereIn('team_season_id', $teams)->select('member_id'));
            }
        });
    }

    public function saveAbsence(Account $actor, Tenant $tenant, ?int $id, array $input, bool $delete = false): array
    {
        $this->authorizeOperation($actor, $tenant, $delete ? 'deactivate' : ($id ? 'update' : 'create'));

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input, $delete) {
            $this->lock($tenant);
            $this->authorizeOperation($actor, $tenant, $delete ? 'deactivate' : ($id ? 'update' : 'create'));
            $old = $id ? $this->table($tenant, 'team_absences')->where('id', $id)->whereNull('deleted_at')->first() : null;
            if ($id) {
                abort_unless($old, 404);
            }
            $this->conflict($old, $input);
            $member = (int) ($old->member_id ?? $input['member_id'] ?? 0);
            $members = app(ModulePermissionService::class)->allows($actor, $tenant, 'planning', $delete ? 'deactivate' : ($id ? 'update' : 'create'))
                ? app(MemberAccessService::class)->activeMembers($tenant)
                : app(MemberAccessService::class)->represented($actor, $tenant);
            abort_unless($members->whereKey($member)->exists(), 403);
            if ($delete) {
                $data = ['deleted_at' => now()->toIso8601String()];
            } else {
                $data = Validator::make($input, ['starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
                    'kind' => $this->optionRule($tenant, 'absence'), 'note' => 'nullable|string|max:1000'])->validate();
                $data['note_encrypted'] = empty($data['note']) ? null : Crypt::encryptString($data['note']);
                unset($data['note']);
            }
            $data += ['revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()->toIso8601String()];
            if ($old) {
                $this->table($tenant, 'team_absences')->where('id', $id)->update($data);
            } else {
                $id = $this->table($tenant, 'team_absences')->insertGetId($data + ['tenant_id' => $tenant->id, 'member_id' => $member]);
            }
            $teams = [];
            foreach (['team_players', 'team_staff'] as $table) {
                $teams = array_merge($teams, $this->table($tenant, $table)->where('member_id', $member)->where('active', true)->pluck('team_season_id')->all());
            }
            $skipped = 0;
            foreach ($this->table($tenant, 'team_events')->where('ends_at', '>', now()->toIso8601String())->where(function ($q) use ($tenant, $teams, $member) {
                $q->whereIn('id', $this->table($tenant, 'team_invitations')->where('member_id', $member)->select('event_id'));
                $q->orWhereIn('team_season_id', $teams)->orWhereIn('id', $this->table($tenant, 'team_participations')->where('member_id', $member)->where('source', 'absence')->select('event_id'));
            })->get() as $event) {
                if ($this->locked($event)) {
                    $date = Time::parse($event->starts_at)->setTimezone('Europe/Zurich')->toDateString();
                    $new = $this->table($tenant, 'team_absences')->where('id', $id)->first();
                    if (($old && $date >= $old->starts_on && $date <= $old->ends_on) || ($date >= $new->starts_on && $date <= $new->ends_on)) {
                        $skipped++;
                    }
                } else {
                    $this->reconcile($tenant, $event, $member);
                }
            }
            $this->audit($actor, $tenant, 'team_absences', $id, $old?->revision, $data['revision']);

            return ['id' => $id, 'skipped' => $skipped];
        });
    }

    public function reconcile(Tenant $tenant, object $event, ?int $only = null): void
    {
        if ($this->locked($event)) {
            return;
        }
        $date = Time::parse($event->starts_at)->setTimezone('Europe/Zurich')->toDateString();
        $roster = $this->roster($tenant, $event)->pluck('id');
        $existing = $this->table($tenant, 'team_participations')->where('event_id', $event->id)->get()->keyBy('member_id');
        $ids = $roster->merge($existing->where('source', 'absence')->keys())->unique()->filter(fn ($id) => ! $only || $id === $only);
        $absent = $this->table($tenant, 'team_absences')->whereNull('deleted_at')->whereIn('member_id', $ids)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->pluck('member_id')->all();
        foreach ($ids as $member) {
            $old = $existing[$member] ?? null;
            if ($old?->source === 'trainer' || $old?->status === 'NICHT_AUFGEBOT') {
                continue;
            }
            $query = $this->table($tenant, 'team_participations')->where('event_id', $event->id)->where('member_id', $member);
            if (in_array($member, $absent, true) && $roster->contains($member)) {
                if ($old?->source === 'absence') {
                    continue;
                }
                $data = ['status' => 'ABWESEND', 'source' => 'absence', 'comment_encrypted' => null,
                    'original_status' => $old?->status, 'original_comment_encrypted' => $old?->comment_encrypted,
                    'revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()->toIso8601String()];
                if ($old) {
                    $query->update($data);
                } else {
                    $query->insert($data + ['tenant_id' => $tenant->id, 'event_id' => $event->id, 'member_id' => $member]);
                }
            } elseif ($old?->source === 'absence') {
                if ($old->original_status === null) {
                    $query->delete();
                } else {
                    $query->update(['status' => $old->original_status, 'comment_encrypted' => $old->original_comment_encrypted,
                        'source' => 'manual', 'original_status' => null, 'original_comment_encrypted' => null, 'revision' => $old->revision + 1, 'updated_at' => now()->toIso8601String()]);
                }
            }
        }
    }

    public function audit(Account $actor, Tenant $tenant, string $type, int $id, ?int $before, int $after): void
    {
        // Audit metadata intentionally excludes health/absence reasons and participation comments.
        $this->table($tenant, 'object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $type, 'object_id' => $id,
            'before_value' => $before ? json_encode(['revision' => $before]) : null, 'after_value' => json_encode(['revision' => $after])]);
    }

    public function message(Tenant $tenant, object $event, string $key): bool
    {
        $inserted = $this->table($tenant, 'team_planning_messages')->insertOrIgnore(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'revision' => $event->revision, 'event_key' => $key]);
        if (! $inserted) {
            return false;
        }
        $team = $this->table($tenant, 'team_seasons')->where('id', $event->team_season_id)->value('label');
        $message = app(MessageTemplateService::class)->render($tenant, $key, 'push', ['verein' => $tenant->name, 'termin' => $event->title, 'datum' => Time::parse($event->starts_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i'), 'mannschaft' => $team]);
        if (! $message) {
            return true;
        }
        $members = $this->roster($tenant, $event)->pluck('id');
        $accounts = $this->table($tenant, 'tenant_accounts')->where('portal_enabled', true)->pluck('account_id');
        $today = now('Europe/Zurich')->toDateString();
        $recipients = DB::connection('clubunio')->table('clubora.account_member_links as l')
            ->join('clubora.accounts as a', 'a.id', '=', 'l.account_id')
            ->join('clubora.members as m', fn ($j) => $j->on('m.id', '=', 'l.member_id')->on('m.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $tenant->id)->where('l.state', 'approved')->where('a.active', true)
            ->whereIn('l.member_id', $members)->whereIn('a.id', $accounts)
            ->where(fn ($q) => $q->where('l.kind', 'self')->orWhere(fn ($q) => $q->where('l.kind', 'parent')->where('m.birth_date', '<=', $today)->whereRaw("m.birth_date + interval '18 years' > CAST(? AS date)", [$today])))
            ->distinct()->pluck('a.id');
        foreach ($recipients as $account) {
            $this->table($tenant, 'notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account, 'title' => $message['title'], 'body' => $message['body'], 'object_type' => 'team_event', 'object_id' => $event->id]);
        }

        return true;
    }

    public function reminders(Tenant $tenant): int
    {
        return DB::connection('clubunio')->transaction(function () use ($tenant) {
            $this->lock($tenant);
            $count = 0;
            foreach ($this->table($tenant, 'team_events')->where('status', 'AKTIV')->where('starts_at', '>', now()->toIso8601String())->get() as $event) {
                if (Time::parse($event->starts_at)->lte(now()->addDay())) {
                    $count += (int) $this->message($tenant, $event, 'team.reminder');
                }
                if ($event->deadline_at && Time::parse($event->deadline_at)->isFuture() && Time::parse($event->deadline_at)->lte(now()->addDay())) {
                    $count += (int) $this->message($tenant, $event, 'team.deadline');
                }
            }

            return $count;
        });
    }
}
