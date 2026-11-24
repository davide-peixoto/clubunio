<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{ActiveTenant,ReactUi,WorkTaskService,WorkNotificationHub,WorkObjectSearch,WorkGuidedCapture};
use App\ClubUnio\Pages\{ObjectSearch,NotificationCenter,TeamTasks,GuidedCapture,PlanningConflicts};
use Illuminate\Support\Facades\{DB,Http};
use Filament\Facades\Filament;
use Illuminate\Support\Str;
$db=DB::connection('clubunio');$stage='start';$checks=0;
try{
    if(config('app.url')!=='https://dev.clubunio.ch'||$db->getDatabaseName()!=='clubunio_dev')throw new LogicException('DEV only');
    Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Queue::fake();config(['clubunio-mobile-push.enabled'=>false,'clubunio-mobile-push.delivery_enabled'=>false]);
    $db->beginTransaction();$db->statement("SET LOCAL statement_timeout='30s'");Http::preventStrayRequests();
    $actor=Account::where('username','davide.peixoto@svsissach.ch')->sole();$tenant=Tenant::where('slug','sv-sissach')->sole();
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));$session=$app['session']->driver();$session->start();
    Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag);
    $bind=function($a,$t,$query=[])use($app,$session){
        Filament::auth()->setUser($a);Filament::setTenant($t,isQuiet:true);
        $r=Illuminate\Http\Request::create('https://dev.clubunio.ch/guided-capture','GET',$query);$r->setUserResolver(fn()=>$a);$r->setLaravelSession($session);
        $r->setRouteResolver(fn()=>(new Illuminate\Routing\Route('GET','/guided-capture',fn()=>null))->name('filament.clubunio.pages.guided-capture'));
        $app->instance('request',$r);app(ActiveTenant::class)->select($r,$t);Filament::bootCurrentPanel();
    };
    $mode=new class extends ReactUi{public bool $value=true;public function enabled(?int $tenantId=null):bool{return $this->value;}};$app->instance(ReactUi::class,$mode);
    if(!in_array('--security',$argv,true))foreach(['de','en'] as $locale)foreach([true,false] as $modern){
        $app->setLocale($locale);$mode->value=$modern;$bind($actor,$tenant);
        foreach([ObjectSearch::class,NotificationCenter::class,TeamTasks::class,PlanningConflicts::class] as $page){
            $stage='render '.class_basename($page);$html=Livewire\Livewire::mount($page);
            echo $stage.' '.$locale.' '.($modern?'modern':'classic').PHP_EOL;
            if(!str_contains($html,'cu-workflow')||preg_match('/work_hub\.[a-z_]+/',strip_tags($html)))throw new LogicException('Render/translation failure');$checks++;
        }
        foreach(['member','expense','event'] as $kind){$stage='wizard '.$kind;$bind($actor,$tenant,['kind'=>$kind]);$html=Livewire\Livewire::mount(GuidedCapture::class,['kind'=>$kind]);echo $stage.' '.$locale.PHP_EOL;if(!str_contains($html,'cu-work-hub'))throw new LogicException('Wizard missing');$checks++;}
    }
    $bind($actor,$tenant);$app->setLocale('de');$mode->value=true;
    $stage='search';foreach(['Peixoto','10','%_'] as $term){app(WorkObjectSearch::class)->results($actor,$tenant,$term);$checks++;}
    $stage='task';$tasks=app(WorkTaskService::class);$id=$tasks->save($actor,$tenant,null,['title'=>'Work hub acceptance','body'=>'Transaction fixture','assigned_to'=>$actor->id,'due_on'=>now()->toDateString()]);
    $tasks->transition($actor,$tenant,$id,1,'doing');$tasks->transition($actor,$tenant,$id,2,'done');
    try{$tasks->transition($actor,$tenant,$id,1,'open');throw new LogicException('Stale accepted');}catch(Illuminate\Validation\ValidationException){$checks++;}
    $tasks->transition($actor,$tenant,$id,3,'archived');$tasks->transition($actor,$tenant,$id,4,'open');
    if($tasks->get($actor,$tenant,$id)->revision!==5)throw new LogicException('Revision failed');$checks++;
    $stage='owned notice';$notice=$db->table('clubora.notifications')->insertGetId(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'object_type'=>'workspace_task','object_id'=>$id,'title'=>'Acceptance','body'=>'']);
    app(WorkNotificationHub::class)->read($actor,$tenant,$notice);if(!$db->table('clubora.notifications')->where('id',$notice)->value('read_at'))throw new LogicException('Notice not read');$checks++;
    $stage='guided member';$command=(string)Str::uuid();$data=['first_name'=>'Workhub','last_name'=>'Acceptance','status_id'=>\App\ClubUnio\Models\MemberStatus::where('tenant_id',$tenant->id)->where('active',true)->where('ends_membership',false)->value('id'),'type_id'=>\App\ClubUnio\Models\MemberType::where('tenant_id',$tenant->id)->where('active',true)->value('id'),'billing_recipient_id'=>\App\ClubUnio\Models\BillingRecipient::where('tenant_id',$tenant->id)->where('active',true)->where('email_field','email')->value('id'),'email'=>'workhub-acceptance@example.invalid','birth_date'=>null,'city'=>'Test'];$capture=app(WorkGuidedCapture::class);
    $url=$capture->save($actor,$tenant,'member',$data,$command);if($capture->save($actor,$tenant,'member',$data,$command)!==$url)throw new LogicException('Idempotency failed');$checks++;
    try{$capture->save($actor,$tenant,'member',array_replace($data,['city'=>'Changed']),$command);throw new LogicException('Command mismatch accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==409)throw $e;$checks++;}
    $stage='guided expense';
    $expense=app(\App\ClubUnio\Services\ExpenseService::class)->visible($actor,$tenant)->whereNotNull('member_id')->whereNotNull('bank_account_id')->first();
    $type=\App\ClubUnio\Models\ExpenseType::where('tenant_id',$tenant->id)->where('active',true)->value('id');
    if(!$expense||!$type)throw new LogicException('Expense fixture prerequisite missing');
    $capture->save($actor,$tenant,'expense',['member_id'=>$expense->member_id,'team_season_id'=>$expense->team_season_id,'round_id'=>$expense->round_id,'bank_account_id'=>$expense->bank_account_id,'expense_type_id'=>$type,'amount'=>'12.50','notes'=>'Work hub rollback acceptance'],(string)Str::uuid());$checks++;
    $stage='guided event';$team=app(\App\ClubUnio\Services\TeamPlanningService::class)->teams($actor,$tenant,true,'create')->firstOrFail();
    $capture->save($actor,$tenant,'event',['team_season_id'=>$team->id,'title'=>'Work hub rollback acceptance','kind'=>'TRAINING','mode'=>'ANMELDEN','starts_at'=>'2026-12-28T18:00','ends_at'=>'2026-12-28T19:30','deadline_at'=>'2026-12-28T17:00','location'=>'Test'],(string)Str::uuid());$checks++;
    $stage='synthetic tenant';$fixture=Tenant::create(['slug'=>'workhub-'.Str::lower(Str::random(12)),'name'=>'Work hub rollback fixture']);
    $account=function($role,$profile=null)use($db,$fixture){$a=(new Account)->forceFill(['username'=>'workhub-'.Str::random(20),'display_name'=>'Work hub fixture','password'=>bcrypt(Str::random(32)),'active'=>true,'api_only'=>false]);$a->save();$db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$fixture->id,'account_id'=>$a->id,'role'=>$role,'access_profile_id'=>$profile]);return $a;};
    $one=$account('admin');$two=$account('office');$bind($one,$fixture);
    $stage='mentions';$chat=app(\App\ClubUnio\Mobile\ChatService::class);$room=$chat->direct($one,$fixture,$two->id);$chat->chat($two,$fixture,$room->id);
    $mentions=app(\App\ClubUnio\Services\WorkChatMentions::class);$people=$mentions->candidates($one,$fixture,$room->id);if(array_column($people,'id')!==[(int)$two->id])throw new LogicException('Mention candidates mismatch');$checks++;
    $input=['client_id'=>(string)Str::uuid(),'text'=>'Synthetic mention acceptance','kind'=>'text','mentions'=>[$two->id]];
    $message=$mentions->send($one,$fixture,$room->id,$input);$mentions->send($one,$fixture,$room->id,$input);
    if($db->table('clubora.chat_mentions')->where('tenant_id',$fixture->id)->count()!==1)throw new LogicException('Mention retry duplicated');$checks++;
    $bind($two,$fixture);if(!$mentions->target($two,$fixture,$message->id))throw new LogicException('Mention target missing');$checks++;
    $stage='task profile';$profile=$db->table('clubora.access_profiles')->insertGetId(['tenant_id'=>$fixture->id,'name'=>'Task fixture','active'=>true,'permissions'=>json_encode(['tasks.view','tasks.create','tasks.complete']),'revision'=>1]);
    $db->table('clubora.account_roles')->where('code','profile')->update(['active'=>true]);$reader=$account('profile',$profile);$bind($reader,$fixture);
    if(!TeamTasks::canAccess())throw new LogicException('Task profile cannot enter');
    $own=$tasks->save($reader,$fixture,null,['title'=>'Owned fixture','assigned_to'=>$reader->id]);$checks++;
    try{$tasks->save($reader,$fixture,$own,['title'=>'Forged assignment','assigned_to'=>$one->id,'revision'=>1]);throw new LogicException('Assignment permission bypass');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;$checks++;}
    try{$tasks->get($reader,$fixture,$id);throw new LogicException('Foreign task exposed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==404)throw $e;$checks++;}
    $db->table('clubora.access_profiles')->where('id',$profile)->update(['permissions'=>'[]']);try{$bind($reader,$fixture);if(TeamTasks::canAccess())throw new LogicException('Revoked tasks still available');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;}$checks++;
    echo $checks." work-hub DEV checks passed; transaction rolled back, no external delivery.\n";
}catch(Throwable $e){fwrite(STDERR,'Work hub failed at '.$stage.': '.get_class($e).' '.$e->getMessage().PHP_EOL);exit(1);}
finally{if($db->transactionLevel())$db->rollBack();}
