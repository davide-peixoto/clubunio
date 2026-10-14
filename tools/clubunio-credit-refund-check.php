<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Refund;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioBalanceService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioGeneralSandboxFixtureService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
$operation = $argv[1] ?? 'status';
abort_unless(in_array($operation, ['status', 'credit', 'overpay', 'refund', 'tax-credit', 'reconcile-cash', 'repair-fixture-times'], true), 422);
$service = app(CreditNoteService::class);
if ($operation === 'repair-fixture-times') {
    DB::connection('clubunio')->transaction(function () use ($tenant, $actor) {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        foreach (['credit_notes' => [17, 18], 'refunds' => [7]] as $table => $ids) {
            $query = DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('created_by', $actor->id)->whereIn('id', $ids)->where('reason', 'like', 'SANDBOX %');
            abort_unless((clone $query)->count() === count($ids), 409);
            $columns = $table === 'credit_notes' ? ['created_at', 'updated_at', 'released_at', 'confirmed_at'] : ['created_at', 'updated_at', 'confirmed_at'];
            foreach ((clone $query)->whereRaw('created_at>now()')->get() as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[$column] = DB::connection('clubunio')->raw("($column AT TIME ZONE 'America/Los_Angeles') AT TIME ZONE 'UTC'");
                }
                (clone $query)->where('id', $row->id)->update($values);
                DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $table, 'object_id' => $row->id,
                    'before_value' => json_encode(array_intersect_key((array) $row, array_flip($columns)), JSON_THROW_ON_ERROR),
                    'after_value' => json_encode(['action' => 'repair_synthetic_timezone', 'source_timezone' => 'America/Los_Angeles', 'interpretation' => 'UTC'], JSON_THROW_ON_ERROR)]);
            }
        }
        $balance = DB::connection('clubunio')->table('clubora.bexio_balances')->where('tenant_id', $tenant->id)->where('object_type', 'invoice')->where('object_id', 8519)->where('remote_id', '7')
            ->where('cash_received_cents', 1500)->where('cash_excess_cents', 500)->whereRaw('observed_at>now()')->first();
        if ($balance) {
            DB::connection('clubunio')->table('clubora.bexio_balances')->where('id', $balance->id)->update(['observed_at' => DB::connection('clubunio')->raw("(observed_at AT TIME ZONE 'America/Los_Angeles') AT TIME ZONE 'UTC'")]);
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'invoices', 'object_id' => 8519,
                'before_value' => json_encode(['observed_at' => $balance->observed_at], JSON_THROW_ON_ERROR),
                'after_value' => json_encode(['action' => 'repair_synthetic_balance_timezone'], JSON_THROW_ON_ERROR)]);
        }
    });
}
if ($operation === 'status') {
    echo json_encode(['db_timezone' => DB::connection('clubunio')->selectOne('SHOW timezone')->TimeZone,
        'app_utc' => now('UTC')->toIso8601String(), 'credit_times' => CreditNote::where('tenant_id', $tenant->id)->whereIn('id', [17, 18])->get(['id', 'created_at', 'released_at', 'confirmed_at'])], JSON_THROW_ON_ERROR).PHP_EOL;
    $row = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', 8519)->first();
    if ($row) {
        echo json_encode(DB::connection('clubunio')->table('clubora.bexio_financial_actions')->where('dispatch_id', $row->id)->get(['operation', 'state', 'amount_cents', 'http_status', 'remote_id']), JSON_THROW_ON_ERROR).PHP_EOL;
        $raw = app(BexioSandboxDispatchService::class)->readStatus($actor, $tenant, 'invoice', 8519);
        echo json_encode(array_intersect_key($raw, array_flip(['total', 'total_received_payments', 'total_credit_vouchers', 'total_remaining_payments', 'kb_item_status_id'])), JSON_THROW_ON_ERROR).PHP_EOL;
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $payments = app(BexioSandboxClient::class)->get($token, '/2.0/kb_invoice/7/payment');
        echo json_encode(array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'value', 'kb_invoice_id', 'kb_credit_voucher_id', 'is_client_account_redemption', 'is_cash_discount', 'bank_account_id', 'date'])), $payments), JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
if ($operation === 'reconcile-cash') {
    $invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('bexio_invoice_id', '7')
        ->where('notes', 'SANDBOX Steuertest v1 einzeln - keine echte Forderung, keine Zahlung, kein Versand')->firstOrFail();
    app(BexioFinancialActionService::class)->reconcile($actor, $tenant, 'invoice', $invoice->id);
    echo json_encode($service->remote($actor, $tenant, $invoice->id), JSON_THROW_ON_ERROR).PHP_EOL;
}
if ($operation === 'credit') {
    $invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', BexioGeneralSandboxFixtureService::PARENT_NOTES)->firstOrFail();
    $remote = $service->remote($actor, $tenant, $invoice->id);
    abort_unless($remote['remote_id'] === '4' && $remote['balance']['credit_cents'] === 3300 && $remote['balance']['paid_cents'] === 0, 409);
    $reason = 'SANDBOX Gutschriftrückfluss v1 - keine echte Forderung';
    $note = CreditNote::where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->where('reason', $reason)->first();
    if (! $note) {
        $lines = $invoice->activeLines()->get()->map(fn ($line) => ['invoice_line_id' => $line->id, 'amount' => app(InvoiceWorkflowService::class)->decimal(app(BexioBalanceService::class)->cents($line->net_amount))])->all();
        $note = $service->create($actor, $tenant, $invoice->id, ['revision' => $invoice->revision, 'request_id' => (string) Str::uuid(), 'reason' => $reason, 'lines' => $lines]);
    }
    if ($note->status->code === 'draft') {
        $note = $service->change($actor, $tenant, $note->id, $note->revision, 'release');
    }
    if ($note->status->code === 'released') {
        $note = $service->change($actor, $tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'SANDBOX-CANCEL-INVOICE-4', 'attestation' => true]);
    }
}
if (in_array($operation, ['overpay', 'refund'], true)) {
    $invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')
        ->where('notes', 'SANDBOX Steuertest v1 einzeln - keine echte Forderung, keine Zahlung, kein Versand')->firstOrFail();
    $row = app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, 'invoice', $invoice->id);
    abort_unless($row->remote_id === '7', 409);
    if ($operation === 'overpay') {
        app(BexioInvoiceActionService::class)->execute($actor, $tenant, $invoice->id, 'issue');
        app(BexioFinancialActionService::class)->execute($actor, $tenant, 'invoice', $invoice->id, 'payment_full');
        app(BexioFinancialActionService::class)->execute($actor, $tenant, 'invoice', $invoice->id, 'payment_overpaid');
    } else {
        $reason = 'SANDBOX Rückerstattungsnachweis v1 - simuliert, KEINE Bankzahlung';
        $refund = Refund::where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->where('reason', $reason)->first();
        if (! $refund) {
            $refund = $service->createRefund($actor, $tenant, $invoice->id, ['request_id' => (string) Str::uuid(), 'amount' => '5.00', 'reason' => $reason]);
        }
        if ($refund->status->code === 'draft') {
            $service->changeRefund($actor, $tenant, $refund->id, $refund->revision, 'confirm',
                ['proof_reference' => 'SANDBOX-SIMULATED-REFUND-INVOICE-7', 'paid_on' => now('Europe/Zurich')->toDateString(), 'attestation' => true]);
        }
    }
    echo json_encode($service->remote($actor, $tenant, $invoice->id), JSON_THROW_ON_ERROR).PHP_EOL;
    echo json_encode(DB::connection('clubunio')->table('clubora.bexio_financial_actions')->where('dispatch_id', $row->id)->get(['operation', 'state', 'amount_cents', 'http_status', 'remote_id']), JSON_THROW_ON_ERROR).PHP_EOL;
}
if ($operation === 'tax-credit') {
    $invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('bexio_invoice_id', '6')
        ->where('notes', 'SANDBOX Steuertest v1 - keine echte Forderung, keine Zahlung, kein Versand')->firstOrFail();
    $service->remote($actor, $tenant, $invoice->id);
    $reason = 'SANDBOX Teilgutschrift v1 - manuelle Bexio-Anlage noch offen';
    $note = CreditNote::where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->where('reason', $reason)->first();
    if (! $note) {
        $note = $service->create($actor, $tenant, $invoice->id, ['request_id' => (string) Str::uuid(), 'revision' => $invoice->revision,
            'reason' => $reason, 'lines' => [['invoice_line_id' => $invoice->activeLines()->orderBy('id')->firstOrFail()->id, 'amount' => '10.00']]]);
    }
    if ($note->status->code === 'draft') {
        $service->change($actor, $tenant, $note->id, $note->revision, 'release');
    }
}
echo json_encode(['credits' => CreditNote::where('tenant_id', $tenant->id)->where('reason', 'like', 'SANDBOX %')->get()->map(fn ($n) => [
    'id' => $n->id, 'invoice' => $n->invoice_id, 'amount' => $n->amount_cents, 'tax' => $n->included_tax, 'state' => $n->status->code, 'external' => $n->external_reference]),
    'refunds' => Refund::where('tenant_id', $tenant->id)->where('reason', 'like', 'SANDBOX %')->get()->map(fn ($r) => [
        'id' => $r->id, 'invoice' => $r->invoice_id, 'amount' => $r->amount_cents, 'state' => $r->status->code, 'proof' => $r->proof_reference])], JSON_THROW_ON_ERROR).PHP_EOL;
