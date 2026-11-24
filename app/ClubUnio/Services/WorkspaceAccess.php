<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Filament\Facades\Filament;

class WorkspaceAccess
{
    public function allowed(?Account $actor, ?Tenant $tenant): bool
    {
        return $actor && $tenant && $actor->active && ! $actor->api_only
            && ! request()->attributes->has('clubunio_impersonation')
            && $actor->canAccessTenant($tenant) && app(ModulePermissionService::class)->canEnter($actor, $tenant);
    }

    public function context(): array
    {
        $actor = Filament::auth()->user()?->fresh();
        $tenant = Filament::getTenant();
        abort_unless($this->allowed($actor, $tenant), 403);
        return [$actor, $tenant];
    }
}
