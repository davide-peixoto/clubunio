<?php

namespace App\ClubUnio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ErpNextStatusReconciliation
{
    public function run(int $limit): array
    {
        $results = ['confirmed' => 0, 'unconfirmed' => 0, 'failed' => 0, 'skipped' => 0];
        if (! config('clubunio-erpnext.enabled')) {
            return $results;
        }

        $now = CarbonImmutable::now();
        $currentCutoff = $now->subMinutes(4);
        $paidCutoff = $now->subDay();
        $rows = DB::connection('clubunio')->table('clubora.erpnext_invoice_transfers as transfer')
            ->join('clubora.tenants as tenant', 'tenant.id', '=', 'transfer.tenant_id')
            ->where('tenant.accounting_provider', AccountingProvider::ERPNEXT)
            ->where('transfer.state', '<>', 'cancelled')
            ->where(fn ($query) => $query->whereNull('transfer.last_status_attempt_at')
                ->orWhere(fn ($query) => $query->where(fn ($query) => $query->where('transfer.state', '<>', 'paid')
                    ->orWhere('transfer.status_sync_failed', true))
                    ->where('transfer.last_status_attempt_at', '<=', $currentCutoff))
                ->orWhere(fn ($query) => $query->where('transfer.state', 'paid')
                    ->where('transfer.last_status_attempt_at', '<=', $paidCutoff)))
            ->orderByRaw('CASE WHEN transfer.last_status_attempt_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('transfer.last_status_attempt_at')
            ->orderBy('transfer.invoice_id')
            ->limit(max(1, min(30, $limit)))
            ->get(['transfer.tenant_id', 'transfer.invoice_id']);

        $transfers = app(ErpNextInvoiceTransferService::class);
        foreach ($rows as $row) {
            $result = $transfers->reconcile((int) $row->tenant_id, (int) $row->invoice_id, $currentCutoff, $paidCutoff);
            $results[$result]++;
        }

        return $results;
    }
}
