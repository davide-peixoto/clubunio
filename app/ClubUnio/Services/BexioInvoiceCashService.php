<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;

class BexioInvoiceCashService
{
    public function fromPayments(array $payments, string $remoteId, array $balance): array
    {
        abort_unless(array_is_list($payments) && count($payments) < 500, 409, 'Zahlungsliste ist nicht vollständig.');
        $seen = [];
        $cash = 0;
        foreach ($payments as $payment) {
            $id = (string) ($payment['id'] ?? '');
            abort_unless(preg_match('/^[1-9][0-9]{0,14}$/D', $id) && ! isset($seen[$id]) && (string) ($payment['kb_invoice_id'] ?? '') === $remoteId, 409);
            $seen[$id] = true;
            if (empty($payment['kb_credit_voucher_id']) && ($payment['is_client_account_redemption'] ?? null) === false && ($payment['is_cash_discount'] ?? null) === false) {
                $cash += app(BexioBalanceService::class)->cents($payment['value'] ?? null);
            }
        }
        // Bexio caps invoice received-payments at the invoice total. Additional receipts live in its payment list.
        $excess = max(0, $cash + $balance['credit_cents'] - $balance['total_cents']);

        return ['cash_received_cents' => $cash, 'cash_excess_cents' => min($cash, $excess)];
    }

    public function read(Tenant $tenant, string $remoteId, array $balance): array
    {
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(BexioSandboxClient::class);
        $client->verify($token);
        $payments = $client->get($token, '/2.0/kb_invoice/'.$remoteId.'/payment', ['limit' => 500]);

        return $this->fromPayments($payments, $remoteId, $balance);
    }
}
