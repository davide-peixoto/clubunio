<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(\Illuminate\Support\Facades\DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409, 'Wrong database');
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
$token = app(BexioConnectionService::class)->accessToken($tenant);
$client = app(BexioSandboxClient::class);
$client->verify($token);
echo json_encode($client->get($token, '/3.0/taxes'), JSON_THROW_ON_ERROR).PHP_EOL;
echo json_encode($client->get($token, '/2.0/article', ['limit' => 500]), JSON_THROW_ON_ERROR).PHP_EOL;
