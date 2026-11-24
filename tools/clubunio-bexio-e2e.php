<?php

use App\ClubUnio\Models\{Account, Expense, Invoice, MemberBankAccount, TeamSeason, Tenant};
use App\ClubUnio\Services\{BexioConnectionService, BexioDispatchTrialService, BexioFinancialActionService,
    BexioInvoiceActionService, BexioInvoiceTransferService, BexioSandboxClient, BexioSandboxDispatchService,
    DocumentUploadService, ExpenseDocumentService, ExpenseService, InvoicePdfService, InvoiceWorkflowService, SyncWorkService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Mail};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$operation = $argv[1] ?? 'inspect';
$allowed = ['inspect', 'prepare', 'transfer', 'issue', 'record-test-payment', 'cancel', 'expense-release', 'expense-transfer', 'expense-book', 'expense-manual-payment', 'sync', 'pdf', 'verify'];
$phase = 'guard';
try {
    abort_unless(in_array($operation, $allowed, true) && count($argv) <= 2, 422);
    $db = DB::connection('clubunio');
    abort_unless($db->getDatabaseName() === 'clubunio_dev' && !config('clubunio.outbound_enabled'), 409);
    Mail::fake();
    $tenant = Tenant::where('slug', 'sv-sissach')->sole();
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $dispatch = app(BexioSandboxDispatchService::class);
    $dispatch->authorize($actor, $tenant);
    $prefix = substr($tenant->getTable(), 0, strrpos($tenant->getTable(), '.'));
    abort_unless(in_array($prefix, ['clubora', 'clubunio'], true), 409);
    $table = fn(string $name) => $db->table($prefix.'.'.$name)->where('tenant_id', $tenant->id);
    $token = app(BexioConnectionService::class)->accessToken($tenant);
    app(BexioSandboxClient::class)->verify($token);
    $binding = $table('bexio_sandbox_fixtures')->sole();
    if ($operation === 'inspect') {
        $banks = app(BexioSandboxClient::class)->get($token, '/3.0/banking/accounts');
        echo json_encode(['sandbox_bank_types' => array_map(fn($b) => ['id' => $b['id'], 'type' => $b['type'] ?? null], $banks)], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    $marker = 'SANDBOX E2E 20261124 - no real payment or mail';
    $find = fn(string $role) => Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker.' '.$role)->first();
    $expense = fn() => Expense::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker)->first();
    $phase = $operation;
    if ($operation === 'prepare') {
        $db->transaction(function () use ($tenant, $actor, $binding, $marker, $find, $expense, $table) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $source = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('bexio_invoice_id', '6')->sole();
            $workflow = app(InvoiceWorkflowService::class);
            $tax = $source->activeLines()->whereNotNull('bexio_catalog_tax_id')->orderBy('id')->value('bexio_catalog_tax_id');
            foreach (['PAYMENT', 'CANCEL'] as $role) {
                if (!$find($role)) {
                    $invoice = $workflow->create($actor, $tenant, ['member_id' => $binding->member_id,
                        'invoice_category_id' => $source->invoice_category_id, 'tax_mode' => 'inclusive', 'notes' => $marker.' '.$role]);
                    $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'line',
                        ['description' => 'Synthetic E2E '.$role, 'gross_amount' => '14.35', 'discount' => '2.00', 'bexio_catalog_tax_id' => $tax]);
                    $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'release');
                }
            }
            if (!$expense()) {
                $bank = MemberBankAccount::where('tenant_id', $tenant->id)->where('member_id', $binding->supplier_id)->where('active', true)->first();
                if (!$bank) {
                    // Deliberately not an IBAN: this isolated fixture cannot become a bank instruction.
                    $bank = MemberBankAccount::forceCreate(['tenant_id' => $tenant->id, 'member_id' => $binding->supplier_id,
                        'iban_encrypted' => Crypt::encryptString('SANDBOX-NOT-A-BANK-ACCOUNT'), 'active' => true]);
                }
                $team = TeamSeason::where('tenant_id', $tenant->id)->where('active', true)->whereHas('team', fn($q) => $q->where('active', true))->orderByDesc('season_id')->firstOrFail();
                $round = $table('expense_rounds')->where('active', true)->orderBy('id')->value('id');
                $type = $table('expense_types')->where('active', true)->where('code', 'sandbox_web')->sole();
                $service = app(ExpenseService::class);
                $e = $service->save($actor, $tenant, null, ['member_id' => $binding->supplier_id, 'team_season_id' => $team->id,
                    'bank_account_id' => $bank->id, 'round_id' => $round, 'notes' => $marker]);
                $service->saveLine($actor, $tenant, $e->id, null, ['expense_revision' => $e->revision,
                    'expense_type_id' => $type->id, 'amount' => '12.35', 'notes' => 'Synthetic E2E receipt']);
            }
        });
        $e = $expense()->fresh();
        $line = $e->activeLines()->sole();
        if (!$line->storage_reference) {
            $pdf = new Dompdf\Dompdf;
            $pdf->loadHtml('<h1>Sandbox E2E receipt</h1><p>Synthetic test only. CHF 12.35. No bank payment, no personal data.</p>');
            $pdf->render();
            $path = tempnam(sys_get_temp_dir(), 'bexio-e2e-');
            try {
                file_put_contents($path, $pdf->output());
                app(ExpenseDocumentService::class)->add($actor, $tenant, $line->id, $line->revision, $e->revision,
                    new UploadedFile($path, 'sandbox-e2e.pdf', 'application/pdf', null, true));
            } finally { if (is_file($path)) unlink($path); }
        }
    }
    if (in_array($operation, ['transfer', 'issue', 'record-test-payment', 'cancel', 'pdf'], true)) {
        foreach (['PAYMENT', 'CANCEL'] as $role) {
            $i = $find($role);
            abort_unless($i && (int)$i->member_id === (int)$binding->member_id, 409);
            if ($operation === 'transfer') {
                $first = app(BexioInvoiceTransferService::class)->transfer($actor, $tenant, $i->id, $i->revision);
                $again = app(BexioInvoiceTransferService::class)->transfer($actor, $tenant, $i->id, $i->fresh()->revision);
                abort_unless($first->state === 'accepted' && $first->id === $again->id, 409);
            } elseif ($operation === 'issue') {
                app(BexioInvoiceActionService::class)->execute($actor, $tenant, $i->id, 'issue');
            } elseif ($operation === 'record-test-payment' && $role === 'PAYMENT') {
                $banks = app(BexioSandboxClient::class)->get($token, '/3.0/banking/accounts');
                $setting = $table('tenant_settings')->where('area', 'bexio')->where('key', 'BEXIO_BANK_ACCOUNT_ID')->sole();
                $bankId = (int)Crypt::decryptString($setting->encrypted_value);
                // Fixed historic test account; this API records accounting only, never a bank instruction.
                abort_unless($bankId === 1 && collect($banks)->contains(fn($b) => (int)$b['id'] === 1 && in_array($b['type'], ['cash', 'bank'], true)), 409);
                app(BexioFinancialActionService::class)->execute($actor, $tenant, 'invoice', $i->id, 'payment_full');
            } elseif ($operation === 'cancel' && $role === 'CANCEL') {
                app(BexioInvoiceActionService::class)->execute($actor, $tenant, $i->id, 'cancel');
            } elseif ($operation === 'pdf') {
                $r = app(InvoicePdfService::class)->open($actor, $tenant, $i->id, true);
                abort_unless($r->getStatusCode() === 200 && str_starts_with($r->getContent(), '%PDF-'), 502);
            }
        }
    }
    if (str_starts_with($operation, 'expense-')) {
        $e = $expense();
        abort_unless($e && (int)$e->member_id === (int)$binding->supplier_id, 409);
        if ($operation === 'expense-release') {
            foreach (['F', 'Z'] as $code) {
                $e->refresh();
                if ($e->status->code === $code || ($code === 'F' && $e->status->code === 'Z')) continue;
                $id = $table('expense_statuses')->where('code', $code)->where('active', true)->value('id');
                app(ExpenseService::class)->transition($actor, $tenant, $e->id,
                    ['revision' => $e->revision, 'status_id' => $id, 'reason' => 'Isolated sandbox E2E approval']);
            }
        } elseif ($operation === 'expense-transfer') {
            $existing = $table('bexio_sandbox_dispatches')->where('expense_id', $e->id)->first();
            $id = $existing?->id ?? $dispatch->prepareTrial($actor, $tenant,
                app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'expense', $e->id));
            app(SyncWorkService::class)->run($actor, $tenant, 'dispatch_result', $id);
        } else {
            $banks = app(BexioSandboxClient::class)->get($token, '/3.0/banking/accounts');
            $setting = $table('tenant_settings')->where('area', 'bexio')->where('key', 'BEXIO_BANK_ACCOUNT_ID')->sole();
            $bankId = (int)Crypt::decryptString($setting->encrypted_value);
            // BexioSandboxClient only permits MANUAL outgoing payment records.
            abort_unless($bankId === 1 && collect($banks)->contains(fn($b) => (int)$b['id'] === 1 && in_array($b['type'], ['cash', 'bank'], true)), 409);
            app(BexioFinancialActionService::class)->execute($actor, $tenant, 'expense', $e->id,
                $operation === 'expense-book' ? 'bill_book' : 'bill_pay');
        }
    }
    if ($operation === 'sync') {
        foreach (['PAYMENT', 'CANCEL'] as $role) {
            $i = $find($role);
            abort_unless($i, 409);
            app(SyncWorkService::class)->run($actor, $tenant, 'invoice_sync', $i->id);
        }
        $e = $expense();
        abort_unless($e, 409);
        app(SyncWorkService::class)->run($actor, $tenant, 'expense_sync', $e->id);
    }
    if ($operation === 'verify') {
        $payment = $find('PAYMENT'); $cancelled = $find('CANCEL'); $e = $expense();
        abort_unless($payment && $cancelled && $e, 409);
        $r = $dispatch->readStatus($actor, $tenant, 'invoice', $payment->id);
        abort_unless((string)$r['kb_item_status_id'] === '9'
            && app(App\ClubUnio\Services\BexioBalanceService::class)->cents($r['total_received_payments']) === 1235
            && app(App\ClubUnio\Services\BexioBalanceService::class)->cents($r['total_remaining_payments']) === 0, 409);
        $r = $dispatch->readStatus($actor, $tenant, 'invoice', $cancelled->id);
        abort_unless((string)$r['kb_item_status_id'] === '19' && $cancelled->workflowStatus->code === 'cancelled', 409);
        $r = $dispatch->readStatus($actor, $tenant, 'expense', $e->id);
        abort_unless($r['status'] === 'PAID' && (float)$r['pending_amount'] === 0.0 && $e->status->code === 'B', 409);
        $d = $dispatch->statusDocument($actor, $tenant, 'expense', $e->id);
        $action = $db->table($prefix.'.bexio_financial_actions')->where('dispatch_id', $d->id)->where('operation', 'bill_pay')->sole();
        abort_unless($action->state === 'accepted' && $action->remote_id, 409);
        $proof = app(BexioSandboxClient::class)->get($token, '/4.0/purchase/outgoing-payments/'.$action->remote_id);
        abort_unless($proof['payment_type'] === 'MANUAL' && empty($proof['banking_payment_id'])
            && $proof['bill_id'] === $d->remote_id
            && app(App\ClubUnio\Services\BexioBalanceService::class)->cents($proof['amount']) === 1235, 409);
        $events = $table('expense_events')->where('expense_id', $e->id)->where('event_key', 'expense.paid')->count();
        abort_unless($events === 1, 409);
        echo json_encode(['manual_payment_only' => true, 'banking_payment_created' => false, 'paid_events' => $events,
            'paid_invoice' => $payment->bexio_invoice_id, 'cancelled_invoice' => $cancelled->bexio_invoice_id,
            'amount_cents' => 1235], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    foreach (['PAYMENT', 'CANCEL'] as $role) {
        $i = $find($role);
        echo json_encode(['role' => $role, 'local_id' => $i?->id, 'remote_id' => $i?->bexio_invoice_id,
            'local_status' => $i?->status?->code, 'workflow' => $i?->workflowStatus?->code], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    $e = $expense();
    echo json_encode(['expense' => $e?->id, 'local_status' => $e?->status?->code,
        'has_receipt' => (bool)$e?->activeLines()->value('storage_reference'),
        'operation' => $operation, 'completed' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['failed_phase' => $phase, 'exception' => get_class($error),
        'code' => $error instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : (int)$error->getCode()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
