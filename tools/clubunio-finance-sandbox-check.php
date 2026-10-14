<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioBalanceService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$service = app(BexioSandboxDispatchService::class);
$service->authorize($actor, $tenant);
$operation = $argv[1] ?? 'status';
if ($operation !== 'status') {
    $kind = $argv[2] ?? '';
    $objectId = $argv[3] ?? '';
    abort_unless(in_array($kind, ['invoice', 'expense'], true) && ctype_digit($objectId) && (int) $objectId > 0, 422);
    if ($operation === 'proof' && $kind === 'expense') {
        $row = $service->statusDocument($actor, $tenant, $kind, (int) $objectId);
        $action = $db->table('clubora.bexio_financial_actions')->where('dispatch_id', $row->id)->where('operation', 'bill_pay')->first();
        abort_unless($action && $action->remote_id, 409);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $data = app(BexioSandboxClient::class)->get($token, '/4.0/purchase/outgoing-payments/'.$action->remote_id);
        echo json_encode(['same_id' => ($data['id'] ?? '') === $action->remote_id, 'same_bill' => ($data['bill_id'] ?? '') === $row->remote_id,
            'type' => $data['payment_type'] ?? null, 'status' => $data['status'] ?? null,
            'same_amount' => app(BexioBalanceService::class)->cents($data['amount'] ?? null) === (int) $action->amount_cents,
            'same_date' => ($data['execution_date'] ?? '') === $action->payment_date,
            'same_bank' => (int) ($data['sender_bank_account_id'] ?? 0) === (int) $action->bank_id,
            'same_note' => ($data['note'] ?? '') === 'clubora-sandbox:'.$action->reference,
            'has_banking_payment' => ! empty($data['banking_payment_id'])], JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    } elseif ($operation === 'issue' && $kind === 'invoice') {
        app(BexioInvoiceActionService::class)->execute($actor, $tenant, (int) $objectId, 'issue');
    } elseif ($operation === 'reconcile') {
        app(BexioFinancialActionService::class)->reconcile($actor, $tenant, $kind, (int) $objectId);
    } else {
        app(BexioFinancialActionService::class)->execute($actor, $tenant, $kind, (int) $objectId, $operation);
    }
}
foreach ($db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('state', 'accepted')->get() as $row) {
    $data = $service->readStatus($actor, $tenant, $row->kind, (int) $row->{$row->kind.'_id'});
    $local = ($row->kind === 'invoice' ? Invoice::class : Expense::class)::where('tenant_id', $tenant->id)->findOrFail($row->{$row->kind.'_id'});
    $balance = $db->table('clubora.bexio_balances')->where('tenant_id', $tenant->id)->where('object_type', $row->kind)->where('object_id', $local->id)->first(['paid_cents', 'credit_cents', 'remaining_cents']);
    echo json_encode(['kind' => $row->kind, 'id' => $row->{$row->kind.'_id'}, 'financial' => array_intersect_key($data, array_flip(['document_no', 'kb_item_status_id', 'status', 'currency_id', 'currency_code', 'total', 'total_received_payments', 'total_credit_vouchers', 'total_remaining_payments', 'pending_amount', 'amount_calc', 'manual_amount', 'bill_date']))], JSON_THROW_ON_ERROR).PHP_EOL;
    echo json_encode(['local_status' => $local->status?->code, 'balance' => $balance, 'paid_events' => $row->kind === 'expense' ? $db->table('clubora.expense_events')->where('expense_id', $local->id)->where('event_key', 'expense.paid')->count() : null], JSON_THROW_ON_ERROR).PHP_EOL;
    foreach ($db->table('clubora.bexio_financial_actions')->where('dispatch_id', $row->id)->get(['operation', 'state', 'amount_cents', 'http_status', 'remote_id']) as $action) {
        echo json_encode($action, JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
