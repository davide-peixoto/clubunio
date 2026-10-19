<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioBillingRecipientService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use App\ClubUnio\Services\SyncWorkService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// CLI-only, fixed synthetic fixture. No payment, email or credit allocation.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$base = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '--read';
abort_unless(count($argv) <= 2 && in_array($mode, ['--read', '--prepare', '--send'], true), 422);
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora'
    && ! config('clubunio.outbound_enabled'), 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$service = app(BexioSandboxDispatchService::class);
$service->authorize($actor, $tenant);
$template = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail(8440);
abort_unless((string) $template->bexio_invoice_id === '6', 409);
$creditService = app(CreditNoteService::class);
$templateRemote = $creditService->remote($actor, $tenant, $template->id);
abort_unless((string) $templateRemote['contact_id'] === '3'
    && app(BexioBillingRecipientService::class)->resolve($template->member) === 3, 409);
$workflow = app(InvoiceWorkflowService::class);
$prefix = 'SANDBOX TWO-TARGET-20261019';
$invoices = [];
foreach (['SOURCE' => '30.00', 'TARGET-A' => '20.00', 'TARGET-B' => '25.00'] as $role => $amount) {
    $marker = "$prefix $role - keine echte Forderung oder Zahlung";
    $query = fn () => Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker);
    abort_if($query()->count() > 1, 409, 'Duplicate fixture; inspect manually');
    $invoice = $query()->first();
    if ($mode === '--prepare' && ! $invoice) {
        $invoice = DB::connection('clubunio')->transaction(function () use ($query, $tenant, $actor, $template, $workflow, $marker, $role, $amount) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($existing = $query()->first()) {
                return $existing;
            }
            $tax = $template->activeLines()->whereNotNull('bexio_catalog_tax_id')->orderBy('id')->value('bexio_catalog_tax_id');
            abort_unless($tax && $template->invoice_category_id, 409);
            $invoice = $workflow->create($actor, $tenant, ['member_id' => $template->member_id,
                'invoice_category_id' => $template->invoice_category_id, 'tax_mode' => 'inclusive', 'notes' => $marker]);
            $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'line',
                ['description' => 'Synthetischer Zwei-Zielrechnungen-Test '.$role, 'gross_amount' => $amount,
                    'discount' => '0.00', 'bexio_catalog_tax_id' => $tax]);

            return $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'release');
        });
    }
    if (! $invoice) {
        abort_if($mode === '--send', 409, 'Prepare fixtures first');
        echo "$role: not prepared\n";
        continue;
    }
    abort_unless($invoice->active && $invoice->member_id === $template->member_id, 409);
    if ($mode === '--send') {
        $dispatches = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches');
        $dispatch = (clone $dispatches)->where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->first();
        if (! $dispatch) {
            $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $invoice->id);
            $dispatch = (clone $dispatches)->find($service->prepareTrial($actor, $tenant, $trial));
        }
        abort_unless((string) json_decode($dispatch->payload, true, 512, JSON_THROW_ON_ERROR)['contact_id'] === '3'
            && (int) $dispatch->expected_cents === (int) round((float) $amount * 100), 409);
        // The existing sync journal reconciles uncertain dispatches instead of blind retries.
        if ($dispatch->state !== 'accepted') {
            app(SyncWorkService::class)->run($actor, $tenant, 'dispatch_result', $dispatch->id);
        }
        abort_unless((clone $dispatches)->find($dispatch->id)->state === 'accepted', 409, 'Unconfirmed dispatch; stop');
        app(BexioInvoiceActionService::class)->execute($actor, $tenant, $invoice->id, 'issue');
        $invoice->refresh();
    }
    $invoices[$role] = $invoice;
    $remote = $invoice->bexio_invoice_id ? $service->readStatus($actor, $tenant, 'invoice', $invoice->id) : [];
    echo json_encode(['role' => $role, 'local_id' => $invoice->id, 'bexio_id' => $invoice->bexio_invoice_id,
        'document_nr' => $remote['document_nr'] ?? null, 'expected_amount' => $amount,
        'remote_total' => $remote['total'] ?? null, 'status' => $remote['kb_item_status_id'] ?? null,
        'cash_received' => $remote['total_received_payments'] ?? null,
        'credit_applied' => $remote['total_credit_vouchers'] ?? null,
        'contact_id' => $remote['contact_id'] ?? null], JSON_THROW_ON_ERROR).PHP_EOL;
    if ($mode === '--read' && $invoice->bexio_invoice_id) {
        $evidence = $creditService->remote($actor, $tenant, $invoice->id);
        echo json_encode(['role' => $role, 'provider_balance' => $evidence['balance'],
            'provider_allocations' => $evidence['credit_allocations'],
            'local_balance' => $invoice->bexioBalance?->only(['total_cents', 'credit_cents', 'remaining_cents'])], JSON_THROW_ON_ERROR).PHP_EOL;
    }
}

$requestId = '07c9b792-6ee1-4f5e-a6b3-f3f633e25019';
$note = CreditNote::where('tenant_id', $tenant->id)->where('request_id', $requestId)->first();
if ($mode === '--send') {
    $source = $invoices['SOURCE']->fresh();
    if (! $note) {
        $note = $creditService->create($actor, $tenant, $source->id, ['request_id' => $requestId,
            'revision' => $source->revision, 'reason' => $prefix.' Guthaben auf zwei Zielrechnungen',
            'lines' => $creditService->availableLines($actor, $tenant, $source->id)]);
    }
    abort_unless($note->invoice_id === $source->id && $note->amount_cents === 3000, 409);
    if ($note->status->code === 'draft') {
        $note = $creditService->change($actor, $tenant, $note->id, $note->revision, 'release');
    }
}
if ($note) {
    echo json_encode(['local_credit_note_id' => $note->id, 'amount_cents' => $note->amount_cents,
        'included_tax' => $note->included_tax, 'status' => $note->status->code,
        'bexio_credit_creation' => 'manual_only'], JSON_THROW_ON_ERROR).PHP_EOL;
    $credit = \App\ClubUnio\Models\CustomerCredit::where('tenant_id', $tenant->id)->where('credit_note_id', $note->id)->first();
    echo json_encode(['customer_credit' => $credit ? ['id' => $credit->id,
        'reference' => $credit->external_reference, 'amount_cents' => $credit->amount_cents,
        'remaining_cents' => $credit->remaining_cents,
        'allocations' => $credit->allocations()->get(['invoice_id', 'amount_cents'])->toArray()] : null], JSON_THROW_ON_ERROR).PHP_EOL;
}
