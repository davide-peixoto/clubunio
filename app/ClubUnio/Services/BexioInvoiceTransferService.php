<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class BexioInvoiceTransferService
{
    public function transfer(Account $actor, Tenant $tenant, int $id, int $revision): object
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'send');
        app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);

        return app(SyncWorkService::class)->run($actor, $tenant, 'invoice_transfer', $id, compact('revision'));
    }

    public function transferNow(Account $actor, Tenant $tenant, int $id, int $revision): object
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'send');
        $dispatch = app(BexioSandboxDispatchService::class);
        $dispatch->authorize($actor, $tenant);
        $invoice = Invoice::where('tenant_id', $tenant->id)->where('active', true)->whereNull('legacy_id')->findOrFail($id);
        $existing = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('kind', 'invoice')->where('invoice_id', $id)->first();
        if ($existing) {
            if ($existing->state === 'accepted') {
                $dispatch->statusDocument($actor, $tenant, 'invoice', $id);
            } elseif ($existing->state === 'ready') {
                $dispatch->send($actor, $tenant, $existing->id);
            } else {
                $dispatch->reconcile($actor, $tenant, $existing->id);
            }
            $dispatchId = $existing->id;
        } else {
            abort_unless((int) $invoice->revision === $revision && ! $invoice->bexio_invoice_id && $invoice->workflowStatus?->code === 'released', 409, 'Rechnung zuerst intern freigeben oder neu laden.');
            $recipient = app(BexioSandboxRecipientService::class)->prepare($actor, $tenant, $invoice);
            abort_unless((int) $invoice->fresh()->revision === $revision, 409, 'Rechnung wurde inzwischen geändert.');
            $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $id, $recipient);
            $dispatchId = $dispatch->prepareTrial($actor, $tenant, $trial);
            $dispatch->send($actor, $tenant, $dispatchId);
        }

        return DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->find($dispatchId);
    }
}
