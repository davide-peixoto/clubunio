<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Invoice;

/** Display aggregates live only on this server-loaded model, not in a shared cache. */
class InvoiceDetailTotals
{
    public function for(Invoice $invoice): array
    {
        if (! array_key_exists('detail_pending_credits_cents', $invoice->getAttributes())) {
            $scope = fn ($query, array $codes) => $query->where('tenant_id', $invoice->tenant_id)
                ->whereHas('status', fn ($q) => $q->where('tenant_id', $invoice->tenant_id)->whereIn('code', $codes));
            $invoice->loadSum([
                'creditNotes as detail_pending_credits_cents' => fn ($q) => $scope($q, ['draft', 'released']),
                'refunds as detail_pending_refunds_cents' => fn ($q) => $scope($q, ['draft']),
                'refunds as detail_confirmed_refunds_cents' => fn ($q) => $scope($q, ['confirmed']),
                'refunds as detail_reserved_refunds_cents' => fn ($q) => $q->where('tenant_id', $invoice->tenant_id)
                    ->whereHas('status', fn ($q) => $q->where('tenant_id', $invoice->tenant_id)->where('code', '<>', 'cancelled')),
            ], 'amount_cents');
        }

        return array_map(fn ($key) => (int) $invoice->getAttribute($key), [
            'pending_credits' => 'detail_pending_credits_cents', 'pending_refunds' => 'detail_pending_refunds_cents',
            'confirmed_refunds' => 'detail_confirmed_refunds_cents', 'reserved_refunds' => 'detail_reserved_refunds_cents',
        ]);
    }
}
