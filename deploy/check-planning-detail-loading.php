<?php
// Fresh authorized, read-only detail projection. No login/device/message is created.
try {
    $root=dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || $root !== '/var/www/svs-laravel/releases/20261105-clubunio-planning-detail-loading') throw new LogicException('Fixed release required');
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.native_planning_enabled')
        || !config('clubunio-mobile-push.enabled') || !config('clubunio-mobile-push.delivery_enabled')) throw new LogicException('Unexpected flags');
    $db=Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected database');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout='15s'");
        $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();
        $actor=App\ClubUnio\Models\Account::where('username','davide.peixoto@svsissach.ch')->sole();
        $request=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/planning/events','GET',[
            'start'=>0,'recperpage'=>20,'from'=>now('Europe/Zurich')->startOfDay()->toIso8601String(),
            'to'=>now('Europe/Zurich')->addDays(14)->toIso8601String()]);
        $request->headers->set('X-ClubUnio-Mobile-Features','personal-push-v1');
        $request->attributes->set('mobile.actor',$actor); $request->attributes->set('mobile.tenant',$tenant);
        $request->attributes->set('mobile.season_scope',true); $app->instance('request',$request);
        app(App\ClubUnio\Mobile\MobileAccess::class)->enter($actor,$tenant);
        if (!app(App\ClubUnio\Mobile\MobilePushReady::class)->available($request)) throw new LogicException('Push must stay available');
        $probe=new class { public int $calls=0; public function snapshot(...$args): array { $this->calls++; throw new LogicException('Detail attempted recipient projection'); } };
        $app->instance(App\ClubUnio\Mobile\MobilePushSquad::class,$probe);
        $count=0; $db->listen(function () use (&$count) { $count++; });
        $start=microtime(true);
        $planning=app(App\ClubUnio\Mobile\MobilePlanningData::class);
        $events=$planning->events($request,$actor,$tenant);
        if (!$events['success'] || !$events['TeamTermine']) throw new LogicException('Missing list');
        $id=(int)$events['TeamTermine'][0]['TeamTerminID'];
        $count=0; $start=microtime(true);
        $data=$planning->event($actor,$tenant,$id,true,false);
        if ($probe->calls !== 0 || ($data['CanSendSquadPush']??null) !== true || isset($data['PushRevision']) || isset($data['PushRecipientFingerprint'])) throw new LogicException('Unexpected eager snapshot or lost capability');
        if (!$data['Teilnahmen'] || $count>200) throw new LogicException('Missing roster or detail query budget exceeded');
        echo json_encode(['read_only'=>true,'roster_count'=>count($data['Teilnahmen']),'queries'=>$count,
            'elapsed_ms'=>round((microtime(true)-$start)*1000),'squad_snapshots'=>$probe->calls,'push_flags_preserved'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR,'Planning list check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL); exit(1);
}
