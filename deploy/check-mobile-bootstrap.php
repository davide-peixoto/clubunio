<?php
try {
    if(PHP_SAPI!=='cli'||!in_array('--closed',$argv,true)) throw new LogicException('Explicit closed bootstrap stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app=require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('clubunio-mobile.bootstrap.enabled',false)!==false||config('clubunio-mobile.native_role_guide_enabled',false)!==false
        ||config('clubunio-mobile.bootstrap.origins',[])) throw new LogicException('Bootstrap/role-guide acceptance remains closed');
    $public=0;$personal=0;
    foreach(Illuminate\Support\Facades\Route::getRoutes() as $route) {
        $action=$route->getActionName();
        if($action===App\ClubUnio\Mobile\MobileBootstrapController::class.'@public') {
            if(in_array(App\ClubUnio\Mobile\MobileMiddleware::class,$route->gatherMiddleware(),true)) throw new LogicException('Public bootstrap must work before login');
            $public++;
        }
        if(in_array($action,[App\ClubUnio\Mobile\MobileBootstrapController::class.'@personal',App\ClubUnio\Mobile\MobileRoleGuideController::class.'@show'],true)) {
            if(!in_array(App\ClubUnio\Mobile\MobileMiddleware::class,$route->gatherMiddleware(),true)) throw new LogicException('Personal bootstrap/guide boundary missing');
            $personal++;
        }
    }
    if($public!==1||$personal!==2) throw new LogicException('Unexpected bootstrap/guide contract');
    echo "Bootstrap1 public/2 personal routes and closed feature/origin gates verified; no data or external action.\n";
} catch(Throwable $e) { fwrite(STDERR,'Bootstrap gate failed: '.get_class($e)." (contents suppressed).\n");exit(1); }
