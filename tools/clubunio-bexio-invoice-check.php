<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioSandboxClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$token = app(BexioConnectionService::class)->accessToken($tenant);
$client = app(BexioSandboxClient::class);
$client->verify($token);
echo json_encode($db->table('clubora.invoice_statuses')->where('tenant_id', $tenant->id)->get(['code', 'name', 'position_code']), JSON_THROW_ON_ERROR).PHP_EOL;
echo json_encode($db->table('clubora.invoice_line_statuses')->where('tenant_id', $tenant->id)->get(['code', 'name', 'active']), JSON_THROW_ON_ERROR).PHP_EOL;
foreach ($client->get($token, '/3.0/banking/accounts') as $bank) {
    echo json_encode(['bank_id' => $bank['id'], 'mode' => $bank['invoice_mode'], 'has_iban' => ! empty($bank['iban_nr']), 'has_qr_iban' => ! empty($bank['qr_invoice_iban'])], JSON_THROW_ON_ERROR).PHP_EOL;
}
