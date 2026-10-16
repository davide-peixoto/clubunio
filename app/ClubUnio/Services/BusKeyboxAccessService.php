<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\BusKeybox;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Simulation only until a test device and its remote-revocation capabilities are approved. */
class BusKeyboxAccessService
{
    public function configure(Account $actor, Tenant $tenant, ?int $id, array $input): BusKeybox
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        $data = Validator::make($input, ['revision' => 'required|integer|min:0', 'name' => 'required|string|max:160', 'bus_id' => 'required|integer|min:1', 'active' => 'required|boolean'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
            abort_unless(app(BusReservationService::class)->table($tenant, 'buses')->where('id', $data['bus_id'])->where('active', true)->exists(), 404);
            $row = $id ? BusKeybox::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new BusKeybox;
            if ($id && (int) $row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Testzuordnung wurde geändert.']);
            }
            abort_if(BusKeybox::where('tenant_id', $tenant->id)->where('bus_id', $data['bus_id'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists(), 409, 'Bus hat bereits eine Testzuordnung.');
            if ($id) {
                $this->codes($tenant)->where('keybox_id', $id)->where('state', 'issued')->update(['state' => 'invalidated', 'invalidated_at' => now()->toIso8601String()]);
            }
            $row->forceFill(['tenant_id' => $tenant->id, 'name' => $data['name'], 'bus_id' => $data['bus_id'], 'active' => $data['active'], 'mode' => 'SIMULATION', 'revision' => $id ? $row->revision + 1 : 1])->save();
            $this->audit($actor, $tenant, $id ?? $row->id, 'configure_simulation');

            return $row->refresh();
        });
    }

    private function codes(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.bus_keybox_codes')->where('tenant_id', $tenant->id);
    }

    private function audit(Account $actor, Tenant $tenant, int $id, string $action): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'bus_keybox', 'object_id' => $id,
            'after_value' => json_encode(['action' => $action, 'mode' => 'SIMULATION'], JSON_THROW_ON_ERROR)]);
    }

    public function invalidate(Account $actor, Tenant $tenant, int $reservation, string $function = 'cancel'): void
    {
        abort_unless(in_array($function, ['cancel', 'approve', 'update'], true), 403);
        $bus = app(BusReservationService::class);
        $bus->authorizeOperation($actor, $tenant, $function);
        $row = $bus->visible($actor, $tenant)->where('id', $reservation)->first();
        abort_unless($row, 404);
        abort_unless(app(ModulePermissionService::class)->allows($actor, $tenant, 'bus', $function)
            || app(MemberAccessService::class)->represented($actor, $tenant, true)->whereKey($row->member_id)->exists(), 403);
        $count = $this->codes($tenant)->where('reservation_id', $reservation)->where('state', 'issued')->update(['state' => 'invalidated', 'invalidated_at' => now()->toIso8601String()]);
        if ($count) {
            $this->audit($actor, $tenant, $reservation, 'invalidate_simulation');
        }
    }

    private function authorized(Account $actor, Tenant $tenant, object $row): bool
    {
        if (! $actor->fresh()?->active) {
            return false;
        }
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'bus', 'update')) {
            return true;
        }
        $fresh = $actor->fresh();
        $access = app(MemberAccessService::class);
        if (! in_array($access->membership($fresh, $tenant)?->role, ['member', 'parent', 'trainer', 'technical_lead'], true)
            || ! $access->canEnter($fresh, $tenant)) {
            return false;
        }
        $members = DB::connection('clubunio')->table('clubora.account_member_links')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('state', 'approved')->where('kind', 'self')
            ->whereIn('member_id', app(MemberAccessService::class)->activeMembers($tenant)->select('id'))->pluck('member_id');

        return $members->contains($row->member_id) || DB::connection('clubunio')->table('clubora.team_staff')->where('tenant_id', $tenant->id)->where('team_season_id', $row->team_season_id)->where('active', true)->whereIn('member_id', $members)->exists();
    }

    private function scope(object $row, BusKeybox $box): string
    {
        return hash('sha256', json_encode([$row->id, $row->revision, $row->bus_id, Time::parse($row->starts_at)->utc()->toIso8601String(), Time::parse($row->ends_at)->utc()->toIso8601String(), $box->id, $box->revision], JSON_THROW_ON_ERROR));
    }

    public function issueSimulation(Account $actor, Tenant $tenant, int $id, int $revision): string
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = app(BusReservationService::class)->visible($actor, $tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($this->authorized($actor, $tenant, $row), 403);
            if ((int) $row->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Reservation wurde geändert.']);
            }
            $preview = $this->inspect($actor, $tenant, $id);
            abort_unless($preview['simulationEnabled'] && $preview['inWindow'], 409, 'Simulation ist nicht eingerichtet oder ausserhalb des Zeitfensters.');
            $box = BusKeybox::where('tenant_id', $tenant->id)->where('bus_id', $row->bus_id)->where('active', true)->firstOrFail();
            $scope = $this->scope($row, $box);
            $old = $this->codes($tenant)->where('scope_key', $scope)->first();
            if ($old) {
                abort_unless($old->state === 'issued', 409);

                return Crypt::decryptString($old->pin_cipher);
            }
            $code = 'TEST-'.strtoupper(bin2hex(random_bytes(4)));
            $this->codes($tenant)->insert(['tenant_id' => $tenant->id, 'reservation_id' => $id, 'keybox_id' => $box->id, 'reservation_revision' => $revision, 'keybox_revision' => $box->revision,
                'scope_key' => $scope, 'state' => 'issued', 'pin_cipher' => Crypt::encryptString($code), 'valid_from' => $preview['from']->toIso8601String(),
                'valid_until' => $preview['until']->toIso8601String(), 'created_by' => $actor->id]);
            $this->audit($actor, $tenant, $id, 'issue_simulation');

            return $code;
        });
    }

    public function inspect(Account $actor, Tenant $tenant, int $id): array
    {
        $bus = app(BusReservationService::class);
        $row = $bus->visible($actor, $tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        $from = Time::parse($row->starts_at)->tz('Europe/Zurich')->subHours(4);
        $until = Time::parse($row->ends_at)->tz('Europe/Zurich')->addHours(4);
        $eligible = $this->authorized($actor, $tenant, $row) && $row->status === 'GENEHMIGT' && $bus->table($tenant, 'buses')->where('id', $row->bus_id)->where('active', true)->exists();
        $inWindow = now()->gte($from) && now()->lt($until);

        $box = BusKeybox::where('tenant_id', $tenant->id)->where('bus_id', $row->bus_id)->where('active', true)->first();
        $saved = $box ? $this->codes($tenant)->where('scope_key', $this->scope($row, $box))->where('state', 'issued')->first() : null;
        $code = $eligible && $inWindow && $saved ? Crypt::decryptString($saved->pin_cipher) : null;

        return ['eligible' => $eligible, 'inWindow' => $eligible && $inWindow, 'from' => $from, 'until' => $until, 'issuanceEnabled' => false,
            'simulationEnabled' => $eligible && (bool) $box, 'testCode' => $code];
    }
}
