<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;

class AnnouncementChoices
{
    public function search(Tenant $tenant, string $type, string $search): array
    {
        return $this->options($tenant, $type, $search, null);
    }

    public function labels(Tenant $tenant, string $type, array $values): array
    {
        if (! $values || count($values) > 500) {
            return [];
        }
        $values = array_filter($values, fn ($value) => is_scalar($value) && ($type === 'roles'
            ? is_string($value) && strlen($value) <= 30
            : filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0));

        return $values ? $this->options($tenant, $type, null, $values) : [];
    }

    private function options(Tenant $tenant, string $type, ?string $search, ?array $values): array
    {
        [$query, $label, $key] = match ($type) {
            'roles' => [AccountRole::where('active', true), 'name', 'code'],
            'teams' => [TeamSeason::where('tenant_id', $tenant->id)->where('active', true), 'label', 'id'],
            'accounts' => [Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id)), 'display_name', 'id'],
            default => [null, null, null],
        };
        if (! $query) {
            return [];
        }
        if ($values !== null) {
            $query->whereIn($key, $values);
        } elseif ($search !== '') {
            $query->where($label, 'ilike', '%'.$search.'%');
        }

        return $query->orderBy($label)->orderBy($key)->limit($values === null ? 50 : 500)->pluck($label, $key)->all();
    }
}
