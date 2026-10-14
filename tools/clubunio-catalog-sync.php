<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioCatalogFixtureService;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409, 'Wrong database');
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
if (in_array('--test-article', $argv, true)) {
    echo 'Sandbox test article: '.app(BexioCatalogFixtureService::class)->create($actor, $tenant).PHP_EOL;
}
if (in_array('--test-invoice', $argv, true)) {
    $id = app(BexioCatalogFixtureService::class)->prepareInvoice($actor, $tenant);
    $query = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $id);
    $dispatch = (clone $query)->value('id');
    $service = app(BexioSandboxDispatchService::class);
    if (! $dispatch) {
        $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $id);
        $dispatch = $service->prepareTrial($actor, $tenant, $trial);
    }
    if (in_array('--send-invoice', $argv, true)) {
        $service->send($actor, $tenant, $dispatch);
    }
    $row = (clone $query)->first();
    echo json_encode(['invoice_id' => $id, 'dispatch_id' => $dispatch, 'state' => $row->state, 'remote_id' => $row->remote_id], JSON_THROW_ON_ERROR).PHP_EOL;
}
$count = app(BexioCatalogService::class)->sync($actor, $tenant);
echo 'Sandbox catalog imported: '.$count.PHP_EOL;
