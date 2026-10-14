<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Dedicated, fixed endpoint allowlist. Never a replacement for the general mock client.
class BexioSandboxClient
{
    public function createTestArticle(string $token, array $configuration): array
    {
        $this->verify($token);
        $payload = ['intern_code' => BexioCatalogFixtureService::CODE, 'intern_name' => 'ClubUnio Sandbox Testartikel',
            'remarks' => BexioCatalogFixtureService::MARKER, 'article_type_id' => 2, 'is_stock' => false,
            'sale_price' => '1.00', 'sale_total' => '1.00'];
        foreach (['user_id', 'currency_id', 'unit_id', 'tax_income_id', 'tax_expense_id', 'account_id'] as $field) {
            abort_unless(isset($configuration[$field]) && is_int($configuration[$field]) && $configuration[$field] > 0, 422);
            $payload[$field] = $configuration[$field];
        }
        try {
            $response = $this->request($token)->post('https://api.bexio.com/2.0/article', $payload);
            $id = $response->json('id');
            $state = in_array($response->status(), [400, 401, 403, 422], true) ? 'rejected'
                : ($response->successful() && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) ? 'accepted' : 'uncertain');

            return ['state' => $state, 'http_status' => $response->status(), 'id' => $state === 'accepted' ? (int) $id : null];
        } catch (\Throwable) {
            return ['state' => 'uncertain', 'http_status' => null, 'id' => null];
        }
    }

    private function request(string $token)
    {
        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)->withoutRedirecting();
    }

    public function get(string $token, string $path, array $query = []): array
    {
        if (! preg_match('~^/(2\.0/(company_profile|article(?:/[1-9][0-9]*)?|contact(?:/[1-9][0-9]*)?|kb_invoice(?:/[1-9][0-9]*(?:/payment)?)?|language|payment_type|unit|accounts)|3\.0/(files|users/me|currencies|banking/accounts|taxes)|4\.0/purchase/(bills(?:/[a-f0-9-]{36})?|outgoing-payments(?:/[a-f0-9-]{36})?))$~D', $path)) {
            throw new \LogicException('Unsupported read');
        }
        $response = $this->request($token)->get('https://api.bexio.com'.$path, $query);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Sandbox read failed', $response->status());
        }

        return $response->json();
    }

    public function verify(string $token): void
    {
        $profiles = $this->get($token, '/2.0/company_profile');
        if (count($profiles) !== 1 || ($profiles[0]['name'] ?? '') !== 'SVS Api Test' || (int) ($profiles[0]['id'] ?? 0) !== 1) {
            throw new \RuntimeException('Sandbox identity mismatch', 409);
        }
    }

    public function send(string $token, string $kind, array $payload = [], ?string $pdf = null, string $filename = 'clubora-sandbox-receipt.pdf', string $mime = 'application/pdf'): array
    {
        $path = match ($kind) {
            'invoice' => '/2.0/kb_invoice', 'expense' => '/4.0/purchase/bills', 'upload' => '/3.0/files',
            default => throw new \LogicException('Unsupported write'),
        };
        // Fail before POST if the token no longer belongs to the expected sandbox.
        $this->verify($token);
        try {
            $request = $this->request($token);
            $response = $kind === 'upload'
                ? $request->attach('file', $pdf, $filename, ['Content-Type' => $mime])->post('https://api.bexio.com'.$path)
                : $request->post('https://api.bexio.com'.$path, $payload);
            $status = $response->status();
            if (in_array($status, [400, 401, 403, 422], true)) {
                return ['state' => 'rejected', 'http_status' => $status];
            }
            $id = $kind === 'upload' ? $response->json('0.uuid') : $response->json('id');
            if (! $response->successful() || ! is_scalar($id)
                || ($kind === 'invoice' ? ! preg_match('/^[1-9][0-9]{0,14}$/D', (string) $id) : ! Str::isUuid((string) $id))) {
                return ['state' => 'uncertain', 'http_status' => $status];
            }

            return ['state' => 'accepted', 'http_status' => $status, 'id' => (string) $id];
        } catch (\Throwable) {
            // No retry, response bodies or tokens in logs. Timeout may follow successful creation.
            return ['state' => 'uncertain', 'http_status' => null];
        }
    }

    public function invoiceAction(string $token, string $id, string $action): array
    {
        if (! preg_match('/^[1-9][0-9]{0,14}$/D', $id) || ! in_array($action, ['issue', 'cancel'], true)) {
            throw new \LogicException('Unsupported invoice action');
        }
        $this->verify($token);
        try {
            $response = $this->request($token)->post('https://api.bexio.com/2.0/kb_invoice/'.$id.'/'.$action);

            return ['state' => $response->successful() && $response->json('success') === true ? 'accepted' : 'uncertain', 'http_status' => $response->status()];
        } catch (\Throwable) {
            return ['state' => 'uncertain', 'http_status' => null];
        }
    }

    public function financialAction(string $token, string $kind, string $id, string $operation, int $cents, int $bankId, string $date, string $reference): array
    {
        if ($cents < 0 || $bankId < 1 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! Str::isUuid($reference)) {
            throw new \LogicException('Invalid financial action');
        }
        $invoice = $kind === 'invoice' && preg_match('/^[1-9][0-9]{0,14}$/D', $id) && in_array($operation, ['payment_partial', 'payment_full'], true);
        $bill = $kind === 'expense' && Str::isUuid($id) && in_array($operation, ['bill_book', 'bill_pay'], true);
        if (! $invoice && ! $bill) {
            throw new \LogicException('Unsupported financial action');
        }
        $this->verify($token);
        $money = app(InvoiceWorkflowService::class)->decimal($cents);
        try {
            if ($operation === 'bill_book') {
                $response = $this->request($token)->put('https://api.bexio.com/4.0/purchase/bills/'.$id.'/bookings/BOOKED');
            } elseif ($invoice) {
                $response = $this->request($token)->post('https://api.bexio.com/2.0/kb_invoice/'.$id.'/payment',
                    ['date' => $date, 'value' => $money, 'bank_account_id' => $bankId, 'payment_service_id' => null]);
            } else {
                // MANUAL records a completed payment in accounting; it cannot create a bank instruction.
                $response = $this->request($token)->post('https://api.bexio.com/4.0/purchase/outgoing-payments',
                    ['bill_id' => $id, 'payment_type' => 'MANUAL', 'execution_date' => $date, 'amount' => (float) $money,
                        'currency_code' => 'CHF', 'exchange_rate' => 1, 'sender_bank_account_id' => $bankId, 'is_salary_payment' => false, 'note' => 'clubora-sandbox:'.$reference]);
            }
            $remoteId = $response->json('id');
            $validId = is_scalar($remoteId) && ($invoice ? preg_match('/^[1-9][0-9]{0,14}$/D', (string) $remoteId) : Str::isUuid((string) $remoteId));

            return ['state' => in_array($response->status(), [400, 401, 403, 422], true) ? 'rejected'
                : ($response->successful() && $validId ? 'accepted' : 'uncertain'),
                'http_status' => $response->status(), 'remote_id' => $validId ? (string) $remoteId : null];
        } catch (\Throwable) {
            return ['state' => 'uncertain', 'http_status' => null, 'remote_id' => null];
        }
    }
}
