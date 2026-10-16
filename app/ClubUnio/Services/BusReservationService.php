<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BusReservationService
{
    public function table(Tenant $tenant, string $name)
    {
        return DB::connection('clubunio')->table('clubora.'.$name)->where('tenant_id', $tenant->id);
    }

    public function enter(Account $actor, Tenant $tenant): void
    {
        abort_unless($actor->fresh()?->active, 403);
        abort_unless(app(MemberAccessService::class)->canEnter($actor, $tenant), 403);
    }

    public function teams(Account $actor, Tenant $tenant)
    {
        $this->enter($actor, $tenant);
        $q = $this->table($tenant, 'team_seasons')->where('active', true)
            ->whereIn('team_id', $this->table($tenant, 'teams')->where('active', true)->select('id'));
        if (! $actor->canAccessTenant($tenant)) {
            $q->whereIn('id', app(MemberAccessService::class)->responsibleTeams($actor, $tenant));
        }

        return $q;
    }

    public function visible(Account $actor, Tenant $tenant)
    {
        $this->enter($actor, $tenant);
        $q = $this->table($tenant, 'bus_reservations')->where('active', true);
        if (! $actor->canAccessTenant($tenant)) {
            $ids = app(MemberAccessService::class)->represented($actor, $tenant, true)->select('clubora.members.id');
            $q->where(fn ($q) => $q->whereIn('member_id', $ids)->orWhereIn('team_season_id', $this->teams($actor, $tenant)->select('id')));
        }

        return $q;
    }

    public function create(Account $actor, Tenant $tenant, array $input): int
    {
        $this->enter($actor, $tenant);
        $data = Validator::make($input, [
            'bus_id' => 'required|integer|min:1', 'team_season_id' => 'required|integer|min:1', 'member_id' => 'required|integer|min:1',
            'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at',
            'location' => 'nullable|string|max:255', 'description' => 'nullable|string|max:1000',
            'status' => 'required|in:ANGEFRAGT,GENEHMIGT',
        ])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->table($tenant, 'buses')->where('id', $data['bus_id'])->where('active', true)->exists(), 422);
            abort_unless($this->teams($actor, $tenant)->where('id', $data['team_season_id'])->exists(), 403);
            $this->reservableTeam($tenant, (int) $data['team_season_id']);
            $members = $actor->canAccessTenant($tenant) ? app(MemberAccessService::class)->activeMembers($tenant) : app(MemberAccessService::class)->represented($actor, $tenant, true);
            abort_unless($members->where('id', $data['member_id'])->exists(), 403);
            $status = $actor->canAccessTenant($tenant) ? $data['status'] : 'ANGEFRAGT';
            $this->status($tenant, $status);
            $from = Time::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Europe/Zurich');
            $to = Time::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], 'Europe/Zurich');
            if ($from <= now() || $from->format('Y-m-d\TH:i') !== $data['starts_at'] || $to->format('Y-m-d\TH:i') !== $data['ends_at']) {
                throw ValidationException::withMessages(['starts_at' => 'Bitte eine gültige zukünftige Reservation erfassen.']);
            }
            $this->conflict($tenant, $data['bus_id'], $from->toIso8601String(), $to->toIso8601String());
            $id = $this->table($tenant, 'bus_reservations')->insertGetId(array_merge($data, [
                'tenant_id' => $tenant->id, 'status' => $status, 'starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(),
                'decided_at' => $status === 'GENEHMIGT' ? now()->toIso8601String() : null, 'decided_by' => $status === 'GENEHMIGT' ? $actor->id : null,
            ]));
            $this->audit($actor, $tenant, $id, null, array_merge($data, ['status' => $status, 'starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(), 'revision' => 1, 'action' => 'create']));
            $this->notify($tenant, $id, $status);

            return $id;
        });
    }

    private function status(Tenant $tenant, string $status): void
    {
        if (! $this->table($tenant, 'bus_statuses')->where('code', $status)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['status' => 'Der Reservierungsstatus fehlt oder ist inaktiv.']);
        }
    }

    public function reservableTeams(Account $actor, Tenant $tenant)
    {
        return $this->teams($actor, $tenant)->whereIn('season_id', $this->table($tenant, 'seasons')->where('active', true)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now('Europe/Zurich')->toDateString()))->select('id'));
    }

    private function reservableTeam(Tenant $tenant, int $team): void
    {
        $season = $this->table($tenant, 'team_seasons')->where('id', $team)->value('season_id');
        if (! $this->table($tenant, 'seasons')->where('id', $season)->where('active', true)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now('Europe/Zurich')->toDateString()))->exists()) {
            throw ValidationException::withMessages(['team_season_id' => 'Die Saison der Mannschaft ist beendet oder inaktiv.']);
        }
    }

    private function conflict(Tenant $tenant, int $bus, string $from, string $to, ?int $ignore = null): void
    {
        if ($this->table($tenant, 'bus_reservations')->where('active', true)->where('bus_id', $bus)->whereIn('status', ['ANGEFRAGT', 'GENEHMIGT', 'UNBEKANNT'])
            ->when($ignore, fn ($q) => $q->where('id', '<>', $ignore))->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists()) {
            throw ValidationException::withMessages(['starts_at' => 'Der Bus ist in diesem Zeitraum bereits reserviert oder angefragt.']);
        }
    }

    public function decide(Account $actor, Tenant $tenant, int $id, int $revision, string $status, ?string $note): void
    {
        $this->enter($actor, $tenant);
        Validator::make(['status' => $status, 'note' => $note], ['status' => 'required|in:GENEHMIGT,ABGELEHNT,STORNIERT', 'note' => 'nullable|string|max:1000'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $status, $note) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->visible($actor, $tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Die Reservation wurde geändert. Bitte neu laden.']);
            }
            $office = $actor->canAccessTenant($tenant);
            if (! $office) {
                abort_unless($status === 'STORNIERT' && $row->status === 'ANGEFRAGT' && app(MemberAccessService::class)->represented($actor, $tenant, true)->where('id', $row->member_id)->exists(), 403);
            }
            if (! in_array($row->status, ['ANGEFRAGT', 'GENEHMIGT', 'UNBEKANNT'], true) || ($status === 'GENEHMIGT' && $row->status === 'GENEHMIGT') || ($status === 'ABGELEHNT' && $row->status === 'GENEHMIGT')) {
                throw ValidationException::withMessages(['status' => 'Dieser Statuswechsel ist nicht zulässig.']);
            }
            if (Time::parse($row->ends_at) <= now()) {
                throw ValidationException::withMessages(['status' => 'Vergangene Reservationen bleiben unverändert.']);
            }
            $this->status($tenant, $status);
            if ($status === 'GENEHMIGT') {
                if (! $row->member_id || ! $row->team_season_id) {
                    throw ValidationException::withMessages(['status' => 'Die historische Reservation hat keine vollständige Mitglieder-/Teamzuordnung.']);
                }
                if (! $this->table($tenant, 'buses')->where('id', $row->bus_id)->where('active', true)->exists()
                    || ! $this->teams($actor, $tenant)->where('id', $row->team_season_id)->exists()
                    || ! app(MemberAccessService::class)->activeMembers($tenant)->where('id', $row->member_id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'Bus, Mannschaft oder Antragsteller ist nicht mehr aktiv.']);
                }
                $this->reservableTeam($tenant, (int) $row->team_season_id);
                $this->conflict($tenant, $row->bus_id, $row->starts_at, $row->ends_at, $id);
            }
            $change = ['status' => $status, 'decision_note' => $note, 'revision' => $revision + 1, 'updated_at' => now()->toIso8601String(), 'decided_at' => now()->toIso8601String(), 'decided_by' => $actor->id];
            $this->table($tenant, 'bus_reservations')->where('id', $id)->update($change);
            if ($status !== 'GENEHMIGT') {
                app(BusKeyboxAccessService::class)->invalidate($actor, $tenant, $id);
            }
            $this->audit($actor, $tenant, $id, ['status' => $row->status, 'revision' => $revision], $change);
            $this->notify($tenant, $id, $status);
        });
    }

    public function canReschedule(Account $actor, Tenant $tenant, object $row): bool
    {
        return Time::parse($row->starts_at)->isFuture() && in_array($row->status, ['ANGEFRAGT', 'GENEHMIGT'], true)
            && ($actor->canAccessTenant($tenant) || ($row->status === 'ANGEFRAGT' && app(MemberAccessService::class)->represented($actor, $tenant, true)->where('id', $row->member_id)->exists()));
    }

    public function reschedule(Account $actor, Tenant $tenant, int $id, array $input): void
    {
        $this->enter($actor, $tenant);
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:1', 'bus_id' => 'required|integer|min:1',
            'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at',
            'location' => 'nullable|string|max:255', 'description' => 'nullable|string|max:1000', 'reason' => 'required|string|max:1000',
            'status' => 'prohibited', 'member_id' => 'prohibited', 'team_season_id' => 'prohibited',
        ])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->visible($actor, $tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($this->canReschedule($actor, $tenant, $row), 403);
            if ((int) $row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Die Reservation wurde geändert. Bitte neu laden.']);
            }
            if (! $this->table($tenant, 'buses')->where('id', $data['bus_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['bus_id' => 'Dieser Bus ist nicht aktiv.']);
            }
            if (! $row->member_id || ! $row->team_season_id || ! app(MemberAccessService::class)->activeMembers($tenant)->where('id', $row->member_id)->exists()
                || ! $this->teams($actor, $tenant)->where('id', $row->team_season_id)->exists()) {
                throw ValidationException::withMessages(['reservation' => 'Die Mitglieder-/Mannschaftszuordnung ist unvollständig oder nicht mehr aktiv.']);
            }
            $this->reservableTeam($tenant, (int) $row->team_season_id);
            $from = Time::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Europe/Zurich');
            $to = Time::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], 'Europe/Zurich');
            if ($from <= now() || $from->format('Y-m-d\TH:i') !== $data['starts_at'] || $to->format('Y-m-d\TH:i') !== $data['ends_at']) {
                throw ValidationException::withMessages(['starts_at' => 'Bitte einen gültigen zukünftigen Zeitraum angeben.']);
            }
            $change = ['bus_id' => (int) $data['bus_id'], 'starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(), 'location' => $data['location'] ?? null, 'description' => $data['description'] ?? null];
            $before = ['bus_id' => (int) $row->bus_id, 'starts_at' => Time::parse($row->starts_at)->tz('Europe/Zurich')->toIso8601String(), 'ends_at' => Time::parse($row->ends_at)->tz('Europe/Zurich')->toIso8601String(), 'location' => $row->location, 'description' => $row->description];
            if ($before === $change) {
                return;
            }
            $this->conflict($tenant, $change['bus_id'], $change['starts_at'], $change['ends_at'], $id);
            $this->table($tenant, 'bus_reservations')->where('id', $id)->update($change + ['revision' => $row->revision + 1, 'updated_at' => now()->toIso8601String()]);
            app(BusKeyboxAccessService::class)->invalidate($actor, $tenant, $id);
            $this->audit($actor, $tenant, $id, $before + ['revision' => $row->revision], $change + ['revision' => $row->revision + 1, 'reason' => $data['reason'], 'action' => 'reschedule']);
            $this->notify($tenant, $id, $row->status, 'bus.changed');
        });
    }

    public static function optionVersion(object $row): string
    {
        return hash('sha256', json_encode([$row->name, (bool) $row->active]));
    }

    public function assignImported(Account $actor, Tenant $tenant, int $id, array $input): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'member_id' => 'required|integer|min:1',
            'team_season_id' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            $row = $this->table($tenant, 'bus_reservations')->where('id', $id)->where('active', true)->lockForUpdate()->first();
            abort_unless($row && $row->legacy_id && (! $row->member_id || ! $row->team_season_id), 404);
            if ((int) $row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Die Reservation wurde geändert. Bitte neu laden.']);
            }
            if (($row->member_id && $row->member_id != $data['member_id']) || ($row->team_season_id && $row->team_season_id != $data['team_season_id'])) {
                throw ValidationException::withMessages(['member_id' => 'Bestehende Zuordnungen bleiben erhalten.']);
            }
            abort_unless($this->table($tenant, 'members')->where('id', $data['member_id'])->exists()
                && $this->table($tenant, 'team_seasons')->where('id', $data['team_season_id'])->exists(), 422);
            $before = ['member_id' => $row->member_id, 'team_season_id' => $row->team_season_id, 'revision' => $row->revision];
            $change = ['member_id' => (int) $data['member_id'], 'team_season_id' => (int) $data['team_season_id'], 'revision' => $row->revision + 1];
            $this->table($tenant, 'bus_reservations')->where('id', $id)->update($change + ['updated_at' => now()]);
            $this->audit($actor, $tenant, $id, $before, $change + ['reason' => $data['reason'], 'action' => 'assign_imported']);
            // Historical correction is not a new reservation or an access-code request.
        });
    }

    public function saveOption(Account $actor, Tenant $tenant, array $input): void
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
        $data = Validator::make($input, ['kind' => 'required|in:bus,status', 'id' => 'nullable|integer|min:1', 'name' => 'required|string|max:160', 'active' => 'required|boolean', 'version' => 'nullable|string|size:64'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $table = $data['kind'] === 'bus' ? 'buses' : 'bus_statuses';
            $id = $data['id'] ?? null;
            abort_if(! $id && $data['kind'] === 'status', 422);
            $row = $id ? $this->table($tenant, $table)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $row, 404);
            if ($row && ! hash_equals(self::optionVersion($row), $data['version'] ?? '')) {
                throw ValidationException::withMessages(['version' => 'Die Auswahl wurde geändert. Bitte neu laden.']);
            }
            $change = ['name' => trim($data['name']), 'active' => (bool) $data['active']];
            if ($change['name'] === '' || $this->table($tenant, $table)->whereRaw('lower(name)=lower(?)', [$change['name']])->where('id', '<>', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['name' => 'Die Bezeichnung ist leer oder bereits vergeben.']);
            }
            if ($id) {
                $this->table($tenant, $table)->where('id', $id)->update($change + ($table === 'buses' ? ['revision' => $row->revision + 1] : []));
            } else {
                $id = $this->table($tenant, $table)->insertGetId($change + ['tenant_id' => $tenant->id]);
            }
            $this->audit($actor, $tenant, $id, $row ? ['name' => $row->name, 'active' => $row->active] : null, $change, $table);
        });
    }

    private function audit(Account $actor, Tenant $tenant, int $id, ?array $before, array $after, string $type = 'bus_reservations'): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $type, 'object_id' => $id, 'before_value' => $before ? json_encode($before) : null, 'after_value' => json_encode($after)]);
    }

    private function notify(Tenant $tenant, int $id, string $status, ?string $event = null): void
    {
        $row = $this->table($tenant, 'bus_reservations')->where('id', $id)->first();
        $db = DB::connection('clubunio');
        $recipients = $db->table('clubora.tenant_accounts as ta')->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')->where('ta.tenant_id', $tenant->id)->where('a.active', true);
        if ($status === 'ANGEFRAGT') {
            $recipients->where('ta.role', 'office');
        } elseif ($status === 'STORNIERT') {
            $recipients->where(fn ($q) => $q->where('ta.role', 'office')->orWhere(fn ($q) => $q->where('ta.portal_enabled', true)->whereIn('ta.account_id', $this->table($tenant, 'account_member_links')->where('member_id', $row->member_id)->where('kind', 'self')->where('state', 'approved')->select('account_id'))));
        } else {
            $recipients->whereIn('ta.account_id', $this->table($tenant, 'account_member_links')->where('member_id', $row->member_id)->where('kind', 'self')->where('state', 'approved')->select('account_id'))->where('ta.portal_enabled', true);
        }
        $member = Member::where('tenant_id', $tenant->id)->find($row->member_id);
        $message = app(MessageTemplateService::class)->render($tenant, $event ?? match ($status) {
            'ANGEFRAGT' => 'bus.requested','GENEHMIGT' => 'bus.approved','STORNIERT' => 'bus.cancelled',default => 'bus.rejected'
        }, 'push', [
            'verein' => $tenant->name, 'mitglied' => $member?->display_name ?? '', 'mannschaft' => $this->table($tenant, 'team_seasons')->where('id', $row->team_season_id)->value('label') ?? '',
            'datum' => Time::parse($row->starts_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i'),
        ]);
        if (! $message) {
            return;
        }
        foreach ($recipients->distinct()->pluck('ta.account_id') as $account) {
            $db->table('clubora.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account, 'title' => $message['title'], 'body' => $message['body'], 'object_type' => 'bus_reservation', 'object_id' => $id]);
        }
    }
}
