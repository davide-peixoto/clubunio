<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$enabled = in_array('--enabled', $argv, true);
if (!$enabled && !in_array('--foundation', $argv, true)) { throw new LogicException('Explicit registration release stage required'); }
if ((bool) config('clubunio-mobile.native_registrations_enabled', false) !== $enabled) { throw new LogicException('Registration release stage mismatch'); }
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected registration database'); }
$count = 0;
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    if (str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobileRegistrationController::class.'@')) {
        foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobileRegistrationReady::class] as $middleware) {
            if (!in_array($middleware, $route->gatherMiddleware(), true)) { throw new LogicException('Ungated registration route'); }
        }
        $count++;
    }
}
if ($count !== 11) { throw new LogicException('Unexpected registration route inventory'); }
if ($enabled) {
    foreach (['tenant_id', 'account_id', 'request_key', 'payload_sha256', 'registration_id'] as $column) {
        if (!$db->getSchemaBuilder()->hasColumn('clubora.mobile_registration_requests', $column)) { throw new LogicException('Registration idempotency schema incomplete'); }
    }
    if (!$db->table('clubora.migrations')->where('migration', '2026_11_03_000300_mobile_registration_requests')->exists()) { throw new LogicException('Registration migration ledger incomplete'); }
}
echo 'Personal registration '.($enabled ? 'enabled schema' : 'closed foundation')." and 11 protected routes verified; no applicant or attachment read/written.\n";
