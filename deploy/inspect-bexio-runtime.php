<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if (!in_array($db->getDatabaseName(), ['clubunio_dev', 'clubunio_stage', 'clubunio_prod'], true)) exit(1);
$paths = array_merge(glob($root.'/app/ClubUnio/Services/Bexio*.php'), array_map(fn($name) => $root.'/app/ClubUnio/Services/'.$name.'.php',
    ['SyncWorkService', 'InvoicePdfService', 'InvoiceWorkflowService', 'InvoiceTaxService', 'CreditNoteService', 'CustomerCreditService']));
$hashes = [];
foreach ($paths as $path) {
    $hashes[substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $root))+1)] =
        hash('sha256', str_replace(["\r\n", 'clubora.'], ["\n", 'clubunio.'], file_get_contents($path)));
}
$tenants = [];
foreach (App\ClubUnio\Models\Tenant::orderBy('id')->get() as $tenant) {
    try {
        $service = app(App\ClubUnio\Services\BexioConnectionService::class);
        $config = $service->configuration($tenant);
        $connection = $service->connection($tenant);
        $tenants[] = ['tenant_id' => $tenant->id, 'slug' => $tenant->slug, 'configured' => true,
            'connected' => !empty($connection['refresh_token']), 'file_scope' => in_array('file', $config['scopes'], true),
            'provider' => $tenant->accounting_provider];
    } catch (Throwable $error) {
        $tenants[] = ['tenant_id' => $tenant->id, 'slug' => $tenant->slug, 'configured' => false,
            'configuration_error' => get_class($error), 'code' => (int)$error->getCode()];
    }
}
echo json_encode(['database' => $db->getDatabaseName(), 'origin' => config('app.url'), 'hashes' => $hashes, 'tenants' => $tenants], JSON_THROW_ON_ERROR).PHP_EOL;
