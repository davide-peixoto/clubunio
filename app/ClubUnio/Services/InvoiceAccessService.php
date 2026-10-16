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
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'invoices', 'view')) {
            return $query;
        }

        if (! app(ExpenseService::class)->portalAllowed($actor, $tenant)) {
            return $query->whereRaw('1=0');
        }

        return $query->where(fn ($q) => $q->whereNull('workflow_status_id')->orWhereHas('workflowStatus', fn ($q) => $q->where('code', 'released')))
            ->whereIn('member_id', app(MemberAccessService::class)
                ->visibleMembers($actor, $tenant)->select('clubora.members.id'));
    }
}
