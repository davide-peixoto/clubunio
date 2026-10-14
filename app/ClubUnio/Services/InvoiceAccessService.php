<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

class InvoiceAccessService
{
    public function visible(Account $actor, Tenant $tenant): Builder
    {
        $query = Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true);
        if ($actor->canAccessTenant($tenant)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('workflow_status_id')->orWhereHas('workflowStatus', fn ($q) => $q->where('code', 'released')))
            ->whereIn('member_id', app(MemberAccessService::class)
                ->visibleMembers($actor, $tenant)->select('clubora.members.id'));
    }
}
