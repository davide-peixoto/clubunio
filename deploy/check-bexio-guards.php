<?php

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BexioBalanceService, BexioConnectionService, BexioSandboxClient, BexioSandboxDispatchService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{DB, Http, Mail};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if (!in_array($db->getDatabaseName(), ['clubunio_dev', 'clubunio_stage', 'clubunio_prod'], true)) exit(1);
$count = 0;
$expect = function(bool $condition) use (&$count) { if (!$condition) throw new LogicException('Bexio guard check failed'); $count++; };
Http::preventStrayRequests(); Mail::fake();
$seen = []; $correct = false;
Http::fake(function($request) use (&$seen, &$correct) {
    $seen[] = $request;
    if (str_ends_with($request->url(), '/company_profile')) return Http::response([['id' => 1, 'name' => $correct ? 'SVS Api Test' : 'Not the sandbox']]);
    if (str_contains($request->url(), '/outgoing-payments')) return Http::response(['id' => 'cb71a19f-ffcd-43be-ae06-dafbc2d41503'], 201);
    return Http::response([], 502);
});
$db->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(8));
    $tenant = Tenant::forceCreate(['slug' => 'bexio-guard-'.$suffix, 'name' => 'Synthetic guard', 'accounting_provider' => 'bexio']);
    $other = Tenant::forceCreate(['slug' => 'bexio-foreign-'.$suffix, 'name' => 'Synthetic foreign', 'accounting_provider' => 'bexio']);
    $actor = Account::forceCreate(['username' => 'bexio-'.$suffix.'@example.invalid', 'display_name' => 'Synthetic',
        'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), 'active' => true, 'api_only' => false]);
    $prefix = substr($tenant->getTable(), 0, strrpos($tenant->getTable(), '.'));
    $db->table($prefix.'.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $dispatch = app(BexioSandboxDispatchService::class);
    $dispatch->authorize($actor, $tenant); $expect(true);
    try { $dispatch->authorize($actor, $other); throw new LogicException('Foreign access allowed'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $expect($e->getStatusCode() === 403); }
    try { app(BexioConnectionService::class)->accessToken($tenant); throw new LogicException('Missing keys accepted'); }
    catch (RuntimeException $e) { $expect($e->getCode() === 401); }
    $expect(count($seen) === 0);
    $method = new ReflectionMethod($dispatch, 'reference');
    foreach (['invoice' => 'api_reference', 'expense' => 'vendor_ref'] as $kind => $key) {
        $row = (object)['kind' => $kind, 'reference' => 'ad4bcfa1-85d4-4700-84f1-c98340d00199'];
        foreach (['clubora', 'clubunio'] as $name) {
            $value = $name.'-sandbox:'.$row->reference;
            $expect($method->invoke($dispatch, $row, [$key => $value]) === $value);
        }
    }
    try { $method->invoke($dispatch, $row, ['vendor_ref' => 'clubora-sandbox:foreign']); throw new LogicException('Foreign reference accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $expect($e->getStatusCode() === 409); }
    $client = app(BexioSandboxClient::class);
    try { $client->invoiceAction('synthetic', '123', 'issue'); throw new LogicException('Wrong company accepted'); }
    catch (RuntimeException $e) { $expect($e->getCode() === 409); }
    $expect(!collect($seen)->contains(fn($r) => $r->method() !== 'GET'));
    $correct = true;
    $start = count($seen);
    $result = $client->invoiceAction('synthetic', '123', 'issue');
    $expect($result['state'] === 'uncertain');
    $expect(count($seen) === $start + 2);
    $expect(collect($seen)->filter(fn($r) => $r->method() === 'POST')->count() === 1);
    $result = $client->financialAction('synthetic', 'expense', 'f5ad6d38-32cf-42f5-8170-e283ce504a33',
        'bill_pay', 1235, 1, '2026-11-24', '7e250216-9952-4ec6-b26b-66be3d04a8b2');
    $expect($result['state'] === 'accepted');
    $last = end($seen);
    $expect($last['payment_type'] === 'MANUAL');
    $expect(str_ends_with($last->url(), '/purchase/outgoing-payments'));
    $balance = app(BexioBalanceService::class)->normalize('expense',
        ['manual_amount' => false, 'amount_calc' => '12.35', 'pending_amount' => '0', 'status' => 'PAID', 'currency_code' => 'CHF'], 1235);
    $expect($balance['remaining_cents'] === 0 && $balance['paid_cents'] === 1235);
    echo json_encode(['database' => $db->getDatabaseName(), 'checks' => $count, 'provider' => 'fake', 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    while ($db->transactionLevel()) $db->rollBack();
}
