<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\CustomerCredit;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioBillingRecipientService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\CustomerCreditService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use App\ClubUnio\Services\SyncWorkService;
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

// Dedicated same-payer synthetic target, never an imported member's real invoice.
$targetNotes = 'SANDBOX GS-00005 Verrechnung v2 - keine echte Forderung oder Zahlung';
if (in_array('--cancel-rejected-target', $argv, true)) {
    $rejected = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', 'SANDBOX GS-00005 Verrechnung v1 - keine echte Forderung oder Zahlung')->whereKey(9773)->firstOrFail();
    abort_unless((string) $rejected->bexio_invoice_id === '12' && ! config('clubunio.outbound_enabled'), 409);
    app(BexioInvoiceActionService::class)->execute($actor, $tenant, $rejected->id, 'cancel');
    echo "Synthetic alternate-recipient invoice12 cancelled; no credit allocated.\n";
}
$target = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $targetNotes)->first();
if (in_array('--prepare-target', $argv, true) && ! $target) {
    abort_unless(! config('clubunio.outbound_enabled') && (string) $remote['contact_id'] === '3', 409);
    abort_unless(app(BexioBillingRecipientService::class)->resolve($invoice->member) === 3, 409, 'Fixture payer changed; no invoice created');
    $workflow = app(InvoiceWorkflowService::class);
    $target = DB::connection('clubunio')->transaction(function () use ($tenant, $actor, $invoice, $targetNotes, $workflow) {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $existing = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $targetNotes)->first();
        if ($existing) {
            return $existing;
        }
        $category = $invoice->invoice_category_id ?: DB::connection('clubunio')->table('clubora.invoice_categories')->where('tenant_id', $tenant->id)->where('active', true)->value('id');
        $target = $workflow->create($actor, $tenant, ['member_id' => $invoice->member_id, 'invoice_category_id' => $category, 'tax_mode' => 'inclusive', 'notes' => $targetNotes]);
        $tax = $invoice->activeLines()->whereNotNull('bexio_catalog_tax_id')->orderBy('id')->value('bexio_catalog_tax_id');
        abort_unless($tax, 409);
        $target = $workflow->change($actor, $tenant, $target->id, $target->revision, 'line', ['description' => 'Synthetischer Verrechnungstest GS-00005', 'gross_amount' => '20.00', 'discount' => '0.00', 'bexio_catalog_tax_id' => $tax]);

        return $workflow->change($actor, $tenant, $target->id, $target->revision, 'release');
    });
}
if (in_array('--send-target', $argv, true)) {
    abort_unless($target && ! config('clubunio.outbound_enabled'), 409);
    $service = app(BexioSandboxDispatchService::class);
    $dispatch = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $target->id)->first();
    if (! $dispatch) {
        $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $target->id);
        $dispatchId = $service->prepareTrial($actor, $tenant, $trial);
        $dispatch = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->find($dispatchId);
    }
    abort_unless((string) json_decode($dispatch->payload, true, 512, JSON_THROW_ON_ERROR)['contact_id'] === '3', 409, 'Wrong fixture recipient; no transmission');
    app(SyncWorkService::class)->run($actor, $tenant, 'dispatch_result', $dispatch->id);
    $dispatch = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->find($dispatch->id);
    abort_unless($dispatch->state === 'accepted', 409, 'Unconfirmed target dispatch; review synchronization');
    app(BexioInvoiceActionService::class)->execute($actor, $tenant, $target->id, 'issue');
}
if ($target) {
    $target->refresh();
    echo json_encode(['target_invoice_id' => $target->id, 'target_bexio_id' => $target->bexio_invoice_id], JSON_THROW_ON_ERROR).PHP_EOL;
    if (in_array('--verify-target', $argv, true)) {
        $credit = CustomerCredit::where('tenant_id', $tenant->id)->where('external_reference', 'GS-00005')->firstOrFail();
        if (! $credit->allocations()->where('invoice_id', $target->id)->exists()) {
            app(CustomerCreditService::class)->allocate($actor, $tenant, $credit->id, $credit->revision, $target->id);
        }
        $allocation = $credit->allocations()->where('invoice_id', $target->id)->firstOrFail();
        echo json_encode(['verified_allocation_cents' => $allocation->amount_cents, 'remaining_credit_cents' => $credit->fresh()->remaining_cents], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    if (in_array('--sync-target', $argv, true)) {
        $check = app(SyncWorkService::class)->run($actor, $tenant, 'invoice_sync', $target->id);
        $row = DB::connection('clubunio')->table('clubora.bexio_checks')->find($check);
        echo json_encode(['target_check_state' => $row->state, 'balance' => $target->fresh()->bexioBalance?->only(['total_cents', 'credit_cents', 'remaining_cents'])], JSON_THROW_ON_ERROR).PHP_EOL;
    }
}

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
$note->refresh();
echo json_encode(['invoice_id' => $invoice->id, 'credit_note_id' => $note->id, 'local_cents' => $note->amount_cents,
    'original_tax' => $note->included_tax, 'status' => $note->status->code, 'provider_credit_cents' => $remote['balance']['credit_cents'],
    'allocations' => $remote['credit_allocations'], 'payer_verified' => ! empty($remote['contact_id'])], JSON_THROW_ON_ERROR).PHP_EOL;
