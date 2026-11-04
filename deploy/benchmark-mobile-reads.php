<?php
// DEV-only, bounded read timing with a synthetic actor/session, always rolled back.
$started=hrtime(true);$stage="bootstrap";
try {
    if (PHP_SAPI !== 'cli' || !in_array(array_slice($argv,1), [['--synthetic-rollback'],['--synthetic-rollback','--role=trainer'],['--synthetic-rollback','--role=technical_lead']],true)) throw new LogicException('Explicit diagnostic mode required');
    $role=isset($argv[2])?substr($argv[2],7):'admin';
    require getcwd().'/vendor/autoload.php';
    $app=require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch') throw new LogicException('Exact DEV required');
    $db=Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new LogicException('Exact standalone DEV DB required');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Mail::fake(); Illuminate\Support\Facades\Queue::fake();
    $queries=0; $sqlMs=0.0;
    $db->listen(function($event) use (&$queries,&$sqlMs){$queries++;$sqlMs+=$event->time;});
    $ping=[];
    for($i=0;$i<3;$i++){ $t=hrtime(true);$db->select('select 1');$ping[]=round((hrtime(true)-$t)/1e6,2); }
    $db->beginTransaction();
    try {
        $db->statement("SET LOCAL lock_timeout TO '2s'"); $db->statement("SET LOCAL statement_timeout TO '15s'");
        $db->statement("SET LOCAL application_name TO 'clubunio-mobile-bounded-read-benchmark'");
        $target=$db->table('clubora.expenses')->where('active',true)->groupBy('tenant_id')->selectRaw('tenant_id,count(*) as records')->orderByDesc('records')->value('tenant_id');
        $tenant=$target ? App\ClubUnio\Models\Tenant::findOrFail($target) : App\ClubUnio\Models\Tenant::query()->firstOrFail();
        $actor=new App\ClubUnio\Models\Account;
        $actor->forceFill(['username'=>'perf-read-'.bin2hex(random_bytes(8)),'display_name'=>'Synthetic read benchmark',
          'password'=>'not-a-password-login','active'=>true,'api_only'=>false])->save();
        $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>$role,'portal_enabled'=>true]);
        if ($role!=='admin') {
            $members=app(App\ClubUnio\Services\MemberAccessService::class)->activeMembers($tenant)->select('clubora.members.id');
            $table=$role==='trainer'?'team_staff':'technical_leads';
            $member=$db->table('clubora.'.$table)->where('tenant_id',$tenant->id)->where('active',true)->whereIn('member_id',$members)->value('member_id');
            if (!$member) throw new LogicException('No existing active role fixture source');
            $db->table('clubora.account_member_links')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic-read-benchmark']);
        }
        $id=(string)Illuminate\Support\Str::uuid();$secret=bin2hex(random_bytes(32));
        $db->table('clubora.mobile_sessions')->insert(['id'=>$id,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
          'device_name'=>'Synthetic read benchmark','expires_at'=>now()->addHour()->toIso8601String(),'created_at'=>now()->toIso8601String()]);
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
        $paths=['bootstrap'=>'bootstrap','modules'=>'modules','expenses-100'=>'expenses?limit=100','expenses-options'=>'expenses/options',
          'planning-20'=>'planning/events?recperpage=20','my-teams-20'=>'my-teams?recperpage=20',
          'members-20'=>'members?recperpage=20','registrations-20'=>'registrations?recperpage=20',
          'documents-20'=>'member-documents?limit=20','training-20'=>'training-plans?recperpage=20',
          'sponsor-20'=>'sponsor-run/events?recperpage=20','bus-20'=>'bus/reservations?limit=20',
          'facilities'=>'facilities/fields','notifications-20'=>'notifications?limit=20'];
        if ($role!=='admin') {$paths=array_intersect_key($paths,array_flip(['bootstrap','modules','expenses-100','expenses-options']));}
        $results=[];
        foreach($paths as $name=>$path) for($sample=0;$sample<2;$sample++) {
$stage=$name;
            $queries=0;$sqlMs=0.0;$t=hrtime(true);
            $request=Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/'.$path,'GET',[],[],[],
              ['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json',
               'HTTP_AUTHORIZATION'=>'Bearer '.$id.'.'.$secret,'HTTP_X_CLUBUNIO_MOBILE_FEATURES'=>'svs-planning-v1,my-teams-v1,expenses-v1,expense-ocr-v1,facilities-v1,bus-v1,member-documents-v1,members-v1,registrations-v1,training-v1,sponsor-v1,member-id-card-v1,penalty-v1']);
            $response=$kernel->handle($request);$elapsed=round((hrtime(true)-$t)/1e6,2);
            $body=$response->getContent();$data=json_decode($body,true)['data']??[];
            $count=null;foreach(['rows','TeamTermine','Teams','TeamSaisons','teams','modules','members','registrations','plans','events','documents','notifications','fields'] as $key)if(isset($data[$key])&&is_array($data[$key])){$count=count($data[$key]);break;}
            $results[$name][]=['status'=>$response->getStatusCode(),'milliseconds'=>$elapsed,'queries'=>$queries,'sql_ms'=>round($sqlMs,2),'bytes'=>strlen($body),'rows'=>$count,'total'=>$data['total']??$data['totalRecordCount']??null];
            if($response->getStatusCode()!==200) throw new LogicException('Benchmark route failed: '.$name.' status'.$response->getStatusCode());
        }
        if(Illuminate\Support\Facades\Http::recorded()->isNotEmpty())throw new LogicException('Unexpected HTTP provider request');
        $output=['source'=>basename(getcwd()),'mode'=>'kernel-synthetic-role-existing-tenant-data','role'=>$role,'bootstrap_and_probe_total_ms'=>round((hrtime(true)-$started)/1e6,2),'database_select1_ms'=>$ping,'results'=>$results,'rolled_back'=>true];
    } finally {$db->rollBack();}
    if ($db->transactionLevel()!==0 || $db->table('clubora.accounts')->where('id',$actor->id)->exists() || $db->table('clubora.mobile_sessions')->where('id',$id)->exists()) throw new LogicException('Rollback not verified');
    echo json_encode($output,JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $error){fwrite(STDERR,'Bounded benchmark failed at '.$stage.': '.get_class($error).PHP_EOL);exit(1);}
