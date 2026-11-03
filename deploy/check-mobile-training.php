<?php

try {
    $foundation = in_array('--foundation', $argv, true); $enabled = in_array('--training-enabled', $argv, true);
    if (PHP_SAPI !== 'cli' || $foundation === $enabled) { throw new LogicException('Exactly one explicit release stage required'); }
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected database'); }
    if ((bool) config('clubunio-mobile.native_training_enabled') !== $enabled) { throw new LogicException('Release stage mismatch'); }
    $count = 0;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (str_starts_with($route->getActionName(), App\ClubUnio\Mobile\MobileTrainingController::class.'@')) {
            foreach ([App\ClubUnio\Mobile\MobileTrainingReady::class, App\ClubUnio\Mobile\MobileMiddleware::class] as $middleware) {
                if (!in_array($middleware, $route->gatherMiddleware(), true)) { throw new LogicException('Unprotected personal training route'); }
            }
            $count++;
        }
    }
    if ($count !== 11) { throw new LogicException('Unexpected training routes'); }
    foreach (array_merge(['training_plans','training_plan_requests','mobile_ai_work','mobile_sessions'], $enabled ? ['mobile_training_plan_shares'] : []) as $table) {
        if (!$db->getSchemaBuilder()->hasTable('clubora.'.$table)) { throw new LogicException('Missing training schema'); }
    }
    if (config('clubunio-mobile.training_ai.enabled', false)) { throw new LogicException('AI cost/provider acceptance is a separate gate'); }
    echo "Personal training11 routes, explicit release stage and schema verified; no data or provider action.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Training release gate failed: '.get_class($error)." (record contents suppressed).\n"); exit(1);
}
