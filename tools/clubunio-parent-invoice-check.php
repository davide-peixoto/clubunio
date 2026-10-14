<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioGeneralSandboxFixtureService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\InvoicePdfService;
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
if ($operation === 'prepare') {
    $id = app(BexioGeneralSandboxFixtureService::class)->prepareParent($actor, $tenant);
    if (! $db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $id)->exists()) {
        $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $id);
        $service->prepareTrial($actor, $tenant, $trial);
    }
}
$invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', BexioGeneralSandboxFixtureService::PARENT_NOTES)->firstOrFail();
$row = $db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->first();
abort_unless($row, 409);
if ($operation === 'send') {
    $service->send($actor, $tenant, $row->id);
} elseif (in_array($operation, ['issue', 'cancel'], true)) {
    app(BexioInvoiceActionService::class)->execute($actor, $tenant, $invoice->id, $operation);
} elseif ($operation === 'pdf') {
    $response = app(InvoicePdfService::class)->open($actor, $tenant, $invoice->id, true);
    abort_unless($response->getStatusCode() === 200, 503);
    $path = storage_path('app/clubunio-parent-invoice-test.pdf');
    file_put_contents($path, $response->getContent());
    echo json_encode(['pdf_path' => $path, 'bytes' => strlen($response->getContent())]).PHP_EOL;
} elseif ($operation === 'reconcile') {
    app(BexioInvoiceActionService::class)->reconcile($actor, $tenant, $invoice->id);
} elseif (! in_array($operation, ['prepare', 'status'], true)) {
    throw new LogicException('Unsupported test action');
}
$invoice->refresh();
$row = $db->table('clubora.bexio_sandbox_dispatches')->find($row->id);
echo json_encode(['invoice_id' => $invoice->id, 'dispatch_id' => $row->id, 'state' => $row->state, 'remote_id' => $row->remote_id, 'status' => $invoice->status?->code, 'workflow' => $invoice->workflowStatus?->code]).PHP_EOL;
