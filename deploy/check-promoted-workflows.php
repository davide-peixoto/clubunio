<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Account,Tenant,Expense,ExpenseType};
use App\ClubUnio\Services\{ActiveTenant,ReactUi,SfvReconcileService,TeamPlanningService,PitchPlanningProjection,PlanningCalendarService,WorkTaskService,WorkNotificationHub,WorkGuidedCapture,DailyPlanningAlternatives,DailyCockpit};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB,Http,Mail,Queue,Crypt};
use Illuminate\Support\Str;
$db=DB::connection('clubunio');$stage='bootstrap';$checks=0;$filePath=null;$exitCode=0;
try{
    if(!in_array([config('app.url'),$db->getDatabaseName()],[['https://stage.clubunio.com','clubunio_stage'],['https://app.clubunio.com','clubunio_prod']],true))throw new LogicException('Reviewed Stage/PROD only');
    $origin=config('app.url');
    Http::preventStrayRequests();Mail::fake();Queue::fake();config(['clubunio-mobile-push.enabled'=>false,'clubunio-mobile-push.delivery_enabled'=>false]);
    $db->beginTransaction();$db->statement("SET LOCAL statement_timeout='30s'");
    $tenant=Tenant::where('slug','sv-sissach')->sole();$actor=Account::where('active',true)->where('api_only',false)->whereHas('tenants',fn($q)=>$q->whereKey($tenant->id)->whereIn('clubora.tenant_accounts.role',['admin','office']))->firstOrFail();
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));$session=$app['session']->driver();$session->start();Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag);
    $bind=function($club,$query=[],$path='/guided-capture')use($app,$session,$actor,$origin){
        Filament::auth()->setUser($actor);Filament::setTenant($club,isQuiet:true);
        $r=Illuminate\Http\Request::create($origin.$path,'GET',$query);$r->setUserResolver(fn()=>$actor);$r->setLaravelSession($session);
        $r->setRouteResolver(fn()=>(new Illuminate\Routing\Route('GET','/guided-capture',fn()=>null))->name('filament.clubunio.pages.guided-capture'));
        $app->instance('request',$r);app(ActiveTenant::class)->select($r,$club);Filament::bootCurrentPanel();
    };
    $mode=new class extends ReactUi{public bool $value=true;public function enabled(?int $tenantId=null):bool{return $this->value;}};$app->instance(ReactUi::class,$mode);
    if(!in_array('--security',$argv,true))foreach(['de','en'] as $locale)foreach([true,false] as $modern){
        $app->setLocale($locale);$mode->value=$modern;$bind($tenant);
        foreach([App\ClubUnio\Pages\Dashboard::class,App\ClubUnio\Pages\PitchPlanning::class,App\ClubUnio\Pages\SocialStudio::class,App\ClubUnio\Pages\MyClub::class,App\ClubUnio\Pages\TeamTasks::class,App\ClubUnio\Pages\NotificationCenter::class,App\ClubUnio\Pages\SfvCompetitions::class,App\ClubUnio\Pages\Components\WorkLiveStatus::class] as $page){
            if($page===App\ClubUnio\Pages\SocialStudio::class&&!$page::canAccess()){echo 'SocialStudio disabled by existing target permissions/configuration'.PHP_EOL;continue;}
            $stage='render '.class_basename($page);$html=Livewire\Livewire::mount($page);
            if(preg_match('/daily\.[a-z_]+/',strip_tags($html))||str_contains($html,'@js('))throw new LogicException('Render/translation error');echo $stage.' '.$locale.' '.($modern?'modern':'classic').PHP_EOL;$checks++;
        }
        $stage='receipt wizard';$bind($tenant,['kind'=>'expense']);$html=Livewire\Livewire::mount(App\ClubUnio\Pages\GuidedCapture::class,['kind'=>'expense']);
        if(!str_contains($html,trans('daily.receipt_step')))throw new LogicException('Receipt step missing');$checks++;
    }
    $app->setLocale('de');$mode->value=true;$bind($tenant);
    $stage='receipt';$existing=app(App\ClubUnio\Services\ExpenseService::class)->visible($actor,$tenant)->whereNotNull('member_id')->whereNotNull('bank_account_id')->firstOrFail();
    $type=ExpenseType::where('tenant_id',$tenant->id)->where('active',true)->value('id');
    $app->instance(App\ClubUnio\Mobile\MalwareScanner::class,new class implements App\ClubUnio\Mobile\MalwareScanner{public function scan(string $bytes):array{return ['clean'=>true,'version'=>'synthetic-acceptance'];}});
    $filePath=tempnam(sys_get_temp_dir(),'cu-daily-');file_put_contents($filePath,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlTAAAAAASUVORK5CYII='));
    $receipt=new Illuminate\Http\UploadedFile($filePath,'receipt.png','image/png',null,true);$command=(string)Str::uuid();
    $data=['member_id'=>$existing->member_id,'team_season_id'=>$existing->team_season_id,'round_id'=>$existing->round_id,'bank_account_id'=>$existing->bank_account_id,'expense_type_id'=>$type,'amount'=>'12.50','notes'=>'Daily rollback acceptance'];
    $capture=app(WorkGuidedCapture::class);$url=$capture->save($actor,$tenant,'expense',$data,$command,$receipt);$again=$capture->save($actor,$tenant,'expense',$data,$command,$receipt);if($url!==$again)throw new LogicException('Receipt retry changed result');
    parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$upload=$db->table('clubora.document_uploads')->where('tenant_id',$tenant->id)->where('id',$query['captureUpload']??0)->first();
    if(!$upload||$upload->state!=='pending'||!$upload->encrypted_bytes)throw new LogicException('Secure staged receipt missing');$checks++;
    $metadata=json_decode(Crypt::decryptString($upload->encrypted_metadata),true);if(($metadata['scanner_version']??'')!=='synthetic-acceptance')throw new LogicException('Scan metadata missing');$checks++;
    $stage='fixture';$fixture=Tenant::create(['slug'=>'daily-'.Str::lower(Str::random(12)),'name'=>'Daily rollback acceptance']);$planning=app(TeamPlanningService::class);
    $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$fixture->id,'account_id'=>$actor->id,'role'=>'admin']);$bind($fixture);
    $insert=fn($table,$data)=>$db->table('clubora.'.$table)->insertGetId(['tenant_id'=>$fixture->id]+$data);
    $season=$insert('seasons',['name'=>'Acceptance season','starts_on'=>'2029-07-01','ends_on'=>'2030-06-30','active'=>true]);
    $team=$insert('teams',['name'=>'Acceptance team','sfv_team_id'=>987650001,'active'=>true]);$teamSeason=$insert('team_seasons',['team_id'=>$team,'season_id'=>$season,'label'=>'Acceptance team','active'=>true]);
    TeamPlanningService::seedOptions($fixture->id);
    $field=$insert('training_options',['category'=>'field','code'=>'H','name'=>'Acceptance field','active'=>true]);
    $status=$insert('facility_statuses',['code'=>'ACCEPTANCE','name'=>'Open','blocks_training'=>false,'active'=>true]);$insert('field_availabilities',['field_id'=>$field,'status_id'=>$status,'active'=>true]);
    $match=$insert('sfv_schedule',['match_id'=>987650002,'season_id'=>2030,'host_name'=>'Acceptance team','guest_name'=>'Other team','team_a_id'=>987650001,'team_b_id'=>987650003,'title'=>'Acceptance game','starts_at'=>'2030-01-10T12:00:00+01:00','location'=>'Tannenbrunn - 1, Sissach','state'=>'Angesetzt','home'=>true,'match_type'=>'Meisterschaft','revision'=>1]);
    $stage='SFV home';$sfv=app(SfvReconcileService::class);$result=$sfv->reconcile($actor,$fixture,'2030-01-10','2030-01-10');if($result['new']!==1)throw new LogicException('Home reconciliation failed: '.json_encode($result['issues']));$checks++;
    $event=app(TeamPlanningService::class)->table($fixture,'team_events')->where('sfv_match_id',987650002)->sole();
    if(!app(PlanningCalendarService::class)->visible($actor,$fixture)->where('id',$event->id)->exists()||count(app(PitchPlanningProjection::class)->entries($fixture,'2030-01-10','2030-01-10'))!==1)throw new LogicException('Planning projections diverged');$checks++;
    $result=$sfv->reconcile($actor,$fixture,'2030-01-10','2030-01-10');if($result['unchanged']!==1)throw new LogicException('SFV retry duplicated');$checks++;
    $competition=$db->table('clubora.competitions')->where('tenant_id',$fixture->id)->sole();
    $room1=$insert('training_options',['category'=>'room','code'=>'1','name'=>'Home room','active'=>true]);$room2=$insert('training_options',['category'=>'room','code'=>'2','name'=>'Guest room','active'=>true]);
    $side=$insert('training_options',['category'=>'side','code'=>'L','name'=>'Left','active'=>true]);
    $field2=$insert('training_options',['category'=>'field','code'=>'K','name'=>'Second field','active'=>true]);$insert('field_availabilities',['field_id'=>$field2,'status_id'=>$status,'active'=>true]);
    $local=['host_room_id'=>$room1,'guest_room_id'=>$room2,'host_side_id'=>$side,'guest_side_id'=>$side,'remarks'=>'Keep local room assignment','show_on_signage'=>false];
    $db->table('clubora.competitions')->where('id',$competition->id)->update($local);
    $db->table('clubora.sfv_schedule')->where('id',$match)->update(['starts_at'=>'2030-01-11T15:30:00+01:00','location'=>'Tannenbrunn - 2, Sissach','state'=>'Neu angesetzt','revision'=>2]);$result=$sfv->reconcile($actor,$fixture,'2030-01-11','2030-01-11');
    $moved=$db->table('clubora.competitions')->where('tenant_id',$fixture->id)->sole();$movedEvent=$planning->table($fixture,'team_events')->where('id',$event->id)->sole();
    if($result['issues']!==[]||$result['updated']!==1||(int)$moved->id!==(int)$competition->id||(int)$moved->field_id!==$field2||Carbon\CarbonImmutable::parse($movedEvent->starts_at)->tz('Europe/Zurich')->format('Y-m-d H:i')!=='2030-01-11 15:30')throw new LogicException('Automatic SFV reschedule failed');$checks++;
    foreach($local as $key=>$value)if($moved->$key!=$value)throw new LogicException('Local metadata changed: '.$key);$checks++;
    if($sfv->reconcile($actor,$fixture,'2030-01-11','2030-01-11')['unchanged']!==1)throw new LogicException('Rescheduled retry duplicated');$checks++;
    $insert('sfv_schedule',['match_id'=>987650004,'season_id'=>2030,'host_name'=>'Other team','guest_name'=>'Acceptance team','team_a_id'=>987650003,'team_b_id'=>987650001,'title'=>'Away acceptance','starts_at'=>'2030-01-11T12:00:00+01:00','location'=>'Away venue','state'=>'Angesetzt','home'=>false,'match_type'=>'Meisterschaft','revision'=>1]);
    if($sfv->reconcile($actor,$fixture,'2030-01-11','2030-01-11')['new']!==1||$db->table('clubora.competitions')->where('tenant_id',$fixture->id)->count()!==1)throw new LogicException('Away game got a local pitch');$checks++;
    $stage='task board/cockpit';$task=app(WorkTaskService::class)->save($actor,$fixture,null,['title'=>'Acceptance task','assigned_to'=>$actor->id,'due_on'=>now()->toDateString()]);
    $cockpit=app(DailyCockpit::class)->data($actor,$fixture);if($cockpit['taskCount']!==1)throw new LogicException('Due task absent');$checks++;
    $html=Livewire\Livewire::mount(App\ClubUnio\Pages\TeamTasks::class);if(!str_contains($html,'moveTask')||!str_contains($html,'draggable="true"'))throw new LogicException('Board controls missing');
    $component=app(App\ClubUnio\Pages\TeamTasks::class);$component->mount();$component->moveTask($task,1,'doing');
    if(app(WorkTaskService::class)->get($actor,$fixture,$task)->state!=='doing')throw new LogicException('Board move failed');$checks++;
    try{$component->moveTask($task,1,'done');throw new LogicException('Stale board transition accepted');}catch(Illuminate\Validation\ValidationException){$checks++;}
    $stage='notification groups';$notice=$insert('notifications',['account_id'=>$actor->id,'object_type'=>'workspace_task','object_id'=>$task,'title'=>'One','body'=>'']);$latest=$insert('notifications',['account_id'=>$actor->id,'object_type'=>'workspace_task','object_id'=>$task,'title'=>'Two','body'=>'']);
    $hub=app(WorkNotificationHub::class);if($hub->unreadCount($actor,$fixture)!==1||$hub->page($actor,$fixture,1,true)['rows'][0]['count']!==2)throw new LogicException('Grouping mismatch');$hub->readGroup($actor,$fixture,$latest);if($hub->unreadCount($actor,$fixture)!==0)throw new LogicException('Unread badge mismatch');$checks++;
    $stage='team alternative';$planning=app(TeamPlanningService::class);$eventId=$planning->saveEvent($actor,$fixture,null,['team_season_id'=>$teamSeason,'title'=>'Standalone acceptance','kind'=>'TRAINING','mode'=>'ANMELDEN','status'=>'AKTIV','starts_at'=>'2030-01-12T12:00','ends_at'=>'2030-01-12T13:00','deadline_at'=>'2030-01-12T11:00','location'=>'Meeting room']);
    $alternatives=app(DailyPlanningAlternatives::class);$proposal=$alternatives->suggestions($actor,$fixture,'team',$eventId,'2030-01-12');if(!$proposal['items'])throw new LogicException('No team alternative');$alternatives->apply($actor,$fixture,$proposal['source'],$proposal['items'][0]);if($planning->event($actor,$fixture,$eventId)->revision!==2)throw new LogicException('Team move failed');$checks++;
    $stage='bus alternative';foreach(['ANGEFRAGT','GENEHMIGT','UNBEKANNT'] as $code)$insert('bus_statuses',compact('code')+['name'=>$code,'active'=>true]);
    $bus1=$insert('buses',['name'=>'Bus A','active'=>true]);$bus2=$insert('buses',['name'=>'Bus B','active'=>true]);$member=$insert('members',['first_name'=>'Acceptance','last_name'=>'Member']);
    $reservation=$insert('bus_reservations',['bus_id'=>$bus1,'team_season_id'=>$teamSeason,'member_id'=>$member,'status'=>'GENEHMIGT','starts_at'=>'2030-01-12T12:00:00+01:00','ends_at'=>'2030-01-12T13:00:00+01:00']);
    $proposal=$alternatives->suggestions($actor,$fixture,'bus',$reservation,'2030-01-12');if(($proposal['items'][0]['bus_id']??0)!==$bus2)throw new LogicException('Free bus missing');$alternatives->apply($actor,$fixture,$proposal['source'],$proposal['items'][0]);
    $row=$db->table('clubora.bus_reservations')->where('id',$reservation)->first();if($row->status!=='GENEHMIGT'||(int)$row->bus_id!==$bus2||$row->revision!==2)throw new LogicException('Bus approval/assignment changed');$checks++;
    $stage='full SFV sync pipeline';
    foreach(['SFV_APPLICATION_KEY'=>'fixture-key','SFV_APPLICATION_PASS'=>'fixture-pass','SFV_CLUB_ID'=>'98765','SFV_SEASON_ID_AUTO'=>'0','SFV_SEASON_ID'=>'2030'] as $key=>$value)$insert('tenant_settings',['area'=>'general','key'=>$key,'label'=>$key,'value_type'=>'string','is_secret'=>true,'encrypted_value'=>Crypt::encryptString($value),'enabled'=>true]);
    $providerMatch=['matchId'=>987650005,'seasonId'=>2030,'teamNameA'=>'Acceptance team','teamNameB'=>'Pipeline other','teamAId'=>987650001,'teamBId'=>987650003,'matchDate'=>'2030-01-13T12:00:00','stadiumPlaygroundName'=>'Tannenbrunn - 1, Sissach','matchStateName'=>'Angesetzt','matchTypeName'=>'Meisterschaft'];
    Http::fake(function($request)use(&$providerMatch){
        if($request->url()==='https://stg-club-api-services.football.ch/api/token')return Http::response(['token'=>'fixture-token']);
        if(str_starts_with($request->url(),'https://stg-club-api-services.football.ch/api/club/schedule?'))return Http::response([$providerMatch]);
        throw new LogicException('Unexpected synthetic HTTP destination');
    });
    // Stored sync work must reconcile even without a logged-in browser or selected Filament tenant.
    request()->setUserResolver(fn()=>null);Filament::setTenant(null,isQuiet:true);
    $count=app(App\ClubUnio\Services\SfvPlanningService::class)->refresh($actor,$fixture,['from'=>'2030-01-13','to'=>'2030-01-13']);
    $summary=request()->attributes->get(SfvReconcileService::class.':'.$fixture->id);
    if($count!==1||($summary['new']??0)!==1||!$planning->table($fixture,'team_events')->where('sfv_match_id',987650005)->exists())throw new LogicException('Fetch-to-calendar pipeline failed');$checks++;
    $stage='API source contract';$bind($fixture,[],'/api/test');$providerMatch['matchId']=987650006;$providerMatch['matchDate']='2030-01-14T12:00:00';

    app(App\ClubUnio\Services\SfvPlanningService::class)->refresh($actor,$fixture,['from'=>'2030-01-14','to'=>'2030-01-14']);
    if(!$planning->table($fixture,'sfv_schedule')->where('match_id',987650006)->exists()||$planning->table($fixture,'team_events')->where('sfv_match_id',987650006)->exists())throw new LogicException('API source-only contract widened');$checks++;
    if(Http::recorded()->count()!==4||Mail::hasSent(Illuminate\Contracts\Mail\Mailable::class)||Mail::hasQueued(Illuminate\Contracts\Mail\Mailable::class))throw new LogicException('Unexpected provider/mail activity');
    echo $checks." daily instance checks passed; transaction rolled back, no real sync, storage transfer, delivery or payment.\n";
}catch(Throwable $error){fwrite(STDERR,'Daily acceptance failed at '.$stage.': '.get_class($error).' '.$error->getMessage().PHP_EOL);$exitCode=1;}
finally{if($db->transactionLevel())$db->rollBack();if($filePath&&is_file($filePath))unlink($filePath);}

exit($exitCode);
