<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TenantAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImpersonationService
{
    public const KEY = 'clubunio_support_session';

    public function table(string $name)
    {
        return DB::connection('clubunio')->table('clubora.support_'.$name);
    }

    public function allowed(Account $actor, Tenant $tenant): bool
    {
        return ! $actor->api_only && app(AccountAdministrationService::class)->allowed($actor, $tenant)
            && $this->table('grants')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('enabled', true)->exists();
    }

    public function audit(int $tenant, int $actor, int $target, string $action, array $details = [], ?string $session = null): void
    {
        $this->table('events')->insert(['tenant_id' => $tenant, 'actor_id' => $actor, 'target_id' => $target,
            'session_id' => $session, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    public function password(Account $actor, string $password): void
    {
        abort_unless($actor->fresh()?->active && Hash::check($password, $actor->fresh()->password), 403, 'Eigenes Passwort ungueltig.');
    }

    public function grant(Account $actor, Tenant $tenant, int $target, bool $enabled, int $revision, string $reason): void
    {
        abort_if(request()->session()->has(self::KEY), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $target, $enabled, $revision, $reason) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccountAdministrationService::class)->allowed($actor, $tenant), 403);
            $account = Account::findOrFail($target);
            abort_unless(TenantAccount::where('tenant_id', $tenant->id)->where('account_id', $target)->exists(), 404);
            abort_if($enabled && ($account->api_only || ! app(AccountAdministrationService::class)->allowed($account, $tenant)), 422);
            $old = $this->table('grants')->where('tenant_id', $tenant->id)->where('account_id', $target)->first();
            abort_unless((int) ($old->revision ?? 0) === $revision, 409, 'Freischaltung wurde inzwischen geaendert.');
            $this->table('grants')->updateOrInsert(['tenant_id' => $tenant->id, 'account_id' => $target],
                ['enabled' => $enabled, 'revision' => $revision + 1]);
            $this->audit($tenant->id, $actor->id, $target, $enabled ? 'grant' : 'revoke', ['reason' => $reason]);
            if (! $enabled) {
                $this->table('sessions')->where('tenant_id', $tenant->id)->where('actor_id', $target)->whereNull('ended_at')
                    ->update(['ended_at' => CarbonImmutable::now()->toIso8601String()]);
            }
        });
    }

    public function start(Request $request, Account $actor, Tenant $tenant, Account $target, string $reason): string
    {
        abort_if($request->session()->has(self::KEY), 409, 'Simulation bereits aktiv.');
        abort_unless($this->allowed($actor, $tenant), 403);
        abort_unless($target->active && ! $target->api_only && $actor->id !== $target->id, 403);
        $link = TenantAccount::where('tenant_id', $tenant->id)->where('account_id', $target->id)->first();
        abort_unless($link && $link->role !== 'admin', 403, 'Administratoren werden nicht simuliert.');
        abort_unless($target->canAccessTenant($tenant) || $link->portal_enabled, 403);
        $bound = app(TenantDomainService::class)->current();
        abort_if($bound && ! $bound->is($tenant), 403);
        $id = (string) Str::uuid();
        DB::connection('clubunio')->transaction(function () use ($id, $tenant, $actor, $target, $reason) {
            $this->table('sessions')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'target_id' => $target->id, 'reason' => $reason, 'expires_at' => CarbonImmutable::now()->addMinutes(15)->toIso8601String()]);
            $this->audit($tenant->id, $actor->id, $target->id, 'start', [], $id);
        });
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put(self::KEY, $id);
        return $target->canAccessTenant($tenant) ? '/' : '/portal/'.rawurlencode($tenant->slug).'/members';
    }

    public function resolve(Request $request): ?object
    {
        $id = $request->session()->get(self::KEY);
        if (! $id) {
            return null;
        }
        $row = is_string($id) && Str::isUuid($id) ? $this->table('sessions')->where('id', $id)->first() : null;
        $actor = $request->user('clubunio');
        $tenant = $row ? Tenant::find($row->tenant_id) : null;
        $target = $row ? Account::find($row->target_id) : null;
        $link = $row ? TenantAccount::where('tenant_id', $row->tenant_id)->where('account_id', $row->target_id)->first() : null;
        $bound = app(TenantDomainService::class)->current();
        if (! $row || ! $actor || $row->actor_id !== $actor->id || $row->ended_at
            || CarbonImmutable::parse($row->expires_at)->isPast() || ! $tenant || ! $this->allowed($actor, $tenant)
            || ! $target?->active || $target->api_only || ! $link || $link->role === 'admin'
            || (! $target->canAccessTenant($tenant) && ! $link->portal_enabled) || ($bound && ! $bound->is($tenant))) {
            $this->stop($request, 'expired_or_revoked');
            abort(403, 'Simulation beendet. Bitte die Seite neu laden.');
        }
        return $row;
    }

    public function stop(Request $request, string $action = 'stop'): void
    {
        $id = $request->session()->pull(self::KEY);
        if (is_string($id) && Str::isUuid($id)) {
            $row = $this->table('sessions')->where('id', $id)->first();
            if ($row && $request->user('clubunio')?->id === $row->actor_id) {
                $this->table('sessions')->where('id', $id)->whereNull('ended_at')->update(['ended_at' => CarbonImmutable::now()->toIso8601String()]);
                $this->audit($row->tenant_id, $row->actor_id, $row->target_id, $action, [], $id);
            }
        }
        $request->session()->regenerate();
        $request->session()->regenerateToken();
    }
}
