<?php

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{SocialInsightsClient,SocialInsightsService,SyncWorkService};
use Illuminate\Support\Facades\{DB,Http};

if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
$expected = ['https://dev.clubunio.ch'=>'clubunio_dev','https://stage.clubunio.com'=>'clubunio_stage'][config('app.url')] ?? null;
if (! $expected || ! app()->environment('staging') || $db->selectOne('select current_database() as name')->name!==$expected) exit(1);
Http::preventStrayRequests();
$db->beginTransaction(); $exit=0;
try {
    $suffix = bin2hex(random_bytes(8));
    $tenant = new Tenant; $tenant->forceFill(['slug'=>'insights-'.$suffix,'name'=>'Synthetic Meta test'])->save();
    $other = new Tenant; $other->forceFill(['slug'=>'insights-other-'.$suffix,'name'=>'Synthetic other'])->save();
    $actor = new Account; $actor->forceFill(['username'=>'meta-test-'.$suffix.'@example.invalid',
        'display_name'=>'Synthetic Meta test','active'=>true,'password'=>bcrypt($suffix)])->save();
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'admin']);
    config(['clubunio-social-insights.enabled'=>true,'clubunio-social-insights.test_tenant'=>(string) $tenant->id,
        'clubunio-social-insights.instagram_ids'=>['12345'],'clubunio-social-insights.facebook_ids'=>['23456']]);
    $require = static function (bool $ok,string $step): void { if (! $ok) throw new RuntimeException($step); };
    $denied = static function (Closure $fn): void {
        try { $fn(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) { return; }
        throw new RuntimeException('Expected rejection');
    };
    $service=app(SocialInsightsService::class);
    $page=['id'=>'23456','name'=>'Synthetic Facebook','access_token'=>'synthetic-never-real-token',
        'instagram_business_account'=>['id'=>'12345','username'=>'synthetic-instagram']];
    $ig=$service->connect($actor,$tenant,'instagram',$page,now()->addDays(30)->toIso8601String());
    $fb=$service->connect($actor,$tenant,'facebook',$page,now()->addDays(30)->toIso8601String());
    $require($service->table('connections')->where('tenant_id',$tenant->id)->where('id',$ig)->value('token_encrypted')!==$page['access_token'],'Token not encrypted');
    $denied(fn ()=>$service->enqueue($actor,$other,$ig));
    $denied(fn ()=>$service->connect($actor,$tenant,'facebook',[...$page,'id'=>'99999'],now()->addDays(30)->toIso8601String()));
    Http::fake(function ($request) {
        $path=parse_url($request->url(),PHP_URL_PATH);
        if (str_ends_with($path,'/insights')) return Http::response(['error'=>['code'=>100]],400);
        if (str_ends_with($path,'/media')) return Http::response(['data'=>[['id'=>'34567','caption'=>'Synthetic <script>text</script>',
            'timestamp'=>now()->toIso8601String(),'media_type'=>'IMAGE','permalink'=>'https://www.instagram.com/p/test/', 'like_count'=>0,'comments_count'=>3]]]);
        if (str_ends_with($path,'/published_posts')) return Http::response(['data'=>[['id'=>'23456_34567','message'=>'Synthetic Facebook post',
            'created_time'=>now()->toIso8601String(),'permalink_url'=>'https://www.facebook.com/test/','reactions'=>['summary'=>['total_count'=>2]]]]]);
        $id=basename($path); return Http::response(['id'=>$id,'followers_count'=>20]);
    });
    foreach ([$ig,$fb] as $id) {
        $queued=$service->enqueue($actor,$tenant,$id);
        $require($queued===$service->enqueue($actor,$tenant,$id),'Duplicate pending import');
    }
    $require($service->tick()===2,'Worker did not run both channels');
    $require($service->table('imported_posts')->where('tenant_id',$tenant->id)->count()===2,'Post import incomplete');
    $require($service->table('metric_snapshots')->where('tenant_id',$tenant->id)->count()===2,'Snapshots missing');
    $require(app(SyncWorkService::class)->table()->where('tenant_id',$tenant->id)->where('operation','social_insights')->where('state','succeeded')->count()===2,'Missing success audit');
    $row=$service->table('connections')->where('tenant_id',$tenant->id)->where('id',$ig)->first();
    $service->import($actor,$tenant,$ig,(int) $row->revision);
    $require($service->table('imported_posts')->where('tenant_id',$tenant->id)->count()===2,'Repeat duplicated post');
    $metrics=json_decode($service->table('imported_posts')->where('tenant_id',$tenant->id)->where('connection_id',$ig)->value('metrics'),true);
    $require($metrics['likes']===0 && $metrics['reach']===null,'Missing value confused with zero');
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
    \Filament\Facades\Filament::setTenant($tenant);
    \Illuminate\Support\Facades\Auth::guard('clubunio')->setUser($actor);
    request()->setUserResolver(fn ($guard=null)=>$actor);
    request()->setLaravelSession(app('session')->driver());
    $dashboard=new \App\ClubUnio\Pages\SocialInsights;
    $data=(new ReflectionMethod($dashboard,'getViewData'))->invoke($dashboard);
    $require($data['total']===2 && $data['totals']['likes']['value']===2 && $data['totals']['reach']['value']===null,'Dashboard aggregation incorrect');
    $require(!isset($data['connections'][0]->token_encrypted),'Token exposed to dashboard');
    $dashboard->platform='facebook'; $dashboard->sort='likes'; $dashboard->search='Synthetic';
    $filtered=(new ReflectionMethod($dashboard,'getViewData'))->invoke($dashboard);
    $require($filtered['total']===1 && $filtered['posts'][0]->platform==='facebook','Dashboard filter incorrect');
    $require(Http::recorded()->every(fn ($pair)=>$pair[0]->method()==='GET' && str_starts_with($pair[0]->url(),'https://graph.facebook.com/')),'Unexpected outbound write');
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['*'=>Http::response(['error'=>['code'=>190]],400)]);
    $work=$service->enqueue($actor,$tenant,$ig); $service->tick();
    $require(app(SyncWorkService::class)->table()->where('id',$work)->value('error_code')==='status_401','Missing failure audit');
    $require($service->table('imported_posts')->where('tenant_id',$tenant->id)->count()===2,'Provider failure lost cached posts');
    $service->disconnect($actor,$tenant,$ig);
    $denied(fn ()=>$service->import($actor,$tenant,$ig,(int) $row->revision));
    $require($service->table('connections')->where('id',$ig)->value('token_encrypted')===null,'Disconnected credential retained');
    $require($service->table('metric_snapshots')->where('tenant_id',$tenant->id)->count()===2,'Disconnect lost history');
    $actor->forceFill(['active'=>false])->save();
    $denied(fn ()=>$service->authorize($actor,$tenant));
    $require(Http::recorded()->every(fn ($pair)=>$pair[0]->method()==='GET' && str_starts_with($pair[0]->url(),'https://graph.facebook.com/')),'Unexpected outbound write');
    echo "Social test-account gates, encrypted connections, both imports, snapshots, no duplicates, failure audit and disconnect passed. Rollback only; no real Meta request or publication.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Social acceptance failed: '.get_class($error).' at '.$error->getFile().':'.$error->getLine().' '.$error->getMessage()."\n"); $exit=1;
    foreach (array_slice($error->getTrace(),0,7) as $frame) fwrite(STDERR,($frame['file'] ?? '').':'.($frame['line'] ?? '').' '.($frame['function'] ?? '')."\n");
} finally { $db->rollBack(); }
exit($exit);
