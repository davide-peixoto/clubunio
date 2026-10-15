<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\CreditNoteService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
$token = app(BexioConnectionService::class)->accessToken($tenant);
$client = app(BexioSandboxClient::class);
$client->verify($token);
$invoice = $client->get($token, '/2.0/kb_invoice/9');
$payments = $client->get($token, '/2.0/kb_invoice/9/payment', ['limit' => 500]);
$local = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('bexio_invoice_id', '9')->sole();
$service = app(CreditNoteService::class);
$remote = $service->remote($actor, $tenant, $local->id);
abort_unless($invoice['id'] === 9 && $invoice['document_nr'] === 'RE-00009' && $remote['remote_id'] === '9', 409);
abort_unless($remote['balance']['total_cents'] === 1005 && $remote['balance']['credit_cents'] === 1005
    && $remote['balance']['paid_cents'] === 0 && $remote['balance']['cash_received_cents'] === 0
    && $remote['credit_allocations'] === [['voucher_id' => '4', 'reference' => 'GS-00004', 'amount_cents' => 1005, 'payment_ids' => ['20']]], 409);
if (($argv[1] ?? 'read') === 'apply') {
    // Only local reconciliation. No provider write, mail, bank payment or GS-00002 reassignment.
    app(BexioFinancialActionService::class)->reconcile($actor, $tenant, 'invoice', $local->id);
    $request = 'fc7e30be-4ed0-4b8e-a9e7-8ef5700e2914';
    $note = CreditNote::where('tenant_id', $tenant->id)->where('request_id', $request)->first();
    if (! $note) {
        $local->refresh();
        $lines = $service->availableLines($actor, $tenant, $local->id);
        $note = $service->create($actor, $tenant, $local->id, ['request_id' => $request, 'revision' => $local->revision,
            'reason' => 'SANDBOX GS-00004 zu RE-00009 - Gutschriftsverrechnung ohne Geldzahlung', 'lines' => $lines]);
    }
    abort_unless((int) $note->invoice_id === (int) $local->id && (int) $note->amount_cents === 1005, 409);
    if ($note->status->code === 'draft') {
        $note = $service->change($actor, $tenant, $note->id, $note->revision, 'release');
    }
    if ($note->status->code === 'released') {
        $note = $service->change($actor, $tenant, $note->id, $note->revision, 'confirm', ['external_reference' => 'GS-00004', 'attestation' => true]);
    }
    abort_unless($note->status->code === 'confirmed' && $note->external_reference === 'GS-00004', 409);
    echo json_encode(['local_credit' => $note->id, 'state' => $note->status->code, 'reference' => $note->external_reference, 'amount_cents' => $note->amount_cents,
        'allocation' => $note->external_snapshot['allocation']], JSON_THROW_ON_ERROR).PHP_EOL;
}
echo json_encode(['invoice' => array_intersect_key($invoice, array_flip(['id', 'document_nr', 'contact_id', 'currency_id', 'total', 'total_received_payments', 'total_credit_vouchers', 'total_remaining_payments', 'kb_item_status_id'])),
    'payments' => array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'value', 'kb_invoice_id', 'kb_credit_voucher_id', 'kb_credit_voucher_text', 'is_client_account_redemption', 'is_cash_discount'])), $payments),
    'own_invoices' => $db->table('clubora.invoices')->where('tenant_id', $tenant->id)->where('bexio_invoice_id', '9')->get(['id', 'legacy_id', 'revision', 'status_id']),
    'own_credits' => $db->table('clubora.credit_notes')->where('tenant_id', $tenant->id)->whereIn('invoice_id', $db->table('clubora.invoices')->where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('bexio_invoice_id', '9')->select('id'))->get(['id', 'invoice_id', 'status_id', 'revision', 'amount_cents', 'external_reference']),
    'stored_balance' => $db->table('clubora.bexio_balances')->where('tenant_id', $tenant->id)->where('object_type', 'invoice')->where('object_id', $local->id)
        ->first(['remote_id', 'remote_status', 'total_cents', 'paid_cents', 'credit_cents', 'remaining_cents', 'cash_received_cents', 'cash_excess_cents']),
    'dispatches' => $db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('remote_id', '9')->get(['id', 'invoice_id', 'state']),
], JSON_THROW_ON_ERROR).PHP_EOL;
