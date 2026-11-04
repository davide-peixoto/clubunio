<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$enabled = in_array('--enabled', $argv, true);
if (!$enabled && !in_array('--foundation', $argv, true)) throw new LogicException('Explicit sponsor billing release stage required');
if ((bool) config('clubunio-mobile.native_sponsor_billing_enabled', false) !== $enabled) throw new LogicException('Sponsor billing release stage mismatch');
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected sponsor billing database');
$count = 0;
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    if (!str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobileSponsorBillingController::class.'@')) continue;
    foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobileSponsorReady::class] as $middleware) {
        if (!in_array($middleware, $route->gatherMiddleware(), true)) throw new LogicException('Ungated personal sponsor billing route');
    }
    if ($route->getActionMethod() !== 'index' && !in_array(App\ClubUnio\Mobile\MobileSponsorBillingReady::class, $route->gatherMiddleware(), true)) throw new LogicException('Ungated sponsor provider operation');
    $count++;
}
if ($count !== 4) throw new LogicException('Unexpected sponsor billing route inventory');
if ($enabled) {
    if (!config('clubunio-mobile.native_sponsor_enabled')) throw new LogicException('Sponsor base is not enabled');
    foreach (['sponsor_run_invoices' => ['revision', 'snapshot_encrypted', 'reference', 'connection_hash', 'configuration_hash', 'operation_token', 'lease_until'],
        'sponsor_run_billing_audit' => ['invoice_id', 'note_encrypted'], 'sync_work_items' => ['deduplication_key', 'encrypted_payload', 'state', 'attempts']] as $table => $columns) {
        foreach ($columns as $column) if (!$db->getSchemaBuilder()->hasColumn('clubora.'.$table, $column)) throw new LogicException('Existing canonical sponsor billing schema incomplete');
    }
}
echo 'Personal sponsor billing '.($enabled ? 'enabled canonical schema' : 'closed provider foundation')." and 4 scoped routes verified; no provider, mail, bank or invoice action performed.\n";
