<?php

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
if (in_array('--owner', $argv, true)) {
    foreach (\App\ClubUnio\Models\Account::where('display_name', 'ilike', '%Peixoto%')->get(['id', 'display_name', 'username', 'active']) as $actor) {
        echo json_encode($actor->toArray(), JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
foreach (DB::connection('clubunio')->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->selectRaw('role, count(*) as total')->groupBy('role')->get() as $group) {
    echo 'Role '.$group->role.': '.$group->total.PHP_EOL;
}
foreach (Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->orderBy('key')->get() as $row) {
    echo $row->key.' enabled='.(int) $row->enabled.' filled='.(int) (trim(Crypt::decryptString($row->encrypted_value)) !== '').PHP_EOL;
}
$service = app(\App\ClubUnio\Services\BexioConnectionService::class);
$connection = $service->connection($tenant);
echo 'Independent connection: '.(empty($connection['refresh_token']) ? 'missing' : 'present').PHP_EOL;
if (in_array('--refresh', $argv, true) && ! empty($connection['refresh_token'])) {
    try {
        $service->accessToken($tenant, $connection['access_token']);
        echo 'Independent token refresh: successful'.PHP_EOL;
    } catch (\Throwable $error) {
        echo 'Independent token refresh failed; status '.(int) $error->getCode().PHP_EOL;
        exit(1);
    }
}
if (in_array('--pdf', $argv, true) && ! empty($connection['refresh_token'])) {
    $actor = \App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->where('active', true)->firstOrFail();
    $invoice = \App\ClubUnio\Models\Invoice::where('tenant_id', $tenant->id)->where('active', true)
        ->whereNotNull('bexio_invoice_id')->where('bexio_invoice_id', '<>', '')->orderBy('id', 'desc')->firstOrFail();
    $response = app(\App\ClubUnio\Services\InvoicePdfService::class)->open($actor, $tenant, $invoice->id, true);
    echo 'PDF invoice '.$invoice->id.': HTTP '.$response->getStatusCode().', bytes '.strlen($response->getContent()).PHP_EOL;
    if ($response->getStatusCode() !== 200 || ! str_starts_with($response->getContent(), '%PDF-')) {
        exit(1);
    }
}
