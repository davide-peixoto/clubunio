<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{ActiveTenant,BackofficeReadContext,DashboardService,ReactUi,WorkNotificationHub};
use Illuminate\Support\Facades\{DB,Http,Mail,Queue,Crypt};
use Filament\Facades\Filament;
$db=DB::connection('clubunio');
if(config('app.url')!=='https://dev.clubunio.ch'||$db->getDatabaseName()!=='clubunio_dev')throw new LogicException('DEV only');
Http::preventStrayRequests();Mail::fake();Queue::fake();
$db->beginTransaction();$db->statement("SET LOCAL statement_timeout='30s'");
$queries=[];$result=[];
$db->listen(function($query)use(&$queries){$queries[]=$query->time;});
try{
    $actor=Account::where('username','davide.peixoto@svsissach.ch')->sole();$tenant=Tenant::where('slug','sv-sissach')->sole();
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));Filament::auth()->setUser($actor);Filament::setTenant($tenant,isQuiet:true);
    config(['session.driver'=>'array']);$session=app('session')->driver();$session->start();Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag);
    $bind=function()use($app,$actor,$tenant,$session){$r=Illuminate\Http\Request::create('https://dev.clubunio.ch/');$r->setUserResolver(fn()=>$actor);$r->setLaravelSession($session);$r->setRouteResolver(fn()=>(new Illuminate\Routing\Route('GET','/',fn()=>null))->name('filament.clubunio.pages.dashboard'));$app->instance('request',$r);app(ActiveTenant::class)->select($r,$tenant);Filament::bootCurrentPanel();return $r;};
    $measure=function($name,$callback)use(&$result,&$queries,$bind){
        $samples=[];
        for($i=0;$i<6;$i++){$request=$bind();$queries=[];$start=hrtime(true);$value=app(BackofficeReadContext::class)->during($request,fn()=>$callback());$ms=(hrtime(true)-$start)/1e6;if($i)$samples[]=['ms'=>round($ms,2),'queries'=>count($queries),'db_ms'=>round(array_sum($queries),2)];}
        $times=array_column($samples,'ms');sort($times);$result[$name]=['median_ms'=>$times[2],'samples'=>$samples];
    };
    $mode=new class extends ReactUi{public bool $value=true;public function enabled(?int $id=null):bool{return $this->value;}};$app->instance(ReactUi::class,$mode);
    $bind();
    $service=app(DashboardService::class);
    $list=$service->scopeOpenInvoices(App\ClubUnio\Models\Invoice::where('tenant_id',$tenant->id)->where('active',true),$tenant);
    $reference=$db->query()->fromSub($list->toBase(),'legacy_totals')->selectRaw('count(*) as total,COALESCE(sum(open_amount),0) as amount')->first();
    $summary=$service->openSummary($actor,$tenant);
    if((int)$reference->total!==$summary['count']||abs((float)$reference->amount-(float)$summary['amount'])>.000001)throw new LogicException('Summary differs from native list');
    $result['summary_matches_native_list']=true;
    foreach([false,true] as $modern){$mode->value=$modern;$measure('dashboard_'.($modern?'react':'classic'),fn()=>Livewire\Livewire::mount(App\ClubUnio\Pages\Dashboard::class));}
    $measure('overview',fn()=>app(DashboardService::class)->overview($actor,$tenant));
    $measure('unread_actual',fn()=>app(WorkNotificationHub::class)->unreadCount($actor,$tenant));
    for($i=0;$i<24;$i++){
        $id=$db->table('clubora.workspace_tasks')->insertGetId(['tenant_id'=>$tenant->id,'created_by'=>$actor->id,'assigned_to'=>$actor->id,'title_encrypted'=>Crypt::encryptString('Performance rollback fixture'),'body_encrypted'=>Crypt::encryptString(''),'state'=>'open','revision'=>1]);
        $db->table('clubora.notifications')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'object_type'=>'workspace_task','object_id'=>$id,'title'=>'Performance rollback fixture','body'=>'']);
    }
    $measure('unread_24_tasks',fn()=>app(WorkNotificationHub::class)->unreadCount($actor,$tenant));
    $result['assets']=[];
    foreach(['react-navigation','dashboard','facility-planner'] as $entry){$bytes=file_get_contents(public_path('js/clubunio-'.$entry.'.js'));$result['assets'][$entry]=['raw'=>strlen($bytes),'gzip'=>strlen(gzencode($bytes,6))];}
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);foreach(array_slice($error->getTrace(),0,12) as $frame)fwrite(STDERR,($frame['class']??'').'::'.($frame['function']??'').' '.basename($frame['file']??''). ':' .($frame['line']??0).PHP_EOL);$failed=true;}finally{$db->rollBack();}if($failed??false)exit(1);
