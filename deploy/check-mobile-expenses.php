<?php
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled = in_array('--enabled', $argv, true);
    if (! $enabled && ! in_array('--foundation', $argv, true)) { throw new LogicException('Explicit expense stage required'); }
    if ((bool) config('clubunio-mobile.native_expenses_enabled') !== $enabled) { throw new LogicException('Expense stage mismatch'); }
    $count = 0;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        foreach ([App\ClubUnio\Mobile\MobileExpenseController::class, App\ClubUnio\Mobile\MobileExpenseOcrController::class] as $controller) {
            if (! str_starts_with($route->getActionName(), $controller.'@')) { continue; }
            foreach ([App\ClubUnio\Mobile\MobileMiddleware::class, App\ClubUnio\Mobile\MobileExpenseReady::class] as $middleware) {
                if (! in_array($middleware, $route->gatherMiddleware(), true)) { throw new LogicException('Unprotected expense route'); }
            }
            $count++;
        }
    }
    if ($count !== 16) { throw new LogicException('Unexpected expense route inventory'); }
    if ($enabled) {
        $db = Illuminate\Support\Facades\DB::connection('clubunio');
        foreach (['mobile_expense_receipts', 'mobile_expense_commands'] as $table) {
            if (! $db->getSchemaBuilder()->hasTable('clubora.'.$table)) { throw new LogicException('Missing expense schema'); }
        }
        if (! $db->table('clubora.migrations')->where('migration', '2026_11_03_000088_mobile_expense_receipts')->exists()) { throw new LogicException('Missing expense ledger'); }
    }
    echo 'Personal expenses16 protected routes and '.($enabled ? 'enabled schema' : 'closed foundation')." verified; no provider or payment called.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Expense release gate failed: '.get_class($error)." (contents suppressed).\n"); exit(1);
}
