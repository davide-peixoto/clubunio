<?php
// Real controller projections; synthetic session/metadata always roll back.
try {
    $root=dirname(__DIR__);
    if(PHP_SAPI!=='cli'||$root!=='/var/www/svs-laravel/releases/20261106-clubunio-mobile-hotpaths') throw new LogicException('Fixed candidate required');
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('app.url')!=='https://dev.clubunio.ch') throw new LogicException('DEV required');
    $db=Illuminate\Support\Facades\DB::connection('clubunio');
    if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Reviewed database required');
    $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();
    $source=$db->table('clubora.chat_messages')->where('tenant_id',$tenant->id)->where('id',709)->first(['account_id','conversation_id']);
    if(!$source) throw new LogicException('Existing reference required');
    $actor=App\ClubUnio\Models\Account::whereKey($source->account_id)->where('active',true)->where('api_only',false)->sole();
    config(['database.connections.hotpath_provider_probe'=>config('database.connections.clubunio')]);
    $provider=Illuminate\Support\Facades\DB::connection('hotpath_provider_probe');
    $session=(string)Illuminate\Support\Str::uuid(); $secret=bin2hex(random_bytes(32));
    $db->beginTransaction(); $provider->beginTransaction();
    try {
        $db->statement("SET LOCAL lock_timeout='1s'"); $db->statement("SET LOCAL statement_timeout='10s'");
        $provider->statement("SET LOCAL lock_timeout='1s'");
        if($db->selectOne('SELECT pg_backend_pid() AS pid')->pid===$provider->selectOne('SELECT pg_backend_pid() AS pid')->pid) throw new LogicException('Independent connections required');
        $provider->select('SELECT id FROM clubora.tenants WHERE id=? FOR SHARE',[$tenant->id]);
        $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
            'device_name'=>'Synthetic rollback performance check','expires_at'=>now()->addMinutes(3)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
        $queries=0; $db->listen(function()use(&$queries){$queries++;});
        foreach(['notifications','chats','chats/'.$source->conversation_id] as $operation) {
            $r=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/'.$operation,'GET');
            $r->headers->set('Authorization','Bearer '.$session.'.'.$secret);
            $r->headers->set('X-ClubUnio-Mobile-Features','svs-planning-v1,my-teams-v1,expenses-v1,personal-push-v1');
            $r->attributes->set('mobile.actor',$actor); $r->attributes->set('mobile.tenant',$tenant); $r->attributes->set('mobile.season_scope',true);
            $route=$app['router']->getRoutes()->match($r);$r->setRouteResolver(fn()=>$route);
            $app->instance('request',$r); app(App\ClubUnio\Mobile\MobileSessions::class)->authenticate($r);
            $before=$queries; $start=microtime(true);
            $data=app(App\ClubUnio\Mobile\MobileReadContext::class)->during($r,$actor,function()use($r,$operation,$actor,$tenant){
                app(App\ClubUnio\Mobile\MobileAccess::class)->enter($actor,$tenant);
                app(App\ClubUnio\Mobile\MobileBootstrapState::class)->authorizeTenant($r,$tenant);
                if($operation==='notifications') return app(App\ClubUnio\Mobile\MobileNotificationController::class)->index($r)->getData(true)['data'];
                return ($operation==='chats'?app(App\ClubUnio\Mobile\MobileController::class)->chats($r):app(App\ClubUnio\Mobile\MobileController::class)->chat($r))->getData(true)['data'];
            });
            $elapsed=round((microtime(true)-$start)*1000);
            if($elapsed>10000) throw new LogicException('Controller exceeded ten-second gate');
            $shape=$operation==='notifications'?['total'=>$data['totalRecordCount'],'page'=>count($data['notifications']),'unread'=>$data['unreadCount']]
                :($operation==='chats'?['total'=>count($data),'direct'=>count(array_filter($data,fn($c)=>$c['kind']==='direct'))]:['detail_verified'=>($data['id']??null)===(int)$source->conversation_id]);
            if($operation!=='notifications'&&$operation!=='chats'&&!$shape['detail_verified']) throw new LogicException('Wrong detail');
            echo json_encode(['operation'=>$operation==='notifications'?'notifications':($operation==='chats'?'chats':'chat-detail'),
                'elapsed_ms'=>$elapsed,'queries'=>$queries-$before,'shape'=>$shape,'provider_shared_lock_compatible'=>true,'rollback_only'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
        }
        $provider->statement('SAVEPOINT revoke_probe');$blocked=false;
        try{$provider->select('SELECT id FROM clubora.tenants WHERE id=? FOR UPDATE NOWAIT',[$tenant->id]);}
        catch(Illuminate\Database\QueryException $e){if(($e->errorInfo[0]??null)!=='55P03')throw $e;$blocked=true;}
        $provider->statement('ROLLBACK TO SAVEPOINT revoke_probe');
        if(!$blocked) throw new LogicException('Policy writes must still serialize');
    } finally {while($provider->transactionLevel()>0)$provider->rollBack();while($db->transactionLevel()>0)$db->rollBack();}
    if($db->table('clubora.mobile_sessions')->where('id',$session)->exists()) throw new LogicException('Synthetic session survived');
    echo "Synthetic session and metadata writes rolled back; policy writes still serialize; no provider call.\n";
} catch(Throwable $e) {fwrite(STDERR,'Mobile hotpath check stopped: '.($e instanceof LogicException?$e->getMessage():get_class($e)).PHP_EOL);exit(1);}
