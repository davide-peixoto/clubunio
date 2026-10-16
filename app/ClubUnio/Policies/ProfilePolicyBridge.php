<?php

namespace App\ClubUnio\Policies;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\ModulePermissionService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Extra model authorization for profile accounts; legacy policies retain their existing rules. */
final class ProfilePolicyBridge
{
    public function check(Account $actor, string $ability, array $arguments): ?bool
    {
        $record = $arguments[0] ?? null;
        $tenant = Filament::getTenant();
        if (! $tenant && $record instanceof Model) {
            $tenant = $record->tenant;
        }
        if (! $tenant) {
            return $actor->tenants()->wherePivot('role', 'profile')->exists() ? false : null;
        }
        $permissions = app(ModulePermissionService::class);
        if (! $permissions->isProfile($actor, $tenant)) {
            return null;
        }
        if (! is_string($record) && ! $record instanceof Model) {
            return false;
        }
        $function = match ($ability) {
            'viewAny', 'view' => 'view', 'create' => 'create', 'update' => 'update',
            default => null,
        };
        if ($function === null || ! $permissions->allowsModel($actor, $tenant, $record, $function)) {
            return false;
        }
        // ReadOnlyPolicy intentionally forces changes through audited domain actions.
        $policy = Gate::getPolicyFor($record);
        if ($function !== 'view' && $policy instanceof ReadOnlyPolicy) {
            return false;
        }
        if ($ability === 'update' && $record instanceof Member && $record->archived_at) {
            return false;
        }
        return true;
    }
}
