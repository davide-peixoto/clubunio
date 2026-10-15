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
        return $this->fromPayments($this->payments($tenant, $remoteId), $remoteId, $balance);
    }

    public function creditEvidence(Tenant $tenant, string $remoteId, array $balance): array
    {
        return $this->evidenceFromPayments($this->payments($tenant, $remoteId), $remoteId, $balance);
    }

    public function evidenceFromPayments(array $payments, string $remoteId, array $balance): array
    {
        $cash = $this->fromPayments($payments, $remoteId, $balance);
        $allocations = [];
        $total = 0;
        foreach ($payments as $payment) {
            if (empty($payment['kb_credit_voucher_id'])) {
                continue;
            }
            $voucher = (string) $payment['kb_credit_voucher_id'];
            abort_unless(preg_match('/^[1-9][0-9]{0,14}$/D', $voucher)
                && ($payment['is_client_account_redemption'] ?? null) === false && ($payment['is_cash_discount'] ?? null) === false, 409);
            $text = trim((string) ($payment['kb_credit_voucher_text'] ?? ''));
            // The provider exposes the number in the invoice payment, not a credit-note read endpoint.
            $reference = preg_match('/^(?:Gutschrift\s+)?(GS-[0-9]{1,20})$/iuD', $text, $match) ? strtoupper($match[1]) : null;
            $amount = app(BexioBalanceService::class)->cents($payment['value'] ?? null);
            abort_unless($amount > 0, 409);
            if (isset($allocations[$voucher])) {
                abort_unless($allocations[$voucher]['reference'] === $reference, 409, 'Widersprüchliche Gutschriftsnummer.');
                $allocations[$voucher]['amount_cents'] += $amount;
                $allocations[$voucher]['payment_ids'][] = (string) $payment['id'];
            } else {
                $allocations[$voucher] = ['voucher_id' => $voucher, 'reference' => $reference, 'amount_cents' => $amount, 'payment_ids' => [(string) $payment['id']]];
            }
            $total += $amount;
        }
        abort_unless($total === $balance['credit_cents'], 409, 'Gutschriftsliste und Rechnungssaldo stimmen nicht überein.');

        return ['cash' => $cash, 'credit_allocations' => array_values($allocations)];
    }

    private function payments(Tenant $tenant, string $remoteId): array
    {
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(BexioSandboxClient::class);
        $client->verify($token);

        return $client->get($token, '/2.0/kb_invoice/'.$remoteId.'/payment', ['limit' => 500]);
    }
}
