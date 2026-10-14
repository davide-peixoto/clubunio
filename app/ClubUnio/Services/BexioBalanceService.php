<?php

namespace App\ClubUnio\Services;

use Illuminate\Validation\ValidationException;

class BexioBalanceService
{
    public function cents(mixed $value): int
    {
        if (is_float($value)) {
            $value = sprintf('%.6F', $value);
        }
        if (! is_scalar($value) || ! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', (string) $value)) {
            throw ValidationException::withMessages(['balance' => 'Bexio-Betrag fehlt oder ist ungueltig.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');
        $fraction = str_pad($fraction, 6, '0');
        if (substr($fraction, 2) !== '0000') {
            throw ValidationException::withMessages(['balance' => 'Bexio-Betrag enthaelt Bruchteile von Rappen.']);
        }

        return (int) $whole * 100 + (int) substr($fraction, 0, 2);
    }

    public function normalize(string $kind, array $data, int $expected): array
    {
        if ($kind === 'invoice') {
            $total = $this->cents($data['total'] ?? null);
            $paid = $this->cents($data['total_received_payments'] ?? null);
            $credit = $this->cents($data['total_credit_vouchers'] ?? null);
            $remaining = $this->cents($data['total_remaining_payments'] ?? null);
            $status = (string) ($data['kb_item_status_id'] ?? '');
            $invalidStatus = ($status === '9' && $remaining !== 0)
                || ($status === '16' && ($remaining <= 0 || $paid + $credit <= 0))
                || (in_array($status, ['7', '8', '31'], true) && $paid > 0);
        } else {
            $total = $this->cents($data[($data['manual_amount'] ?? false) ? 'amount_man' : 'amount_calc'] ?? null);
            $remaining = $this->cents($data['pending_amount'] ?? null);
            $credit = 0;
            $paid = $total - $remaining;
            $status = (string) ($data['status'] ?? '');
            $invalidStatus = ($status === 'PAID' && $remaining !== 0) || ($status === 'DRAFT' && $paid !== 0);
            if (($data['currency_code'] ?? '') !== 'CHF') {
                throw ValidationException::withMessages(['balance' => 'Finanzabgleich unterstuetzt nur CHF-Belege.']);
            }
        }
        if ($total !== $expected || $paid < 0 || $total !== $paid + $credit + $remaining || $invalidStatus) {
            throw ValidationException::withMessages(['balance' => 'Bexio-Status, Zahlungen, Gutschriften oder Restbetrag sind widerspruechlich.']);
        }

        return ['total_cents' => $total, 'paid_cents' => $paid, 'credit_cents' => $credit, 'remaining_cents' => $remaining];
    }
}
