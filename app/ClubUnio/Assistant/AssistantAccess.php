<?php

namespace App\ClubUnio\Assistant;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ImpersonationService;
use Illuminate\Support\Facades\DB;

class AssistantAccess
{
    public function allows(Account $actor, Tenant $tenant): bool
    {
        if (! config('clubunio-assistant.enabled') || request()->attributes->has('clubunio_impersonation')
            || (request()->hasSession() && request()->session()->has(ImpersonationService::KEY))) {
            return false;
        }

        // Deliberately bypass request-level permission snapshots for every agent step.
        return DB::connection('clubunio')->table('clubunio.accounts as a')
            ->join('clubunio.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
            ->join('clubunio.tenants as t', 't.id', '=', 'ta.tenant_id')
            ->where('a.id', $actor->id)->where('a.active', true)->where('a.api_only', false)
            ->where('ta.tenant_id', $tenant->id)->whereIn('ta.role', ['admin', 'office'])->exists();
    }

    public function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless($this->allows($actor, $tenant), 403);
    }
}
