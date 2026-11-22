<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, ClubEvent, Member, Tenant};
use App\ClubUnio\Mobile\MobileAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt, DB, Validator};
use Illuminate\Support\Str;

class EventCheckinService
{
    public function enabled(): bool { return config('clubunio-event-checkin.enabled', false) === true; }
    private function requireEnabled(): void { abort_unless($this->enabled(), 503); }
    private function codes() { return DB::connection('clubunio')->table('clubunio.event_checkin_codes'); }
    private function attendance() { return DB::connection('clubunio')->table('clubunio.event_checkins'); }

    public function origin(Tenant $tenant): string
    {
        $map = config('clubunio-event-checkin.tenant_origins', []);
        $origin = rtrim((string) ($map[$tenant->id] ?? config('clubunio-event-checkin.public_origin')), '/');
        $p = parse_url($origin);
        abort_unless($p && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) && !isset($p['query']) && !isset($p['fragment'])
            && (!isset($p['path']) || $p['path'] === ''), 503);
        return $origin;
    }

    public function isOpen(ClubEvent $event): bool
    {
        $now = CarbonImmutable::now();
        return $this->enabled() && $event->active && $event->checkin_enabled
            && $event->checkin_opens_at !== null && $event->checkin_closes_at !== null
            && $now->greaterThanOrEqualTo($event->checkin_opens_at) && $now->lessThan($event->checkin_closes_at);
    }

    public function event(Tenant $tenant, int $event, bool $lock = false): ClubEvent
    {
        return ClubEvent::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($event);
    }

    private function dto(ClubEvent $event): array
    {
        return ['ID' => (int) $event->id, 'Revision' => (int)$event->revision, 'Name' => (string)$event->name, 'Location' => (string)$event->location,
            'Enabled' => (bool) $event->checkin_enabled, 'OpensAt' => $event->checkin_opens_at?->toIso8601String(),
            'ClosesAt' => $event->checkin_closes_at?->toIso8601String(), 'IsOpen' => $this->isOpen($event)];
    }

    /** Read-only; displaying an unconfigured event does not mint a QR. */
    public function qr(Account $actor, Tenant $tenant, int $id): array
    {
        $this->requireEnabled();
        app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'events', 'update');
        $event = $this->event($tenant, $id);
        $code = $this->codes()->where('tenant_id', $tenant->id)->where('event_id', $id)->first();
        return ['Event' => $this->dto($event), 'Revision' => (int) $event->revision,
            'TokenRevision' => $code ? (int) $code->revision : null,
            'Url' => $code ? $this->origin($tenant).'/event-check-in/'.Crypt::decryptString($code->token_encrypted) : null];
    }

    private function audit(Account $actor, Tenant $tenant, int $event, string $operation, int $revision): void
    {
        DB::connection('clubunio')->table('clubunio.object_changes')->insert(['tenant_id' => $tenant->id,
            'actor_id' => $actor->id, 'object_type' => 'event_checkin', 'object_id' => $event,
            'after_value' => json_encode(['operation' => $operation, 'revision' => $revision], JSON_THROW_ON_ERROR)]);
    }

    private function issue(Tenant $tenant, int $event): void
    {
        if ($this->codes()->where('tenant_id', $tenant->id)->where('event_id', $event)->exists()) return;
        $token = bin2hex(random_bytes(32));
        $this->codes()->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'event_id' => $event,
            'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token)]);
    }

    public function configure(Account $actor, Tenant $tenant, int $id, array $input): array
    {
        $this->requireEnabled();
        $d = Validator::make($input, ['Enabled' => 'required|boolean', 'Revision' => 'required|integer|min:0',
            'OpensAt' => 'nullable|date|required_if:Enabled,true', 'ClosesAt' => 'nullable|date|required_if:Enabled,true'])->validate();
        $open = !empty($d['OpensAt']) ? CarbonImmutable::parse($d['OpensAt'], 'Europe/Zurich')->utc() : null;
        $close = !empty($d['ClosesAt']) ? CarbonImmutable::parse($d['ClosesAt'], 'Europe/Zurich')->utc() : null;
        abort_if($d['Enabled'] && (!$open || !$close || !$close->greaterThan($open)), 422, __('Choose a check-in end after its start.'));
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $d, $open, $close) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'events', 'update');
            $event = $this->event($tenant, $id, true);
            abort_unless((int)$event->revision === (int)$d['Revision'], 409);
            if ($d['Enabled']) {
                abort_unless($event->active, 409);
                abort_unless(trim((string)$event->name) !== '' && trim((string)$event->location) !== '', 422,
                    __('Complete event name and location before enabling check-in.'));
                $this->origin($tenant); $this->issue($tenant, $id);
            }
            $event->forceFill(['checkin_enabled' => $d['Enabled'], 'checkin_opens_at' => $open,
                'checkin_closes_at' => $close, 'revision' => $event->revision + 1])->save();
            $this->audit($actor, $tenant, $id, 'configured', (int)$event->revision);
            return $this->qr($actor, $tenant, $id);
        });
    }

    public function rotate(Account $actor, Tenant $tenant, int $id, int $revision): array
    {
        $this->requireEnabled();
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'events', 'update');
            $event = $this->event($tenant, $id, true); abort_unless((int)$event->revision === $revision, 409);
            $this->issue($tenant, $id); $token = bin2hex(random_bytes(32));
            $this->codes()->where('tenant_id', $tenant->id)->where('event_id', $id)->update(['token_hash' => hash('sha256', $token),
                'token_encrypted' => Crypt::encryptString($token), 'revision' => DB::connection('clubunio')->raw('revision+1'), 'updated_at' => now()]);
            $event->forceFill(['revision' => $event->revision + 1])->save();
            $this->audit($actor, $tenant, $id, 'rotated', (int)$event->revision);
            return $this->qr($actor, $tenant, $id);
        });
    }

    public function close(Account $actor, Tenant $tenant, int $id, int $revision): array
    {
        $this->requireEnabled();
        app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'events', 'update');
        $event = $this->event($tenant, $id);
        return $this->configure($actor, $tenant, $id, ['Enabled' => false, 'Revision' => $revision,
            'OpensAt' => $event->checkin_opens_at?->toIso8601String(), 'ClosesAt' => $event->checkin_closes_at?->toIso8601String()]);
    }

    /** No token details are exposed before personal club access is established. */
    public function context(Account $actor, string $token): array
    {
        $this->requireEnabled(); $code = $this->code($token);
        $tenant = Tenant::findOrFail($code->tenant_id);
        app(MobileAccess::class)->enter($actor, $tenant);
        $bound = app(TenantDomainService::class)->resolve(request());
        abort_if($bound && !$bound->is($tenant), 404);
        return ['Tenant' => ['ID' => (int)$tenant->id, 'Name' => $tenant->name], 'EventID' => (int)$code->event_id];
    }

    private function code(string $token, ?Tenant $tenant = null, bool $lock = false): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        return $this->codes()->where('token_hash', hash('sha256', $token))
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    protected function candidates(Account $actor, Tenant $tenant, ClubEvent $event)
    {
        $query = app(MemberAccessService::class)->represented($actor, $tenant, true)
            ->whereHas('status', fn ($q) => $q->where('active', true));
        if ($event->team_id) {
            $query->where(function ($q) use ($tenant, $event) {
                $q->whereExists(fn ($a) => $a->selectRaw('1')->from('clubunio.event_guests as invite')
                    ->where('invite.tenant_id', $tenant->id)->where('invite.club_event_id', $event->id)
                    ->where('invite.active', true)->whereColumn('invite.member_id', 'clubunio.members.id'));
                foreach (['team_players', 'team_staff'] as $table) {
                    $q->orWhereExists(fn ($a) => $a->selectRaw('1')->from('clubunio.'.$table.' as assignment')
                        ->join('clubunio.team_seasons as ts', fn ($j) => $j->on('ts.id', '=', 'assignment.team_season_id')->on('ts.tenant_id', '=', 'assignment.tenant_id'))
                        ->where('assignment.tenant_id', $tenant->id)->where('assignment.active', true)->where('ts.active', true)
                        ->where('ts.team_id', $event->team_id)->where('ts.season_id', $event->season_id)
                        ->whereColumn('assignment.member_id', 'clubunio.members.id'));
                }
            });
        }
        return $query;
    }

    public function show(Account $actor, Tenant $tenant, string $token): array
    {
        $this->requireEnabled(); app(MobileAccess::class)->enter($actor, $tenant);
        $code = $this->code($token, $tenant); $event = $this->event($tenant, (int)$code->event_id);
        $members = $this->candidates($actor, $tenant, $event)->get();
        $present = $this->attendance()->where('tenant_id', $tenant->id)->where('event_id', $event->id)
            ->whereIn('member_id', $members->modelKeys())->get()->keyBy('member_id');
        return ['Event' => $this->dto($event), 'TokenRevision' => (int)$code->revision,
            'Members' => $members->map(fn ($m) => ['ID' => (int)$m->id, 'Name' => trim($m->first_name.' '.$m->last_name),
                'Present' => isset($present[$m->id]), 'CheckedInAt' => isset($present[$m->id]) ? CarbonImmutable::parse($present[$m->id]->checked_in_at)->toIso8601String() : null])->all()];
    }

    public function confirm(Account $actor, Tenant $tenant, string $token, array $input): array
    {
        $this->requireEnabled();
        $d = Validator::make($input, ['MemberID' => 'required|integer|min:1', 'TokenRevision' => 'required|integer|min:1', 'EventRevision' => 'required|integer|min:0', 'Confirmation' => 'required|accepted'])->validate();
        abort_unless(($input['Confirmation'] ?? null) === true, 422);
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $token, $d) {
            // Same ordering as board configuration: club, event, code. A rotate/close
            // cannot race a stale bearer into a new attendance row.
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $lookup = $this->code($token, $tenant);
            $event = $this->event($tenant, (int)$lookup->event_id, true);
            abort_unless((int)$event->revision === (int)$d['EventRevision'], 409);
            $code = $this->code($token, $tenant, true);
            abort_unless((int)$code->revision === (int)$d['TokenRevision'], 409);
            $actor = Account::whereKey($actor->id)->where('active', true)->where('api_only', false)->sharedLock()->first();
            abort_unless($actor, 401);
            app(MobileAccess::class)->enter($actor, $tenant);
            $link = DB::connection('clubunio')->table('clubunio.account_member_links')->where('tenant_id', $tenant->id)
                ->where('account_id', $actor->id)->where('member_id', $d['MemberID'])->where('kind', 'self')->where('state', 'approved')->sharedLock()->first();
            abort_unless($link, 403);
            $member = $this->candidates($actor, $tenant, $event)->whereKey($d['MemberID'])->sharedLock()->firstOrFail();
            $existing = $this->attendance()->where('tenant_id', $tenant->id)->where('event_id', $event->id)->where('member_id', $member->id)->first();
            if (!$existing) {
                abort_unless($this->isOpen($event), 409, __('Event check-in is currently closed.'));
                $this->attendance()->insert(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'member_id' => $member->id,
                    'checked_in_at' => now(), 'checked_in_by' => $actor->id]);
                $this->audit($actor, $tenant, (int)$event->id, 'member_confirmed', (int)$code->revision);
            }
            $saved = $this->attendance()->where('tenant_id', $tenant->id)->where('event_id', $event->id)->where('member_id', $member->id)->firstOrFail();
            return ['Confirmed' => true, 'AlreadyPresent' => $existing !== null, 'EventID' => (int)$event->id,
                'MemberID' => (int)$member->id, 'CheckedInAt' => CarbonImmutable::parse($saved->checked_in_at)->toIso8601String()];
        });
    }
}
