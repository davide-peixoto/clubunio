<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Fresh authorization for a single operation; never a replacement for record ownership checks. */
final class ModulePermissionService
{
    public function allows(Account $actor, Tenant $tenant, string $module, string $function): bool
    {
        if (! array_key_exists($module, PermissionCatalog::MODULES)
            || ! array_key_exists($function, PermissionCatalog::functions($module))) {
            return false;
        }
        $db = DB::connection('clubunio');
        $link = $db->table('clubora.tenant_accounts as membership')
            ->join('clubora.accounts as account', 'account.id', '=', 'membership.account_id')
            ->where('membership.account_id', $actor->id)->where('membership.tenant_id', $tenant->id)
            ->where('account.active', true)->select('membership.*')->first();
        if (! $link) {
            return false;
        }
        if (in_array($link->role, ['admin', 'office'], true)) {
            return true;
        }
        if ($link->role !== 'profile' || ! ($link->access_profile_id ?? null)) {
            return false;
        }
        if (! $db->table('clubora.account_roles')->where('code', 'profile')->where('active', true)->exists()) {
            return false;
        }
        $profile = $db->table('clubora.access_profiles')->where('tenant_id', $tenant->id)
            ->where('id', $link->access_profile_id)->where('active', true)->first();
        if (! $profile) {
            return false;
        }
        $permissions = json_decode($profile->permissions, true, flags: JSON_THROW_ON_ERROR);

        return in_array($module.'.view', $permissions, true) && in_array($module.'.'.$function, $permissions, true);
    }

    public function authorize(Account $actor, Tenant $tenant, string $module, string $function): void
    {
        abort_unless($this->allows($actor, $tenant, $module, $function), 403);
    }
}
