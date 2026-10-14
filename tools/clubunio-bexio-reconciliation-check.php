<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioReconciliationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->where('active', true)->firstOrFail();
$invoice = Invoice::where('tenant_id', $tenant->id)->where('active', true)->whereNotNull('bexio_invoice_id')
    ->whereRaw("bexio_invoice_id ~ '^[1-9][0-9]*$'")->orderByDesc('id')->firstOrFail();
$id = app(BexioReconciliationService::class)->preview($actor, $tenant, 'invoice', $invoice->id);
$check = DB::connection('clubunio')->table('clubora.bexio_checks')->where('id', $id)->first();
echo json_encode(['check' => $id, 'state' => $check->state, 'provider_status' => $check->provider_status, 'remote_status' => $check->remote_status, 'reference' => $check->reference]).PHP_EOL;
// Deliberately no apply: one live read and a technical audit entry only.
exit($check->state === 'failed' ? 1 : 0);
