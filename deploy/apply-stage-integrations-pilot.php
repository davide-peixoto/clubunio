<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('staging') || config('app.url') !== 'https://stage.clubunio.com') {
    exit(1);
}
StageMigrationTarget::authorize(DB::connection('clubunio'), 'clubunio_stage');
$exit = $kernel->call('migrate', [
    '--database' => 'clubunio',
    '--path' => [
        'database/clubunio/2026_11_13_002000_support_center.php',
        'database/clubunio/2026_11_13_002010_support_public_requests.php',
        'database/clubunio/2026_11_13_002090_support_triage.php',
        'database/clubunio/2026_11_13_002200_support_workflow.php',
        'database/clubunio/2026_11_15_002300_support_queue.php',
        'database/clubunio/2026_11_15_002310_support_zammad.php',
        'database/clubunio/2026_11_16_000090_keycloak_account_links.php',
        'database/clubunio/2026_11_16_003000_tenant_accounting_provider.php',
        'database/clubunio/2026_11_16_003010_erpnext_invoice_transfers.php',
        'database/clubunio/2026_11_16_003020_erpnext_expense_transfers.php',
        'database/clubunio/2026_11_16_003025_erpnext_status_reconciliation.php',
    ],
    '--force' => true,
]);
echo $kernel->output();
exit($exit);
