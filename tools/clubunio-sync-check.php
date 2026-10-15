<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\SyncWorkService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$service = app(SyncWorkService::class);
$service->authorize($actor, $tenant);
if (($argv[1] ?? '') === '--collect') {
    echo 'Imported local work items: '.$service->importOutstanding($actor, $tenant).PHP_EOL;
}
echo json_encode($service->table()->where('tenant_id', $tenant->id)->selectRaw('operation, state, count(*) as total')
    ->groupBy('operation', 'state')->orderBy('operation')->get()->all(), JSON_THROW_ON_ERROR).PHP_EOL;
