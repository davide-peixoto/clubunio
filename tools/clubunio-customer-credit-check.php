<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\CustomerCredit;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\CustomerCreditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
// Default read-only; the explicit registration flag below only changes the local journal.
$note = CreditNote::where('tenant_id', $tenant->id)->whereKey(18)->where('reason', 'like', 'SANDBOX %')->firstOrFail();
$invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail($note->invoice_id);
abort_unless((string) $invoice->bexio_invoice_id === '6', 409);
$remote = app(CreditNoteService::class)->remote($actor, $tenant, $invoice->id);

// Explicit opt-in, based on the manually inspected GS-00005 in SVS Api Test.
// This only registers local customer credit; no Bexio write or payment.
if (($argv[1] ?? '') === '--register-gs-00005') {
    abort_unless($note->amount_cents === 1000 && (string) $note->included_tax === '0.748933'
        && (string) $remote['contact_id'] === '3' && $remote['balance']['remaining_cents'] === 0, 409);
    $credit = CustomerCredit::where('tenant_id', $tenant->id)->where('credit_note_id', $note->id)->first();
    if (! $credit) {
        $credit = app(CustomerCreditService::class)->register($actor, $tenant, $note->id,
            ['revision' => $note->revision, 'external_reference' => 'GS-00005', 'attestation' => true]);
    }
    abort_unless($credit->external_reference === 'GS-00005' && $credit->amount_cents === 1000, 409);
    echo json_encode(['customer_credit_id' => $credit->id, 'external_reference' => $credit->external_reference,
        'unallocated_cents' => $credit->remaining_cents, 'evidence' => 'manual_amount_and_tax_attestation_no_allocation'], JSON_THROW_ON_ERROR).PHP_EOL;
}
echo json_encode(['invoice_id' => $invoice->id, 'credit_note_id' => $note->id, 'local_cents' => $note->amount_cents,
    'original_tax' => $note->included_tax, 'status' => $note->status->code, 'provider_credit_cents' => $remote['balance']['credit_cents'],
    'allocations' => $remote['credit_allocations'], 'payer_verified' => ! empty($remote['contact_id'])], JSON_THROW_ON_ERROR).PHP_EOL;
