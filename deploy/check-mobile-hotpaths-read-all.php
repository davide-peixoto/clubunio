<?php
// Synthetic session and read-marker scenario are always rolled back. No provider calls.
try {
    $root=dirname(__DIR__);
    if(PHP_SAPI!=='cli'||$root!=='/var/www/svs-laravel/releases/20261106-clubunio-mobile-hotpaths') throw new LogicException('Fixed candidate required');
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('app.url')!=='https://dev.clubunio.ch') throw new LogicException('DEV required');
    $db=Illuminate\Support\Facades\DB::connection('clubunio');
    if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Reviewed database required');
    $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();
    $source=$db->table('clubora.chat_messages')->where('tenant_id',$tenant->id)->where('id',709)->first(['account_id']);
    if(!$source) throw new LogicException('Existing reference required');
    $actor=App\ClubUnio\Models\Account::whereKey($source->account_id)->where('active',true)->where('api_only',false)->sole();
    $owned=fn()=>$db->table('clubora.mobile_inbox')->where('tenant_id',$tenant->id)->where('account_id',$actor->id);
    $sources=fn()=>$db->table('clubora.notifications')->where('tenant_id',$tenant->id)->where('account_id',$actor->id);
    $before=$owned()->orderBy('id')->get(['id','read_at'])->toJson();
    $beforeSources=$sources()->orderBy('id')->get(['id','read_at'])->toJson();
    $session=(string)Illuminate\Support\Str::uuid(); $secret=bin2hex(random_bytes(32));
    $db->beginTransaction();
    try {
        $db->statement("SET LOCAL lock_timeout='2s'"); $db->statement("SET LOCAL statement_timeout='10s'");
        App\ClubUnio\Models\Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $ids=$owned()->orderBy('id')->pluck('id')->map(fn($v)=>(int)$v)->all();
        if(!$ids||count($ids)>100) throw new LogicException('Bounded existing sample required');
        $through=max($ids); $observed=array_slice($ids,-20);
        $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
            'device_name'=>'Synthetic rollback read check','expires_at'=>now()->addMinutes(2)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
        // Recreate unread input within this rollback only; preserve real read state afterwards.
        $owned()->whereIn('id',$ids)->update(['read_at'=>null]);
        $sourceIds=[];
        foreach($owned()->whereIn('id',$ids)->pluck('event_key') as $key) {
            if(preg_match('/^notification:([1-9][0-9]*)$/D',$key,$match)) $sourceIds[]=(int)$match[1];
        }
        if($sourceIds) $sources()->whereIn('id',$sourceIds)->update(['read_at'=>null]);
        $r=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/notifications/read-all','POST',
            ['through_id'=>$through,'observed_ids'=>$observed]);
        $r->headers->set('Authorization','Bearer '.$session.'.'.$secret);
        $r->attributes->set('mobile.actor',$actor); $r->attributes->set('mobile.tenant',$tenant);
        $r->attributes->set('mobile.season_scope',true); $app->instance('request',$r);
        app(App\ClubUnio\Mobile\MobileSessions::class)->authenticate($r);
        $queries=0;$db->listen(function()use(&$queries){$queries++;});$start=microtime(true);
        $response=app(App\ClubUnio\Mobile\MobileNotificationController::class)->readAll($r);
        $elapsed=round((microtime(true)-$start)*1000); $ack=$response->getData(true)['data']??null;
        if(!is_array($ack)||($ack['read']??null)!==true||!is_int($ack['unreadCount']??null)||$ack['unreadCount']<0
            ||($ack['through_id']??null)!==$through||!is_array($ack['read_ids']??null)
            ||array_diff($ack['read_ids'],$observed)) throw new LogicException('Invalid scoped acknowledgement');
        if($r->attributes->has(App\ClubUnio\Mobile\MobileReadContext::class)) throw new LogicException('Projection frame escaped');
        if($elapsed>10000) throw new LogicException('Read-all exceeded ten-second gate');
        echo json_encode(['rollback_scenario'=>true,'sample_rows'=>count($ids),'observed_rows'=>count($observed),
            'ack_read_rows'=>count($ack['read_ids']),'marked_rows'=>count($ack['marked_ids']??[]),'queries'=>$queries,'elapsed_ms'=>$elapsed],JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { while($db->transactionLevel()>0) $db->rollBack(); }
    if($before!==$owned()->orderBy('id')->get(['id','read_at'])->toJson()
        ||$beforeSources!==$sources()->orderBy('id')->get(['id','read_at'])->toJson()
        ||$db->table('clubora.mobile_sessions')->where('id',$session)->exists()) throw new LogicException('Rollback verification failed');
    echo "Read markers restored; synthetic session absent; no message or push created.\n";
} catch(Throwable $e) { fwrite(STDERR,'Notification check stopped: '.($e instanceof LogicException?$e->getMessage():get_class($e)).PHP_EOL);exit(1); }
