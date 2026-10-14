<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class BexioPayloadBuilder
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['dispatch' => $message]);
    }

    private function integer(Tenant $tenant, string $key, int $min = 1, int $max = 2147483647): int
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', $key)->first();
        try {
            $value = $row ? trim(Crypt::decryptString($row->encrypted_value)) : '';
        } catch (\Throwable) {
            $this->fail('Bexio-Parameter '.$key.' ist nicht lesbar.');
        }
        if (! preg_match('/^\d{1,10}$/D', $value) || (int) $value < $min || (int) $value > $max) {
            $this->fail('Bexio-Parameter '.$key.' fehlt oder ist ungültig.');
        }

        return (int) $value;
    }

    // Called only after document authorization/eligibility in BexioDispatchTrialService.
    public function build(Tenant $tenant, $document, array $positions, int $total): array
    {
        $member = $document->member;
        if ($document->tenant_id != $tenant->id || $member?->tenant_id != $tenant->id) {
            abort(403);
        }
        if (! ($document instanceof Invoice) && (! ctype_digit((string) $member->bexio_contact_id) || (int) $member->bexio_contact_id < 1 || (int) $member->bexio_contact_id > 2147483647)) {
            $this->fail('Ungültige Bexio-Kontaktzuordnung.');
        }
        $money = app(InvoiceWorkflowService::class);
        if ($document instanceof Invoice) {
            if (count($positions) > 150) {
                $this->fail('Maximal 150 Rechnungspositionen pro Versandprüfung.');
            }
            $contactId = app(BexioBillingRecipientService::class)->resolve($member);
            $inclusive = $document->tax_mode === 'inclusive';
            if (! $inclusive && $this->integer($tenant, 'BEXIO_MWST_TYPE', 0, 2) !== 2) {
                $this->fail('Steuerbefreite Rechnungen benötigen BEXIO_MWST_TYPE=2.');
            }
            $payload = ['contact_id' => $contactId, 'title' => 'Rechnung #'.($document->legacy_id ?? $document->id),
                'api_reference' => 'clubunio:'.$tenant->id.':invoice:'.$document->id, 'mwst_type' => $inclusive ? 0 : 2, 'mwst_is_net' => ! $inclusive, 'show_position_taxes' => $inclusive];
            foreach (['user_id', 'language_id', 'bank_account_id', 'currency_id', 'payment_type_id'] as $field) {
                $payload[$field] = $this->integer($tenant, 'BEXIO_'.strtoupper($field));
            }
            $date = CarbonImmutable::parse($document->created_at)->setTimezone('Europe/Zurich')->startOfDay();
            $payload['is_valid_from'] = $date->toDateString();
            $payload['is_valid_to'] = $date->addDays($this->integer($tenant, 'BEXIO_INVOICE_DUE_DAYS', 0, 365))->toDateString();
            $unit = $this->integer($tenant, 'BEXIO_UNIT_ID');
            $tax = $this->integer($tenant, 'BEXIO_TAX_ID');
            $payload['positions'] = [];
            foreach ($positions as $line) {
                // Pre-discounted fixed price avoids percentage rounding and cumulative discount ambiguity.
                $position = ['type' => 'KbPositionCustom', 'text' => htmlspecialchars($line['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'amount' => '1', 'unit_id' => $unit, 'tax_id' => $inclusive ? $line['tax_snapshot']['remote_id'] : $tax, 'unit_price' => $money->decimal($line['net'])];
                if (($line['discount'] ?? 0) > 0) {
                    $position['text'] .= '<br>Brutto CHF '.$money->decimal($line['gross']).' / Rabatt CHF '.$money->decimal($line['discount']).' / Endbetrag CHF '.$money->decimal($line['net']);
                }
                if ($line['article'] !== null) {
                    if (! ctype_digit((string) $line['article']) || (int) $line['article'] < 1 || (int) $line['article'] > 2147483647) {
                        $this->fail('Ungültige Bexio-Artikelzuordnung.');
                    }
                    $position['type'] = 'KbPositionArticle';
                    $position['article_id'] = (int) $line['article'];
                }
                $payload['positions'][] = $position;
            }

            return ['method' => 'POST', 'path' => '/2.0/kb_invoice', 'payload' => $payload];
        }
        if (count($positions) > 100) {
            $this->fail('Maximal 100 Spesenpositionen pro Bexio-Lieferantenrechnung.');
        }
        if (! $document->payment_released_on) {
            $this->fail('Datum der Zahlungsfreigabe fehlt.');
        }
        $country = $member->country;
        if (! $country || ! $country->active || $country->tenant_id != $tenant->id || ! preg_match('/^[A-Z]{2}$/D', (string) $country->code)) {
            $this->fail('Für die Lieferantenadresse ist ein gültiger ISO-2-Ländercode erforderlich.');
        }
        $address = ['firstname_suffix' => trim((string) $member->first_name), 'lastname_company' => trim((string) ($member->company ?: $member->last_name)),
            'address_line' => trim($member->street.' '.$member->house_number), 'postcode' => trim((string) $member->postal_code), 'city' => trim((string) $member->city),
            'country_code' => $country->code, 'type' => 'PRIVATE'];
        foreach (['lastname_company', 'address_line', 'postcode', 'city'] as $field) {
            if ($address[$field] === '' || mb_strlen($address[$field]) > 255) {
                $this->fail('Lieferantenadresse fehlt oder ist ungültig: '.$field);
            }
        }
        $date = CarbonImmutable::parse($document->payment_released_on)->startOfDay();
        $payload = ['supplier_id' => (int) $member->bexio_contact_id, 'vendor_ref' => 'clubunio:'.$tenant->id.':expense:'.$document->id,
            'title' => 'Spesen #'.($document->legacy_id ?? $document->id), 'contact_partner_id' => $this->integer($tenant, 'BEXIO_BILL_CONTACT_PARTNER_ID'),
            'bill_date' => $date->toDateString(), 'due_date' => $date->addDays($this->integer($tenant, 'BEXIO_BILL_DUE_DAYS', 0, 365))->toDateString(),
            'currency_code' => 'CHF', 'item_net' => false, 'manual_amount' => false, 'amount_calc' => (float) $money->decimal($total),
            'address' => $address, 'attachment_ids' => [], 'discounts' => [], 'line_items' => []];
        foreach ($positions as $i => $line) {
            if ((int) $line['account'] > 2147483647) {
                $this->fail('Ungültiges Buchungskonto.');
            }
            $payload['line_items'][] = ['position' => $i, 'amount' => (float) $money->decimal($line['net']), 'title' => mb_substr($line['description'], 0, 80), 'tax_id' => null, 'booking_account_id' => (int) $line['account']];
        }

        return ['method' => 'POST', 'path' => '/4.0/purchase/bills', 'payload' => $payload];
    }
}
