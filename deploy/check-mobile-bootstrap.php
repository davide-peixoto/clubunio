<?php
try {
    if(PHP_SAPI!=='cli'||count($argv)!==2||!in_array($argv[1],['--closed','--enabled'],true)) throw new LogicException('Explicit bootstrap stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app=require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled=$argv[1]==='--enabled';
    if((bool)config('clubunio-mobile.bootstrap.enabled',false)!==$enabled||(bool)config('clubunio-mobile.native_role_guide_enabled',false)!==$enabled)
        throw new LogicException('Bootstrap/role-guide stage mismatch');
    if(!$enabled&&config('clubunio-mobile.bootstrap.origins',[])) throw new LogicException('Closed bootstrap allowlist required');
    if($enabled) {
        if(config('app.url')!=='https://dev.clubunio.ch'||!in_array('https://dev.clubunio.ch',config('clubunio-mobile.bootstrap.origins',[]),true)) throw new LogicException('Explicit DEV bootstrap origin required');
        $db=Illuminate\Support\Facades\DB::connection('clubunio');
        if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora'||!$db->table('clubora.migrations')->where('migration','2026_11_04_000800_mobile_app_announcements')->exists()
            ||!$db->getSchemaBuilder()->hasColumn('clubora.mobile_app_announcements','audience')) throw new LogicException('Reviewed existing800 required');
    }
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
    echo 'Bootstrap1 public/2 personal routes and '.($enabled?'enabled existing800':'closed feature/origin')." gates verified; no data or external action.\n";
} catch(Throwable $e) { fwrite(STDERR,'Bootstrap gate failed: '.get_class($e)." (contents suppressed).\n");exit(1); }
