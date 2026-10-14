<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class BexioInvoiceActionService
{
    private function table()
    {
        return DB::connection('clubunio')->table('clubora.bexio_invoice_actions');
    }

    private function remote(Account $actor, Tenant $tenant, int $invoiceId): array
    {
        $dispatch = app(BexioSandboxDispatchService::class);
        $row = $dispatch->statusDocument($actor, $tenant, 'invoice', $invoiceId);
        $data = $dispatch->readStatus($actor, $tenant, 'invoice', $invoiceId);
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $dispatch->invoiceContactLabel($tenant, (int) $payload['contact_id']);
        $total = (string) ($data['total'] ?? '');
        abort_unless(preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $total), 409);
        [$whole, $fraction] = array_pad(explode('.', $total), 2, '');
        $fraction = str_pad($fraction, 6, '0');
        abort_unless(substr($fraction, 2) === '0000', 409, 'Unerwartete Bruchteile von Rappen.');
        $cents = (int) $whole * 100 + (int) substr($fraction, 0, 2);
        abort_unless((string) ($data['id'] ?? '') === $row->remote_id
            && $cents === (int) ($row->expected_cents ?? 2500), 409, 'Testrechnung wurde extern geaendert.');
        app(InvoiceTaxService::class)->assertRemote(Invoice::where('tenant_id', $tenant->id)->findOrFail($invoiceId), $payload, $data);

        return [$row, $data];
    }

    public function execute(Account $actor, Tenant $tenant, int $invoiceId, string $operation): void
    {
        abort_unless(in_array($operation, ['issue', 'cancel'], true), 422);
        [$row, $remote] = $this->remote($actor, $tenant, $invoiceId);
        $existing = $this->table()->where('dispatch_id', $row->id)->where('operation', $operation)->first();
        if ($existing) {
            abort_unless($existing->state === 'accepted', 409, 'Aktion bereits gestartet. Nur Ergebnis abgleichen.');

            return;
        }
        $status = (int) ($remote['kb_item_status_id'] ?? 0);
        abort_unless($operation === 'issue' ? $status === 7 : in_array($status, [8, 31], true), 409, 'Rechnungsstatus erlaubt diese Aktion nicht.');
        // Never cancel paid/partially paid or credited documents, including inconsistent provider data.
        foreach (['total_received_payments', 'total_credit_vouchers'] as $field) {
            abort_unless(isset($remote[$field]) && is_numeric($remote[$field]) && (float) $remote[$field] === 0.0, 409, 'Zahlungen/Gutschriften zuerst fachlich klaeren.');
        }
        $id = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $invoiceId, $row, $operation, $status) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $current = app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, 'invoice', $invoiceId);
            abort_unless(hash_equals($row->connection_hash, $current->connection_hash), 409);
            abort_if($this->table()->where('dispatch_id', $row->id)->where(fn ($q) => $q->where('operation', $operation)->orWhere('state', '<>', 'accepted'))->exists(), 409);
            abort_if(DB::connection('clubunio')->table('clubora.bexio_financial_actions')->where('dispatch_id', $row->id)->whereIn('state', ['running', 'uncertain'])->exists(), 409, 'Offene Finanzaktion zuerst abgleichen.');

            return $this->table()->insertGetId(['dispatch_id' => $row->id, 'actor_id' => $actor->id, 'operation' => $operation,
                'state' => 'running', 'connection_hash' => $row->connection_hash, 'before_status' => $status]);
        });
        try {
            $token = app(BexioConnectionService::class)->accessToken($tenant);
            $result = app(BexioSandboxClient::class)->invoiceAction($token, $row->remote_id, $operation);
            $this->table()->where('id', $id)->update(['state' => 'uncertain', 'http_status' => $result['http_status']]);
            $this->reconcile($actor, $tenant, $invoiceId);
        } catch (\Throwable $error) {
            $this->table()->where('id', $id)->where('state', 'running')->update(['state' => 'uncertain']);
            throw $error;
        }
    }

    public function reconcile(Account $actor, Tenant $tenant, int $invoiceId): void
    {
        [$row, $remote] = $this->remote($actor, $tenant, $invoiceId);
        $status = (int) ($remote['kb_item_status_id'] ?? 0);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $row, $status, $invoiceId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, 'invoice', $invoiceId);
            foreach ($this->table()->where('dispatch_id', $row->id)->where('state', '<>', 'accepted')->lockForUpdate()->get() as $action) {
                abort_unless(hash_equals($action->connection_hash, $row->connection_hash), 409);
                $accepted = $status === ($action->operation === 'issue' ? 8 : 19);
                $this->table()->where('id', $action->id)->update(['state' => $accepted ? 'accepted' : 'uncertain', 'after_status' => $status, 'checked_at' => now()]);
            }
        });
        $service = app(BexioReconciliationService::class);
        $checkId = $service->preview($actor, $tenant, 'invoice', $invoiceId);
        $check = DB::connection('clubunio')->table('clubora.bexio_checks')->find($checkId);
        abort_unless(in_array($check->state, ['ready', 'unchanged'], true), 409, 'Lokaler Statusabgleich noch offen.');
        if ($check->state === 'ready') {
            $service->apply($actor, $tenant, $checkId);
        }
    }
}
