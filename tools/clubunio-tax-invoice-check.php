<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\BexioCatalogEntry;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\InvoicePdfService;
use App\ClubUnio\Services\InvoiceTaxService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$dispatch = app(BexioSandboxDispatchService::class);
$dispatch->authorize($actor, $tenant);
$operation = $argv[1] ?? 'status';
$single = in_array('--single', $argv, true);
$up = in_array('--up', $argv, true);
$exempt = in_array('--exempt', $argv, true);
$marker = 'SANDBOX Steuertest v1'.($exempt ? ' steuerbefreit' : ($up ? ' aufrunden' : ($single ? ' einzeln' : ''))).' - keine echte Forderung, keine Zahlung, kein Versand';
if ($operation === 'prepare') {
    app(BexioCatalogService::class)->sync($actor, $tenant);
    $db->transaction(function () use ($actor, $tenant, $db, $marker, $single, $up, $exempt) {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $binding = $db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        abort_unless($binding && hash_equals($binding->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
        if (Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker)->exists()) {
            return;
        }
        $category = InvoiceCategory::where('tenant_id', $tenant->id)->where('code', $exempt ? 'fine' : 'merchandise')->where('active', true)->firstOrFail();
        $workflow = app(InvoiceWorkflowService::class);
        $invoice = $workflow->create($actor, $tenant, ['member_id' => $binding->member_id, 'invoice_category_id' => $category->id,
            'tax_mode' => $exempt ? 'exempt' : 'inclusive', 'notes' => $marker]);
        foreach ($up || $exempt ? [['UN81', '12.03', '2.00']] : ($single ? [['UN81', '12.01', '2.00']] : [['UN81', '12.00', '2.00'], ['UR26', '10.26', '0.00'], ['UEX', '20.01', '0.00']]) as [$code, $gross, $discount]) {
            $tax = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('connection_hash', $binding->connection_hash)
                ->where('kind', 'tax')->where('code', $code)->firstOrFail();
            $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'line', [
                'description' => 'Synthetische Steuer-Testposition '.$code, 'gross_amount' => $gross,
                'discount' => $discount, 'bexio_catalog_tax_id' => $exempt ? null : $tax->id]);
        }
        $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'release');
    });
}
$invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker)->firstOrFail();
$query = $db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id);
$row = (clone $query)->first();
if (! $row && $operation === 'prepare') {
    $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $invoice->id);
    $dispatch->prepareTrial($actor, $tenant, $trial);
    $row = (clone $query)->first();
}
abort_unless($row, 409);
match ($operation) {
    'prepare', 'status' => null,
    'send' => $dispatch->send($actor, $tenant, $row->id),
    'issue', 'cancel' => app(BexioInvoiceActionService::class)->execute($actor, $tenant, $invoice->id, $operation),
    'pay' => app(BexioFinancialActionService::class)->execute($actor, $tenant, 'invoice', $invoice->id, 'payment_full'),
    'reconcile' => $dispatch->reconcile($actor, $tenant, $row->id),
    'pdf' => (function () use ($actor, $tenant, $invoice) {
        $response = app(InvoicePdfService::class)->open($actor, $tenant, $invoice->id, true);
        abort_unless($response->getStatusCode() === 200, 503);
        file_put_contents(storage_path('app/clubunio-tax-invoice-test.pdf'), $response->getContent());
        echo 'PDF bytes: '.strlen($response->getContent()).PHP_EOL;
    })(),
    default => throw new LogicException('Unsupported operation'),
};
$invoice->refresh();
$row = (clone $query)->first();
echo json_encode(['invoice_id' => $invoice->id, 'dispatch_id' => $row->id, 'state' => $row->state,
    'remote_id' => $row->remote_id, 'status' => $invoice->status?->code, 'summary' => app(InvoiceTaxService::class)->summary($invoice, true)], JSON_THROW_ON_ERROR).PHP_EOL;
if (in_array('--inspect-remote', $argv, true) && $row->remote_id) {
    abort_unless(hash_equals($row->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
    $token = app(BexioConnectionService::class)->accessToken($tenant);
    $client = app(BexioSandboxClient::class);
    $client->verify($token);
    $data = $client->get($token, '/2.0/kb_invoice/'.$row->remote_id);
    $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
    abort_unless(($data['api_reference'] ?? '') === $payload['api_reference'], 409);
    echo json_encode(array_intersect_key($data, array_flip(['id', 'mwst_type', 'mwst_is_net', 'total', 'total_net', 'total_taxes', 'total_rounding_difference', 'total_gross', 'kb_item_status_id'])), JSON_THROW_ON_ERROR).PHP_EOL;
    echo json_encode($data['positions'] ?? [], JSON_THROW_ON_ERROR).PHP_EOL;
}
