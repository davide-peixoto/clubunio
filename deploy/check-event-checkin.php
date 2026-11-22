<?php

// Explicit, synthetic, real PostgreSQL check. No test row survives the transaction.
if (($argv[1] ?? '') !== '--synthetic-rollback' || !in_array($argv[2] ?? '', ['clubunio_dev','clubunio_stage'], true)) {
    fwrite(STDERR, "Explicit reviewed synthetic target required\n"); exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== $argv[2]) { fwrite(STDERR,"Unexpected target\n");exit(1); }
config(['cache.default'=>'array','clubunio-event-checkin.enabled'=>true,
    'clubunio-event-checkin.public_origin'=>config('app.url'), 'clubunio-event-checkin.tenant_origins'=>[]]);
$app->instance('request',Illuminate\Http\Request::create(config('app.url').'/synthetic-checkin','POST'));
$expect=static function(bool $ok):void { if(!$ok) throw new RuntimeException('Synthetic check-in assertion failed'); };
$denied=static function(callable $operation,int $status)use($expect):void {
    try {$operation();throw new RuntimeException('Expected check-in denial');}
    catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$expect($e->getStatusCode()===$status);}
};
$db->beginTransaction();
try {
    $db->statement("SET LOCAL application_name='event-checkin-synthetic-rollback'");
    $tenant=App\ClubUnio\Models\Tenant::create(['slug'=>'event-qa-'.bin2hex(random_bytes(6)),'name'=>'Synthetic Event Club']);
    $other=App\ClubUnio\Models\Tenant::create(['slug'=>'event-other-'.bin2hex(random_bytes(6)),'name'=>'Other Synthetic Club']);
    $season=new App\ClubUnio\Models\Season;
    $season->forceFill(['tenant_id'=>$tenant->id,'name'=>'Synthetic season','active'=>true])->save();
    $status=new App\ClubUnio\Models\MemberStatus;
    $status->forceFill(['tenant_id'=>$tenant->id,'code'=>'SYNTHETIC','name'=>'Synthetic member','active'=>true,'ends_membership'=>false])->save();
    $type=new App\ClubUnio\Models\MemberType;
    $type->forceFill(['tenant_id'=>$tenant->id,'code'=>'SYNTHETIC','name'=>'Synthetic type','active'=>true])->save();
    $member=new App\ClubUnio\Models\Member;
    $member->forceFill(['tenant_id'=>$tenant->id,'first_name'=>'Synthetic','last_name'=>'Member','birth_date'=>now()->subYears(12)->toDateString(),
        'status_id'=>$status->id,'type_id'=>$type->id])->save();
    $accounts=[];
    foreach(['admin','member','parent']as$kind){
        $a=new App\ClubUnio\Models\Account;
        $a->forceFill(['username'=>'event-qa-'.$kind.'-'.bin2hex(random_bytes(6)), 'display_name'=>'Synthetic '.$kind,
            'password'=>password_hash(bin2hex(random_bytes(16)),PASSWORD_BCRYPT,['cost'=>4]),'active'=>true,'api_only'=>false])->save();
        $db->table('clubunio.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$a->id,'role'=>$kind==='admin'?'admin':'member','portal_enabled'=>true]);
        $accounts[$kind]=$a;
    }
    foreach(['member'=>'self','parent'=>'parent']as$kind=>$linkKind){
        $db->table('clubunio.account_member_links')->insert(['tenant_id'=>$tenant->id,'account_id'=>$accounts[$kind]->id,
            'member_id'=>$member->id,'kind'=>$linkKind,'state'=>'approved','source'=>'synthetic_check']);
    }
    $event=new App\ClubUnio\Models\ClubEvent;
    $event->forceFill(['tenant_id'=>$tenant->id,'season_id'=>$season->id,'name'=>'Synthetic Assembly','location'=>'Synthetic hall','active'=>true])->save();
    $event->refresh();$service=app(App\ClubUnio\Services\EventCheckinService::class);
    $expect($service->qr($accounts['admin'],$tenant,$event->id)['Url']===null);
    $qr=$service->configure($accounts['admin'],$tenant,$event->id,['Revision'=>(int)$event->revision,'Enabled'=>true,
        'OpensAt'=>now()->subHour()->toIso8601String(),'ClosesAt'=>now()->addHour()->toIso8601String()]);
    $token=basename(parse_url($qr['Url'],PHP_URL_PATH));
    $expect($qr['Url']===$service->qr($accounts['admin'],$tenant,$event->id)['Url']);
    $shown=$service->show($accounts['member'],$tenant,$token);
    $expect($shown['Event']['IsOpen']===true && count($shown['Members'])===1 && $shown['Members'][0]['Present']===false);
    $expect($db->table('clubunio.event_checkins')->where('tenant_id',$tenant->id)->count()===0);
    $guest=new App\ClubUnio\Models\EventGuest;
    $guest->forceFill(['tenant_id'=>$tenant->id,'club_event_id'=>$event->id,'member_id'=>$member->id,'active'=>false])->save();
    $input=['MemberID'=>(int)$member->id,'TokenRevision'=>$shown['TokenRevision'],'EventRevision'=>$shown['Event']['Revision'],'Confirmation'=>true];
    $expect(app(App\ClubUnio\Services\MemberAccessService::class)->canEnter($accounts['parent'],$tenant));
    $denied(fn()=>$service->confirm($accounts['parent'],$tenant,$token,$input),403);
    $denied(fn()=>$service->confirm($accounts['admin'],$tenant,$token,$input),403);
    $denied(fn()=>$service->confirm($accounts['member'],$tenant,$token,[...$input,'Confirmation'=>'yes']),422);
    $first=$service->confirm($accounts['member'],$tenant,$token,$input);
    $again=$service->confirm($accounts['member'],$tenant,$token,$input);
    $expect($first['Confirmed']===true && $first['AlreadyPresent']===false && $again['AlreadyPresent']===true);
    $expect($first['CheckedInAt']===$again['CheckedInAt']);
    $expect($db->table('clubunio.event_checkins')->where('tenant_id',$tenant->id)->count()===1);
    $expect($guest->fresh()->active===false);
    $expect($service->show($accounts['member'],$tenant,$token)['Members'][0]['Present']===true);
    $service->close($accounts['admin'],$tenant,$event->id,$qr['Revision']);
    $denied(fn()=>$service->confirm($accounts['member'],$tenant,$token,$input),409);
    $qr=$service->qr($accounts['admin'],$tenant,$event->id);
    $closedRetry=[...$input,'EventRevision'=>$qr['Revision']];
    $expect($service->confirm($accounts['member'],$tenant,$token,$closedRetry)['AlreadyPresent']===true);
    $rotated=$service->rotate($accounts['admin'],$tenant,$event->id,$qr['Revision']);
    $expect($rotated['Url']!==$qr['Url']);
    try{$service->show($accounts['member'],$tenant,$token);throw new RuntimeException('Old QR accepted');}
    catch(Illuminate\Database\RecordNotFoundException){$expect(true);}
    $denied(fn()=>$service->show($accounts['member'],$other,basename(parse_url($rotated['Url'],PHP_URL_PATH))),403);
    echo "PG explicit self check-in, idempotency, RSVP separation, role/tenant boundaries, stale revision, close/rotation passed; rollback.\n";
}catch(Throwable $error){
    fwrite(STDERR,'Synthetic event check failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");$failed=true;
}finally{$db->rollBack();}
if($failed??false)exit(1);
