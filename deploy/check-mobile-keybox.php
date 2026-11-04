<?php
try {
    if (PHP_SAPI!=='cli'||!in_array('--closed',$argv,true)) throw new LogicException('Explicit closed keybox stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app=require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('clubunio-mobile.native_keybox_enabled',false)!==false) throw new LogicException('Native keybox stage mismatch');
    foreach(['issuance_enabled','provider_enabled','operations_reviewed','offline_revocation_acknowledged'] as $flag) {
        if(config('clubunio-mobile.keybox.'.$flag,false)!==false) throw new LogicException('Keybox operations must remain closed');
    }
    if(config('clubunio-mobile.keybox.tenant_slugs',[])||config('clubunio-mobile.keybox.approved_keybox_ids',[])) throw new LogicException('Keybox allowlists need separate operational acceptance');
    $count=0;
    foreach(Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if(!str_starts_with($route->getActionName(),App\ClubUnio\Mobile\MobileKeyboxController::class.'@')) continue;
        foreach([App\ClubUnio\Mobile\MobileMiddleware::class,App\ClubUnio\Mobile\MobileKeyboxReady::class] as $middleware) {
            if(!in_array($middleware,$route->gatherMiddleware(),true)) throw new LogicException('Unprotected keybox route');
        }
        $count++;
    }
    if($count!==6) throw new LogicException('Unexpected keybox contract');
    echo "Keybox6 personal routes, closed flags/allowlists verified; no schema, provider, PIN or hardware action.\n";
} catch(Throwable $error) { fwrite(STDERR,'Keybox gate failed: '.get_class($error)." (contents suppressed).\n"); exit(1); }
