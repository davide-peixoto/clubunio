<?php

// Fixed sandbox acceptance only. Provider reads; local reconciliation via business services.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$step = $argv[1] ?? '--read';
if (count($argv) > 2 || ! in_array($step, ['--read', '--first', '--second'], true)) {
    exit("Use --read, --first or --second\n");
}
$argv = [$argv[0], '--read'];
require __DIR__.'/clubunio-two-target-credit-prepare.php';
if ($step === '--read') {
    exit;
}
abort_unless($note && $note->id === 427 && $note->amount_cents === 3000
    && $note->invoice_id === 10451, 409);
$targets = ['TARGET-A' => [10452, '15', 1000, 1000], 'TARGET-B' => [10453, '16', 2000, 500]];
$observed = [];
foreach ($targets as $role => [$id, $remoteId, $amount, $remaining]) {
    abort_unless($invoices[$role]->id === $id && (string) $invoices[$role]->bexio_invoice_id === $remoteId, 409);
    $e = $creditService->remote($actor, $tenant, $id);
    $expected = $role === 'TARGET-B' && $step === '--first' ? 0 : $amount;
    abort_unless((string) $e['contact_id'] === '3' && $e['balance']['cash_received_cents'] === 0
        && $e['balance']['credit_cents'] === $expected, 409, 'Unexpected provider balance');
    if ($expected) {
        abort_unless(count($e['credit_allocations']) === 1
            && $e['credit_allocations'][0]['voucher_id'] === '7'
            && $e['credit_allocations'][0]['reference'] === 'GS-00007'
            && $e['credit_allocations'][0]['amount_cents'] === $expected
            && count($e['credit_allocations'][0]['payment_ids']) === 1
            && $e['balance']['remaining_cents'] === $remaining, 409, 'Unexpected allocation');
    }
    $observed[$role] = $e;
}
$credits = app(\App\ClubUnio\Services\CustomerCreditService::class);
$reconciliation = app(\App\ClubUnio\Services\BexioReconciliationService::class);
$credit = \App\ClubUnio\Models\CustomerCredit::where('tenant_id', $tenant->id)->where('credit_note_id', $note->id)->first();
if (! $credit) {
    abort_unless($step === '--first', 409, 'First-step acceptance missing');
    $credit = $credits->register($actor, $tenant, $note->id, ['revision' => $note->revision,
        'external_reference' => 'GS-00007', 'attestation' => true]);
}
abort_unless($credit->external_reference === 'GS-00007' && $credit->amount_cents === 3000, 409);
if ($step === '--second') {
    abort_unless($credit->allocations()->where('invoice_id', 10452)->where('amount_cents', 1000)->exists(), 409);
}
foreach ($targets as $role => [$id, $remoteId, $amount, $remaining]) {
    if ($step === '--first' && $role === 'TARGET-B') {
        continue;
    }
    $existing = $credit->allocations()->where('invoice_id', $id)->first();
    if ($existing) {
        abort_unless($existing->amount_cents === $amount
            && $existing->evidence['allocation'] === $observed[$role]['credit_allocations'][0], 409);
    } else {
        $credit = $credits->allocate($actor, $tenant, $credit->id, $credit->fresh()->revision, $id);
    }
    $check = $reconciliation->preview($actor, $tenant, 'invoice', $id);
    $state = \Illuminate\Support\Facades\DB::connection('clubunio')->table('clubora.bexio_checks')->where('id', $check)->value('state');
    if ($state === 'ready') {
        $reconciliation->apply($actor, $tenant, $check);
    } else {
        abort_unless(in_array($state, ['unchanged', 'applied'], true), 409, 'Reconciliation not ready');
    }
    $balance = $invoices[$role]->fresh()->bexioBalance;
    abort_unless($balance && $balance->credit_cents === $amount && $balance->remaining_cents === $remaining, 409);
    echo json_encode(['verified' => $role, 'local_id' => $id, 'credit_cents' => $amount,
        'remaining_cents' => $remaining, 'payment_ids' => $observed[$role]['credit_allocations'][0]['payment_ids']]).PHP_EOL;
}
echo json_encode(['customer_credit_id' => $credit->id, 'remaining_cents' => $credit->fresh()->remaining_cents,
    'note_status' => $note->fresh()->status->code]).PHP_EOL;
