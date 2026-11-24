<?php

// CLI-only DEV acceptance. Never creates provider documents or applies statuses.
use App\ClubUnio\Models\{Account, CustomerCredit, Expense, Invoice, Tenant};
use App\ClubUnio\Services\{BexioBalanceService, BexioConnectionService, BexioSandboxClient, BexioSandboxDispatchService, CreditNoteService, InvoicePdfService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$phase = 'database';
try {
    $db = DB::connection('clubunio');
    if ($db->getDatabaseName() !== 'clubunio_dev') { throw new LogicException('DEV required'); }
    $tenant = Tenant::where('slug', 'sv-sissach')->sole();
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
    $schema = $tenant->getTable();
    $schema = substr($schema, 0, strrpos($schema, '.'));
    if (! in_array($schema, ['clubunio', 'clubora'], true)) { throw new LogicException('Unknown schema'); }
    $table = fn (string $name) => $db->table($schema.'.'.$name)->where('tenant_id', $tenant->id);
    $connection = app(BexioConnectionService::class);
    echo json_encode(['database' => $db->getDatabaseName(), 'tenant_id' => $tenant->id,
        'connected' => ! empty($connection->connection($tenant)['refresh_token']),
        'accepted_dispatches' => $table('bexio_sandbox_dispatches')->where('state', 'accepted')->count(),
        'fixture_binding' => $table('bexio_sandbox_fixtures')->exists()], JSON_THROW_ON_ERROR).PHP_EOL;
    $phase = 'oauth';
    $token = $connection->accessToken($tenant);
    $phase = 'sandbox_identity';
    $client = app(BexioSandboxClient::class);
    $client->verify($token);
    echo "PASS sandbox identity: SVS Api Test / profile 1\n";
    $rows = $table('bexio_sandbox_dispatches')->where('state', 'accepted')->orderBy('id')
        ->get(['id', 'kind', 'invoice_id', 'expense_id', 'remote_id', 'expected_cents']);
    $snapshot = function () use ($table): string {
        $result = [];
        foreach (['invoices', 'invoice_lines', 'expenses', 'expense_lines', 'credit_notes', 'credit_note_lines',
            'customer_credits', 'customer_credit_allocations', 'refunds', 'bexio_sandbox_dispatches'] as $name) {
            $result[$name] = $table($name)->orderBy('id')->get()->all();
        }
        return hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
    };
    $phase = 'business_snapshot';
    $before = $snapshot();
    $failures = [];
    $cases = ['6' => [4025, 0, 0, 4025, null], '9' => [1005, 1005, 0, 0, 'GS-00004'],
        '13' => [2000, 1000, 1000, 0, 'GS-00005'], '14' => [3000, 0, 3000, 0, null],
        '15' => [2000, 1000, 1000, 0, 'GS-00007'], '16' => [2500, 2000, 500, 0, 'GS-00007']];
    foreach ($cases as $remoteId => [$total, $credit, $remaining, $cash, $reference]) {
        $phase = 'invoice_'.$remoteId;
        try {
            $row = $rows->where('kind', 'invoice')->where('remote_id', (string) $remoteId)->sole();
            $invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail($row->invoice_id);
            if ((string) $invoice->bexio_invoice_id !== (string) $remoteId) { throw new LogicException('Identity changed'); }
            $remote = app(CreditNoteService::class)->remote($actor, $tenant, $invoice->id);
            foreach (['total_cents' => $total, 'credit_cents' => $credit, 'remaining_cents' => $remaining,
                'cash_received_cents' => $cash] as $key => $expected) {
                if ((int) $remote['balance'][$key] !== $expected) { throw new LogicException('Unexpected balance'); }
            }
            $allocations = collect($remote['credit_allocations']);
            if ($reference && ($allocations->where('reference', $reference)->count() !== 1
                || (int) $allocations->where('reference', $reference)->sum('amount_cents') !== $credit)) {
                throw new LogicException('Allocation changed');
            }
            $local = $invoice->bexioBalance;
            if (! $local || (int) $local->total_cents !== $total || (int) $local->credit_cents !== $credit
                || (int) $local->remaining_cents !== $remaining) { throw new LogicException('Local balance mismatch'); }
            echo json_encode(['invoice' => $remoteId, 'local_id' => $invoice->id, 'balance' => $remote['balance'],
                'allocations' => $remote['credit_allocations'], 'local_matches' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (Throwable $error) {
            $failures[] = ['phase' => $phase, 'exception' => get_class($error), 'code' => (int) $error->getCode()];
        }
    }
    foreach (['GS-00005' => [1000, 1, '0.748933'], 'GS-00007' => [3000, 2, '2.247919']] as $reference => [$amount, $count, $tax]) {
        $phase = 'customer_credit_'.$reference;
        try {
            $credit = CustomerCredit::where('tenant_id', $tenant->id)->where('external_reference', $reference)->sole();
            if ((int) $credit->amount_cents !== $amount || $credit->remaining_cents !== 0
                || $credit->allocations()->count() !== $count || $credit->creditNote->status->code !== 'confirmed'
                || (string) $credit->creditNote->included_tax !== $tax) { throw new LogicException('Customer credit mismatch'); }
            echo json_encode(['customer_credit' => $reference, 'amount_cents' => $amount, 'remaining_cents' => 0,
                'allocations' => $count, 'original_tax' => $tax, 'confirmed' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (Throwable $error) {
            $failures[] = ['phase' => $phase, 'exception' => get_class($error), 'code' => (int) $error->getCode()];
        }
    }
    $expenseCases = $rows->where('kind', 'expense')->whereIn('remote_id', [
        '7b605483-9abb-4e9f-a5d8-86b7348886aa', 'ebb98b7c-ac67-4ce0-bda4-b0e598c8a708',
    ]);
    if ($expenseCases->count() !== 2) { throw new LogicException('Historical expense fixtures missing'); }
    foreach ($expenseCases as $row) {
        $phase = 'expense_'.$row->expense_id;
        try {
            $remote = app(BexioSandboxDispatchService::class)->readStatus($actor, $tenant, 'expense', $row->expense_id);
            $expected = match ((string) $row->remote_id) {
                '7b605483-9abb-4e9f-a5d8-86b7348886aa' => [1500, 'DRAFT'],
                'ebb98b7c-ac67-4ce0-bda4-b0e598c8a708' => [2000, 'PAID'],
                default => throw new LogicException('Unreviewed expense fixture'),
            };
            $balance = app(BexioBalanceService::class)->normalize('expense', $remote, $expected[0]);
            if ($remote['status'] !== $expected[1]) { throw new LogicException('Expense status changed'); }
            $expense = Expense::where('tenant_id', $tenant->id)->whereNull('legacy_id')->findOrFail($row->expense_id);
            if ($expected[1] === 'PAID' && ($expense->status->code !== 'B'
                || (int) $expense->bexioBalance?->remaining_cents !== 0)) { throw new LogicException('Paid expense local mismatch'); }
            echo json_encode(['expense' => $row->expense_id, 'status' => $remote['status'],
                'balance' => $balance, 'local_status' => $expense->status->code], JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (Throwable $error) {
            $failures[] = ['phase' => $phase, 'exception' => get_class($error), 'code' => (int) $error->getCode()];
        }
    }
    foreach (['6', '13'] as $remoteId) {
        $phase = 'pdf_'.$remoteId;
        try {
            $row = $rows->where('kind', 'invoice')->where('remote_id', $remoteId)->sole();
            $response = app(InvoicePdfService::class)->open($actor, $tenant, $row->invoice_id, true);
            $bytes = $response->getContent();
            if ($response->getStatusCode() !== 200 || ! str_starts_with($bytes, '%PDF-')) { throw new LogicException('PDF unavailable'); }
            echo json_encode(['pdf' => $remoteId, 'status' => 200, 'bytes' => strlen($bytes)], JSON_THROW_ON_ERROR).PHP_EOL;
        } catch (Throwable $error) {
            $failures[] = ['phase' => $phase, 'exception' => get_class($error), 'code' => (int) $error->getCode()];
        }
    }
    if ($before !== $snapshot()) { $failures[] = ['phase' => 'business_snapshot_changed']; }
    echo json_encode(['business_fingerprints_unchanged' => $before === $snapshot(), 'failures' => $failures], JSON_THROW_ON_ERROR).PHP_EOL;
    exit($failures ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['failed_phase' => $phase, 'exception' => get_class($error),
        'code' => (int) $error->getCode()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
