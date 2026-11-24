<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Account,Tenant,AccessProfile,Member};
use App\ClubUnio\Services\{ActiveTenant,ApprovalQueue,MemberTimeline,WorkspacePreferences,WorkspaceLinks,ReactUi};
use App\ClubUnio\Pages\{MyClub,ApprovalCenter,PlanningConflicts};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
$db=DB::connection('clubunio');$stage='bootstrap';
try {
    if (config('app.url')!=='https://dev.clubunio.ch') throw new LogicException('DEV required');
    $db->beginTransaction(); $db->statement("SET LOCAL statement_timeout='30s'"); Http::preventStrayRequests();
    $admin=Account::where('username','davide.peixoto@svsissach.ch')->sole();$tenant=Tenant::where('slug','sv-sissach')->sole();
    if (!$admin->hasUnrestrictedBackofficeAccess($tenant)) throw new LogicException('Administrator required');
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));
    $session=$app['session']->driver();$session->start();
    Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag);
    $bind=function($actor,$club,$path='/my-club') use($app,$session) {
        Filament::auth()->setUser($actor);Filament::setTenant($club,isQuiet:true);
        $request=Illuminate\Http\Request::create('https://dev.clubunio.ch'.$path);
        $request->setUserResolver(fn()=>$actor);$request->setLaravelSession($session);
        $request->setRouteResolver(fn()=>(new Illuminate\Routing\Route('GET',$path,fn()=>null))->name('filament.clubunio.pages.my-club'));
        $app->instance('request',$request);app(ActiveTenant::class)->select($request,$club);Filament::bootCurrentPanel();
        return $request;
    };
    $mode=new class extends ReactUi { public bool $value=true; public function enabled(?int $tenantId=null):bool{return $this->value;} };
    $app->instance(ReactUi::class,$mode); $checks=0;
    if (!in_array('--security', $argv, true)) foreach (['de','en'] as $locale) foreach ([true,false] as $modern) {
        $app->setLocale($locale);$mode->value=$modern;$bind($admin,$tenant);
        foreach ([MyClub::class,ApprovalCenter::class,PlanningConflicts::class] as $page) {
            $html=Livewire\Livewire::mount($page);
            if (!str_contains($html,'cu-workflow') || preg_match('/workspace\.[a-z_]+/',strip_tags($html))) throw new LogicException('Render/translation failed: '.$page);
            echo 'Rendered '.class_basename($page).' '.$locale.' '.($modern?'modern':'classic').PHP_EOL;$checks++;
        }
    }
    $mode->value=true;$app->setLocale('de');$bind($admin,$tenant);
    $stage='preferences';
    $prefs=app(WorkspacePreferences::class);$before=$prefs->read($admin,$tenant);
    $prefs->toggle($admin,$tenant,'members');$prefs->toggle($admin,$tenant,'members');
    if ($prefs->read($admin,$tenant)['favorites']!==$before['favorites']) throw new LogicException('Favourite toggle failed');$checks++;
    $member=App\ClubUnio\Resources\MemberResource::getEloquentQuery()->firstOrFail();
    $prefs->remember($admin,$tenant,'members',$member->id);
    if (!$prefs->recent($admin,$tenant)) throw new LogicException('Recent resolution failed');$checks++;
    $timeline=app(MemberTimeline::class)->entries($admin,$tenant,$member->id);
    view('clubunio.members.timeline',compact('timeline'))->render();$checks++;
    $queues=app(ApprovalQueue::class);
    foreach (array_keys($queues->kinds($admin,$tenant)) as $kind) {
        $rows=$queues->rows($admin,$tenant,$kind,1)['rows'];
        if ($rows) { $preview=$queues->preview($admin,$tenant,$kind,$rows[0]['id']); if (!$preview['revision']) throw new LogicException('Missing revision');$checks++; }
    }
    $stage='saved view';
    $saved=app(App\ClubUnio\Services\SavedViewService::class)->save($admin,$tenant,'members',null,['name'=>'Workflow fixture','is_default'=>false],['version'=>1,'filters'=>[],'search'=>'Workflow fixture query','sort'=>null,'tab'=>null,'columns'=>[]]);
    $bind($admin,$tenant,'/members?savedView='.$saved->id);
    $html=Livewire\Livewire::mount(App\ClubUnio\Resources\MemberResource\Pages\ListMembers::class);
    if(!str_contains($html,'Workflow fixture query'))throw new LogicException('Direct saved view did not apply');$checks++;
    // Real profile permissions in a synthetic tenant; no mocks and no persisted changes.
    $stage='create profile';
    $fixture=Tenant::create(['slug'=>'workflow-'.Str::lower(Str::random(12)),'name'=>'Workflow acceptance']);
    $profileActor=(new Account)->forceFill(['username'=>'workflow-'.Str::random(20),'display_name'=>'Workflow fixture','password'=>bcrypt(Str::random(32)),'active'=>true]);$profileActor->save();
    $profileId=$db->table('clubora.access_profiles')->insertGetId(['tenant_id'=>$fixture->id,'name'=>'Read fixture','active'=>true,'permissions'=>json_encode(['members.view']),'revision'=>1]);

    $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$fixture->id,'account_id'=>$profileActor->id,'role'=>'profile','access_profile_id'=>$profileId]);
    $db->table('clubora.account_roles')->where('code','profile')->update(['active'=>true]);
    $stage='bind profile';
    $bind($profileActor,$fixture);
    if (!MyClub::canAccess() || ApprovalCenter::canAccess()) throw new LogicException('Read-only profile boundary failed');
    $links=app(WorkspaceLinks::class)->available();
    if (!isset($links['members']) || isset($links['expenses']) || app(WorkspaceLinks::class)->quickActions()!==[]) throw new LogicException('Quick-action grant leaked');$checks++;
    $stage='profile home';
    $html=Livewire\Livewire::mount(MyClub::class);if (!str_contains($html,'cu-workflow')) throw new LogicException('Profile home failed');$checks++;
    $stage='foreign timeline';
    try { app(MemberTimeline::class)->entries($profileActor,$fixture,$member->id);throw new LogicException('Foreign timeline exposed'); }
    catch (Illuminate\Database\Eloquent\ModelNotFoundException $expected) {$checks++;}
    $stage='grant approval';
    $db->table('clubora.access_profiles')->where('tenant_id',$fixture->id)->where('id',$profileId)->update(['permissions'=>json_encode(['members.view','member_changes.view','member_changes.approve'])]);$bind($profileActor,$fixture);
    if (!ApprovalCenter::canAccess() || array_keys(app(ApprovalQueue::class)->kinds($profileActor,$fixture))!==['change']) throw new LogicException('Narrow approval grant failed');$checks++;
    $html=Livewire\Livewire::mount(ApprovalCenter::class);if(!str_contains($html,'cu-workflow'))throw new LogicException('Profile approval failed');$checks++;
    $stage='synthetic decision';
    $memberId=$db->table('clubora.members')->insertGetId(['tenant_id'=>$fixture->id,'first_name'=>'Workflow','last_name'=>'Fixture']);
    $statuses=[];foreach(['pending','rejected'] as $state)$statuses[$state]=$db->table('clubora.member_change_statuses')->insertGetId(['tenant_id'=>$fixture->id,'code'=>$state,'name'=>$state,'color'=>'gray']);
    $requestId=$db->table('clubora.member_change_requests')->insertGetId(['tenant_id'=>$fixture->id,'member_id'=>$memberId,'requested_by'=>$profileActor->id,
        'status_id'=>$statuses['pending'],'state'=>'pending','revision'=>1,'member_version'=>str_repeat('0',64),
        'snapshot_encrypted'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['before'=>['first_name'=>'Workflow'],'changes'=>['first_name'=>'Changed']]))]);
    $queue=app(ApprovalQueue::class);
    $preview=$queue->preview($profileActor,$fixture,'change',$requestId);if(count($preview['comparison'])!==1)throw new LogicException('Synthetic comparison missing');$checks++;
    try{$queue->decide($profileActor,$fixture,'change',$requestId,'2','reject','Synthetic review decline');throw new LogicException('Stale decision accepted');}
    catch(Illuminate\Validation\ValidationException $expected){$checks++;}
    $queue->decide($profileActor,$fixture,'change',$requestId,'1','reject','Synthetic review decline');
    $result=$db->table('clubora.member_change_requests')->where('tenant_id',$fixture->id)->where('id',$requestId)->first();
    if($result->state!=='rejected'||$result->revision!==2)throw new LogicException('Domain decision failed');$checks++;
    if($queue->counts($profileActor,$fixture)['change']['count']!==0)throw new LogicException('Queue did not refresh');$checks++;
    $stage='revocation';
    $db->table('clubora.access_profiles')->where('tenant_id',$fixture->id)->where('id',$profileId)->update(['permissions'=>'[]']);
    try { $bind($profileActor,$fixture); if (MyClub::canAccess() || ApprovalCenter::canAccess()) throw new LogicException('Revoked access survived'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $expected) { if($expected->getStatusCode()!==403) throw $expected; }
    $checks++;
    echo $checks." workflow DEV checks passed. DE/EN, both designs, profile restrictions, previews and tenant isolation. Transaction rolled back; only a synthetic rejection executed. No external delivery.\n";
} catch(Throwable $error) { fwrite(STDERR,'Workflow acceptance failed at '.$stage.': '.get_class($error).' '.$error->getMessage().PHP_EOL);exit(1); }
finally {if($db->transactionLevel())$db->rollBack();}
