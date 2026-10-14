<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BexioFinancialActionService
{
    private function table()
    {
        return DB::connection('clubunio')->table('clubora.bexio_financial_actions');
    }

    private function remote(Account $actor, Tenant $tenant, string $kind, int $objectId): array
    {
        $service = app(BexioSandboxDispatchService::class);
        $row = $service->statusDocument($actor, $tenant, $kind, $objectId);
        $data = $service->readStatus($actor, $tenant, $kind, $objectId);
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        abort_unless((string) ($data['id'] ?? '') === $row->remote_id, 409);
        if ($kind === 'invoice') {
            $service->invoiceContactLabel($tenant, (int) $payload['contact_id']);
            abort_unless((int) ($data['currency_id'] ?? 0) === (int) $payload['currency_id'], 409);
        }
        $document = ($kind === 'invoice' ? Invoice::class : Expense::class)::where('tenant_id', $tenant->id)->findOrFail($objectId);
        $money = app(BexioBalanceService::class);
        $expected = (int) ($row->expected_cents ?? ($kind === 'invoice' ? 2500 : 1500));
        $local = $kind === 'invoice' ? app(InvoiceTaxService::class)->summary($document, true)['total_cents'] : $money->cents((string) $document->activeLines()->sum('amount'));
        abort_unless($local === $expected, 409, 'Lokale Positionen wurden geaendert.');
        if ($kind === 'invoice') {
            app(InvoiceTaxService::class)->assertRemote($document, $payload, $data);
        }
        $balance = $money->normalize($kind, $data, $expected);

        return [$row, $data, $balance];
    }

    private function payments(Tenant $tenant, $row): array
    {
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(BexioSandboxClient::class);
        $client->verify($token);
        $data = $row->kind === 'invoice'
            ? $client->get($token, '/2.0/kb_invoice/'.$row->remote_id.'/payment', ['limit' => 500])
            : $client->get($token, '/4.0/purchase/outgoing-payments', ['bill_id' => $row->remote_id, 'limit' => 100]);
        if ($row->kind === 'expense') {
            abort_unless(isset($data['data'], $data['paging']['page_count']) && (int) $data['paging']['page_count'] <= 1, 409, 'Zahlungsliste ist nicht vollstaendig.');
            $data = $data['data'];
        }
        abort_unless(array_is_list($data) && count($data) < ($row->kind === 'invoice' ? 500 : 100), 409);
        $ids = [];
        foreach ($data as $payment) {
            $id = (string) ($payment['id'] ?? '');
            abort_unless($row->kind === 'invoice' ? preg_match('/^[1-9][0-9]{0,14}$/D', $id) : Str::isUuid($id), 409);
            abort_unless((string) ($payment[$row->kind === 'invoice' ? 'kb_invoice_id' : 'bill_id'] ?? '') === $row->remote_id && ! isset($ids[$id]), 409);
            $ids[$id] = true;
        }

        return $data;
    }

    public function execute(Account $actor, Tenant $tenant, string $kind, int $objectId, string $operation): void
    {
        abort_unless(($kind === 'invoice' && in_array($operation, ['payment_partial', 'payment_full', 'payment_overpaid'], true))
            || ($kind === 'expense' && in_array($operation, ['bill_book', 'bill_pay'], true)), 422);
        $guard = app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, $kind, $objectId);
        $existing = $this->table()->where('dispatch_id', $guard->id)->where('operation', $operation)->first();
        if ($existing) {
            abort_unless($existing->state === 'accepted', 409, 'Aktion bereits gestartet. Ergebnis abgleichen.');

            return;
        }
        $this->released($tenant, $kind, $objectId);
        [$row, $remote, $balance] = $this->remote($actor, $tenant, $kind, $objectId);
        $status = $remote[$kind === 'invoice' ? 'kb_item_status_id' : 'status'];
        abort_unless($kind === 'invoice' ? ($operation === 'payment_overpaid' ? (int) $status === 9 : in_array((int) $status, [8, 16, 31], true))
            : ($operation === 'bill_book' ? $status === 'DRAFT' : $status === 'BOOKED'), 409, 'Status erlaubt diese Testbuchung nicht.');
        abort_unless(($operation === 'payment_overpaid' ? $balance['remaining_cents'] === 0 && $balance['paid_cents'] === $balance['total_cents'] && $balance['overpaid_cents'] === 0
            : $balance['remaining_cents'] > 0) && $balance['credit_cents'] === 0, 409);
        if ($kind === 'expense') {
            abort_unless(($remote['payment'] ?? null) === null, 409, 'Vorhandene Zahlungsdaten zuerst klaeren.');
        }
        $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', 'BEXIO_BANK_ACCOUNT_ID')->firstOrFail();
        $bankId = Crypt::decryptString($setting->encrypted_value);
        abort_unless(ctype_digit($bankId) && (int) $bankId > 0, 409);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $banks = app(BexioSandboxClient::class)->get($token, '/3.0/banking/accounts');
        abort_unless(collect($banks)->contains(fn ($bank) => (int) $bank['id'] === (int) $bankId && in_array($bank['type'] ?? '', ['bank', 'cash'], true)), 409);
        $payments = $operation === 'bill_book' ? [] : $this->payments($tenant, $row);
        if ($kind === 'expense' && $operation === 'bill_pay') {
            abort_unless($payments === [], 409, 'Bereits vorhandene Zahlungen zuerst abgleichen.');
        }
        $cents = $operation === 'payment_overpaid' ? 500 : ($operation === 'bill_book' ? 0 : ($operation === 'payment_partial' ? intdiv($balance['remaining_cents'], 2) : $balance['remaining_cents']));
        abort_unless($operation === 'bill_book' || $cents > 0, 409);
        $date = now('Europe/Zurich')->toDateString();
        if ($kind === 'expense') {
            abort_unless(($remote['bill_date'] ?? '9999-12-31') <= $date, 409);
        }
        $reference = (string) Str::uuid();
        $id = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $kind, $objectId, $row, $operation, $cents, $bankId, $date, $reference, $payments) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $current = app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, $kind, $objectId);
            $this->released($tenant, $kind, $objectId);
            abort_unless(hash_equals($current->connection_hash, $row->connection_hash), 409);
            abort_if($this->table()->where('dispatch_id', $row->id)->where(fn ($q) => $q->where('operation', $operation)->orWhereIn('state', ['running', 'uncertain']))->exists()
                || DB::connection('clubunio')->table('clubora.bexio_invoice_actions')->where('dispatch_id', $row->id)->where('state', '<>', 'accepted')->exists(), 409);

            return $this->table()->insertGetId(['dispatch_id' => $row->id, 'actor_id' => $actor->id, 'operation' => $operation, 'state' => 'running',
                'reference' => $reference, 'connection_hash' => $row->connection_hash, 'amount_cents' => $cents, 'bank_id' => (int) $bankId, 'payment_date' => $date,
                'previous_ids' => json_encode(array_map(fn ($payment) => (string) $payment['id'], $payments), JSON_THROW_ON_ERROR)]);
        });
        try {
            $result = app(BexioSandboxClient::class)->financialAction($token, $kind, $row->remote_id, $operation, $cents, (int) $bankId, $date, $reference);
            $this->table()->where('id', $id)->update(['state' => $result['state'] === 'rejected' ? 'rejected' : 'uncertain', 'remote_id' => $result['remote_id'], 'http_status' => $result['http_status']]);
            if ($result['state'] !== 'rejected') {
                $this->reconcile($actor, $tenant, $kind, $objectId);
            }
        } catch (\Throwable $error) {
            $this->table()->where('id', $id)->where('state', 'running')->update(['state' => 'uncertain']);
            throw $error;
        }
    }

    private function released(Tenant $tenant, string $kind, int $objectId): void
    {
        $document = ($kind === 'invoice' ? Invoice::class : Expense::class)::where('tenant_id', $tenant->id)->findOrFail($objectId);
        abort_unless($kind === 'invoice' ? $document->workflowStatus?->code === 'released'
            : ($document->status?->code === 'Z' && $document->payment_released_on), 409, 'Beleg zuerst lokal freigeben.');
    }

    public function reconcile(Account $actor, Tenant $tenant, string $kind, int $objectId): void
    {
        [$row, $remote, $balance] = $this->remote($actor, $tenant, $kind, $objectId);
        $actions = $this->table()->where('dispatch_id', $row->id)->whereIn('state', ['running', 'uncertain'])->get();
        $payments = $actions->contains(fn ($a) => $a->operation !== 'bill_book') ? $this->payments($tenant, $row) : [];
        if ($kind === 'expense' && $payments) {
            $token = app(BexioConnectionService::class)->accessToken($tenant);
            $client = app(BexioSandboxClient::class);
            $client->verify($token);
            foreach ($payments as &$payment) {
                $detail = $client->get($token, '/4.0/purchase/outgoing-payments/'.$payment['id']);
                abort_unless(($detail['id'] ?? '') === $payment['id'] && ($detail['bill_id'] ?? '') === $row->remote_id, 409);
                $payment = $detail;
            }
            unset($payment);
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $kind, $objectId, $row, $remote, $payments, $balance) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, $kind, $objectId);
            foreach ($this->table()->where('dispatch_id', $row->id)->whereIn('state', ['running', 'uncertain'])->lockForUpdate()->get() as $action) {
                abort_unless(hash_equals($action->connection_hash, $row->connection_hash), 409);
                $found = null;
                if ($action->operation === 'bill_book') {
                    if (($remote['status'] ?? '') === 'BOOKED') {
                        $found = $row->remote_id;
                    }
                } else {
                    $previous = json_decode($action->previous_ids, true, 512, JSON_THROW_ON_ERROR);
                    $matches = array_filter($payments, function ($payment) use ($action, $previous, $kind) {
                        $id = (string) $payment['id'];
                        if (in_array($id, $previous, true) || ($action->remote_id && $id !== $action->remote_id)) {
                            return false;
                        }
                        $money = app(BexioBalanceService::class);

                        return $money->cents($payment[$kind === 'invoice' ? 'value' : 'amount'] ?? null) === (int) $action->amount_cents
                            && ($payment[$kind === 'invoice' ? 'date' : 'execution_date'] ?? '') === $action->payment_date
                            && (int) ($payment[$kind === 'invoice' ? 'bank_account_id' : 'sender_bank_account_id'] ?? 0) === (int) $action->bank_id
                            && ($kind === 'invoice' ? empty($payment['kb_credit_voucher_id'])
                                : (($payment['payment_type'] ?? '') === 'MANUAL' && in_array($payment['status'] ?? '', ['TRANSFERRED', 'PAID'], true)
                                    && empty($payment['banking_payment_id']) && ($payment['note'] ?? '') === 'clubora-sandbox:'.$action->reference));
                    });
                    $cash = $kind === 'invoice' ? app(BexioInvoiceCashService::class)->fromPayments($payments, $row->remote_id, $balance) : [];
                    if (count($matches) === 1 && $balance['paid_cents'] >= (int) $action->amount_cents
                        && ($action->operation !== 'payment_overpaid' || $cash['cash_excess_cents'] >= (int) $action->amount_cents)
                        && (! in_array($action->operation, ['payment_full', 'bill_pay'], true) || $balance['remaining_cents'] === 0)) {
                        $found = (string) array_values($matches)[0]['id'];
                    }
                }
                $this->table()->where('id', $action->id)->update(['state' => $found ? 'accepted' : 'uncertain', 'remote_id' => $found ?? $action->remote_id, 'checked_at' => now()->toIso8601String()]);
            }
        });
        $service = app(BexioReconciliationService::class);
        $checkId = $service->preview($actor, $tenant, $kind, $objectId);
        $check = DB::connection('clubunio')->table('clubora.bexio_checks')->find($checkId);
        abort_unless(in_array($check->state, ['ready', 'unchanged'], true), 409, 'Finanzabgleich noch offen.');
        if ($check->state === 'ready') {
            $service->apply($actor, $tenant, $checkId);
        }
    }
}
