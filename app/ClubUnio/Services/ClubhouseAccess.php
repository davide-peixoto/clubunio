<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ClubhouseAccess
{
    public function ready(): bool
    {
        $key = self::class.'.schema';
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, Schema::connection('clubunio')->hasTable('clubunio.clubhouse_grants'));
        }

        return request()->attributes->get($key) === true;
    }

    public function member(Account $actor, Tenant $tenant): bool
    {
        $fresh = $actor->fresh();

        return $tenant->fresh() !== null && $fresh?->active && ! $fresh->api_only
            && DB::connection('clubunio')->table('clubunio.tenant_accounts')
                ->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->exists();
    }

    public function administrator(Account $actor, Tenant $tenant): bool
    {
        return $this->member($actor, $tenant) && ! request()->attributes->has('clubunio_impersonation')
            && app(AccountAdministrationService::class)->allowed($actor, $tenant);
    }

    public function allows(Account $actor, Tenant $tenant, string $ability): bool
    {
        if (! $this->ready() || ! $this->member($actor, $tenant)) {
            return false;
        }

        return $this->allowedMember($actor, $tenant, $ability, $this->grantFor($actor, $tenant));
    }

    private function grantFor(Account $actor, Tenant $tenant): ?object
    {
        return DB::connection('clubunio')->table('clubunio.clubhouse_grants')
            ->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('active', true)->first();
    }

    private function allowedMember(Account $actor, Tenant $tenant, string $ability, ?object $grant): bool
    {
        if (request()->attributes->has('clubunio_impersonation') && $ability !== 'view') {
            return false;
        }
        $permission = match ($ability) {
            'view' => ['clubhouse_hours', 'view'],
            'hours.write' => ['clubhouse_hours', 'update'],
            'menus.write' => ['clubhouse_menus', 'update'],
            'menus.publish' => ['clubhouse_menus', 'send'],
            default => null,
        };
        if (! $permission) {
            return false;
        }
        $modules = app(ModulePermissionService::class);
        if ($modules->allows($actor, $tenant, ...$permission)
            || ($ability === 'view' && $modules->allows($actor, $tenant, 'clubhouse_menus', 'view'))) {
            return true;
        }
        if (! $grant) {
            return false;
        }

        return match ($ability) {
            'view' => (bool) ($grant->hours_write || $grant->menus_write || $grant->menus_publish),
            'hours.write' => (bool) $grant->hours_write,
            'menus.write' => (bool) $grant->menus_write,
            'menus.publish' => (bool) $grant->menus_publish,
        };
    }

    public function authorize(Account $actor, Tenant $tenant, string $ability): void
    {
        abort_unless($this->allows($actor, $tenant, $ability), 403);
    }

    public function capabilities(Account $actor, Tenant $tenant): array
    {
        if (! $this->ready() || ! $this->member($actor, $tenant)) {
            return array_fill_keys(['manage', 'hours_write', 'menus_write', 'menus_publish', 'admin'], false);
        }
        $grant = $this->grantFor($actor, $tenant);

        return ['manage' => $this->allowedMember($actor, $tenant, 'view', $grant),
            'hours_write' => $this->allowedMember($actor, $tenant, 'hours.write', $grant),
            'menus_write' => $this->allowedMember($actor, $tenant, 'menus.write', $grant),
            'menus_publish' => $this->allowedMember($actor, $tenant, 'menus.publish', $grant),
            'admin' => $this->administrator($actor, $tenant)];
    }

    public function grant(Account $actor, Tenant $tenant, int $accountId, array $input): void
    {
        abort_unless($this->ready() && $this->administrator($actor, $tenant), 403);
        $data = Validator::make($input, ['hours_write' => 'required|boolean', 'menus_write' => 'required|boolean',
            'menus_publish' => 'required|boolean', 'active' => 'required|boolean', 'revision' => 'required|integer|min:0',
            'reason' => 'required|string|min:10|max:500'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $accountId, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->administrator($actor, $tenant), 403);
            DB::connection('clubunio')->table('clubunio.tenant_accounts')->where('tenant_id', $tenant->id)
                ->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
            $table = DB::connection('clubunio')->table('clubunio.clubhouse_grants')->where('tenant_id', $tenant->id)->where('account_id', $accountId);
            $old = (clone $table)->lockForUpdate()->first();
            if ((int) ($old->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => trans('clubhouse.conflict')]);
            }
            $values = collect($data)->only(['hours_write', 'menus_write', 'menus_publish', 'active'])->all()
                + ['revision' => (int) $data['revision'] + 1];
            if ($old) {
                $table->update($values);
            } else {
                $table->insert($values + ['tenant_id' => $tenant->id, 'account_id' => $accountId]);
            }
            $this->audit($actor, $tenant, 'grant', $accountId, 'saved', ['before' => $old, 'after' => $values, 'reason' => $data['reason']]);
        });
    }

    public function audit(Account $actor, Tenant $tenant, string $entity, ?int $id, string $operation, array $data = []): void
    {
        DB::connection('clubunio')->table('clubunio.clubhouse_events')->insert(['tenant_id' => $tenant->id,
            'actor_id' => $actor->id, 'entity' => $entity, 'object_id' => $id, 'operation' => $operation,
            'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()->toIso8601String()]);
    }
}
