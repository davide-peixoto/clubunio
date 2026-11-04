<?php

// Read-only readiness gate. Does not initialize credentials, enqueue or deliver.
try {
    if (PHP_SAPI !== 'cli' || array_slice($argv, 1) !== ['--closed']) throw new LogicException('Explicit closed push stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('clubunio-mobile-push.enabled', false) || config('clubunio-mobile-push.delivery_enabled', false)
        || config('clubunio-mobile-push.apps', [])) throw new LogicException('External push runtime must remain closed');
    if (ltrim(Composer\InstalledVersions::getPrettyVersion('google/auth') ?? '', 'v') !== '1.55.3'
        || ! class_exists(Google\Auth\Credentials\ServiceAccountCredentials::class)) throw new LogicException('Locked official OAuth SDK missing');
    $counts = [App\ClubUnio\Mobile\MobilePushController::class => 0, App\ClubUnio\Mobile\MobileNotificationController::class => 0];
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        foreach (array_keys($counts) as $controller) {
            if (! str_starts_with($route->getActionName(), $controller.'@')) continue;
            if (! in_array(App\ClubUnio\Mobile\MobileMiddleware::class, $route->gatherMiddleware(), true)) throw new LogicException('Personal push boundary missing');
            $counts[$controller]++;
        }
    }
    if (array_values($counts) !== [6, 4]) throw new LogicException('Unexpected push/notification route inventory');
    echo "Six personal push/four original notification routes and closed provider runtime verified; no device, credential, queue or delivery action.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Push release gate failed: '.get_class($error)."\n"); exit(1);
}
