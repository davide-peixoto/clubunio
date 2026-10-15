<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioInvoiceTransferService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
$token = app(BexioConnectionService::class)->accessToken($tenant);
$client = app(BexioSandboxClient::class);
$client->verify($token);
$marker = 'SANDBOX direct-invoice-transfer release64';
$invoice = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker)->first();
if (($argv[1] ?? 'read') === 'apply') {
    if (! $invoice) {
        $invoice = DB::connection('clubunio')->transaction(function () use ($tenant, $actor, $marker) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = Invoice::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', $marker)->first();
            if ($existing) {
                return $existing;
            }
            $member = new Member;
            $member->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Sandbox', 'last_name' => 'Transfer64'])->save();
            $category = InvoiceCategory::where('tenant_id', $tenant->id)->where('active', true)->firstOrFail();

            return app(InvoiceWorkflowService::class)->create($actor, $tenant, ['member_id' => $member->id,
                'invoice_category_id' => $category->id, 'tax_mode' => 'exempt', 'notes' => $marker]);
        });
    }
    $workflow = app(InvoiceWorkflowService::class);
    if ($invoice->workflowStatus?->code === 'draft') {
        if ($invoice->activeLines()->count() === 0) {
            $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'line', [
                'description' => 'SANDBOX Transfer64 Testposition ohne Versand', 'gross_amount' => '1.00', 'discount' => '0.00']);
        }
        $invoice = $workflow->change($actor, $tenant, $invoice->id, $invoice->revision, 'release');
    }
    app(BexioInvoiceTransferService::class)->transfer($actor, $tenant, $invoice->id, $invoice->revision);
    $invoice->refresh();
}
if (! $invoice?->bexio_invoice_id) {
    echo "No confirmed release64 test invoice.\n";
    exit;
}
app(BexioSandboxFixtureService::class)->guardPdf($tenant, $invoice, $token);
$remote = $client->get($token, '/2.0/kb_invoice/'.$invoice->bexio_invoice_id);
abort_unless((int) $remote['kb_item_status_id'] === 7 && (float) $remote['total'] === 1.0, 409);
echo json_encode(['invoice_id' => $invoice->id, 'member_id' => $invoice->member_id, 'remote_id' => $remote['id'],
    'number' => $remote['document_nr'], 'contact_id' => $remote['contact_id'], 'total' => $remote['total'],
    'remote_status' => $remote['kb_item_status_id'], 'member_original_contact' => $invoice->member->bexio_contact_id,
    'pdf_guard' => 'passed', 'mail_sent' => false, 'payment_sent' => false], JSON_THROW_ON_ERROR).PHP_EOL;
