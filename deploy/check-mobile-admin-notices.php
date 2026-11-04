<?php

// DEV only. Existing schemas only; every synthetic write is rolled back. No PHPUnit dependency.
$db = null; $stage = 'arguments'; $checks = 0;
try {
    if (PHP_SAPI !== 'cli' || !in_array('--synthetic-rollback',$argv,true)
        || count(array_intersect(['--foundation','--enabled'],$argv)) !== 1
        || array_diff(array_slice($argv,1),['--synthetic-rollback','--foundation','--enabled'])) throw new LogicException('Explicit stage and rollback required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled = in_array('--enabled',$argv,true);
    if (rtrim((string)config('app.url'),'/') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')
        || (bool)config('clubunio-mobile.native_push_admin_enabled',false) !== $enabled
        || config('clubunio-mobile-push.delivery_enabled',false)) throw new LogicException('Exact DEV stage with provider delivery closed required');
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new LogicException('Standalone reviewed PostgreSQL required');
    $routes = 0;
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (!str_starts_with($route->getActionName(),App\ClubUnio\Mobile\MobileAdminNoticeController::class.'@')) continue;
        foreach ([App\ClubUnio\Mobile\MobileMiddleware::class,App\ClubUnio\Mobile\MobileAdminNoticeReady::class] as $middleware)
            if (!in_array($middleware,$route->gatherMiddleware(),true)) throw new LogicException('Missing real route guard');
        $routes++;
    }
    if ($routes !== 6) throw new LogicException('Six exact personal notice routes required');
    if ($enabled && (!$db->table('clubora.migrations')->where('migration','2026_11_04_001000_mobile_admin_notices')->exists()
        || !$db->getSchemaBuilder()->hasColumn('clubora.mobile_admin_notices','published_at')
        || !$db->getSchemaBuilder()->hasColumn('clubora.mobile_admin_notice_recipients','notice_id'))) throw new LogicException('Reviewed existing1000 schema required');
    Illuminate\Support\Facades\Http::preventStrayRequests(); Illuminate\Support\Facades\Mail::fake(); Illuminate\Support\Facades\Queue::fake();
    app()->bind(App\ClubUnio\Mobile\MobilePushFcm::class,fn()=>throw new LogicException('Provider forbidden in synthetic gate'));
    config(['cache.default'=>'array']); $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $expect = function($actual,$expected,string $label) use (&$checks,&$stage) { $stage=$label; if ($actual !== $expected) throw new LogicException('Contract mismatch'); $checks++; };
    $call = function(string $method,string $path,array $body=[],?string $token=null) use ($kernel,&$stage) {
        $stage=$method.' '.preg_replace('/[0-9]+/','id',$path);
        $server=['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'];
        if ($token) $server['HTTP_AUTHORIZATION']='Bearer '.$token;
        $response=$kernel->handle(Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path,$method,[],[],[],$server,json_encode($body,JSON_THROW_ON_ERROR)));
        return [$response->getStatusCode(),json_decode($response->getContent(),true,64,JSON_THROW_ON_ERROR)];
    };
    $db->beginTransaction();
    $db->statement("SET LOCAL application_name TO 'clubunio-admin-notices-release-rollback'");
    $tenant=App\ClubUnio\Models\Tenant::create(['slug'=>'qa-notice-'.bin2hex(random_bytes(6)),'name'=>'Synthetic notice club']);
    $tokens=[]; $actors=[]; $password=bin2hex(random_bytes(16));
    foreach (['admin','member'] as $role) {
        $actor=new App\ClubUnio\Models\Account;
        $actor->forceFill(['username'=>'qa-notice-'.bin2hex(random_bytes(8)),'display_name'=>'Synthetic '.$role,
            'active'=>true,'api_only'=>false,'password'=>Illuminate\Support\Facades\Hash::make($password)])->save();
        $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>$role,'portal_enabled'=>true]);
        $member=$db->table('clubora.members')->insertGetId(['tenant_id'=>$tenant->id,'first_name'=>'Synthetic','last_name'=>$role,'birth_date'=>'1980-01-01']);
        $db->table('clubora.account_member_links')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic-release']);
        [$status,$reply]=$call('POST','session',['username'=>$actor->username,'password'=>$password,'device_name'=>'Synthetic notice gate']);
        $expect($status,200,'synthetic login'); $tokens[$role]=$reply['data']['token']; $actors[$role]=$actor;
    }
    $base='clubs/'.$tenant->id; $paths=['push-admin/options','push-admin/lookup?type=account','push-admin/notices/1','admin-notices/1'];
    if (!$enabled) {
        foreach ($paths as $path) { [$status]=$call('GET',$base.'/'.$path,[],$tokens['admin']); $expect($status,503,'closed GET'); }
        foreach (['preview','notices'] as $path) { [$status]=$call('POST',$base.'/push-admin/'.$path,[],$tokens['admin']); $expect($status,503,'closed POST'); }
    } else {
        [$status]=$call('GET',$base.'/push-admin/options',[],$tokens['member']); $expect($status,403,'ordinary cannot send');
        [$status,$reply]=$call('GET',$base.'/push-admin/options',[],$tokens['admin']); $expect($status,200,'admin options');
        $expect($reply['data']['tenantId'],(int)$tenant->id,'actual tenant');
        $payload=['target_type'=>'account','target_value'=>(string)$actors['member']->id,'title'=>'Synthetic private title','body'=>'Synthetic private text'];
        [$status,$preview]=$call('POST',$base.'/push-admin/preview',$payload,$tokens['admin']); $expect($status,200,'real preview');
        $expect($preview['data']['recipientCount'],1,'current recipient');
        $command=$payload+['request_key'=>(string)Illuminate\Support\Str::uuid(),'revision'=>$preview['data']['revision'],'confirm'=>true];
        [$status,$receipt]=$call('POST',$base.'/push-admin/notices',$command,$tokens['admin']); $expect($status,200,'durable source');
        $id=$receipt['data']['noticeId'];
        [$status,$replay]=$call('POST',$base.'/push-admin/notices',$command,$tokens['admin']); $expect($status,200,'explicit replay'); $expect($replay['data']['noticeId'],$id,'one source');
        [$status]=$call('POST',$base.'/push-admin/notices',array_replace($command,['body'=>'Changed']),$tokens['admin']); $expect($status,409,'changed payload rejected');
        [$status,$content]=$call('GET',$base.'/admin-notices/'.$id,[],$tokens['member']); $expect($status,200,'recipient private content'); $expect($content['data']['body'],$payload['body'],'exact source text');
        [$status]=$call('GET',$base.'/admin-notices/'.$id,[],$tokens['admin']); $expect($status,404,'sender is not implicit recipient');
        $source=$db->table('clubora.mobile_admin_notices')->where('id',$id)->first();
        $expect(str_contains($source->body_encrypted,'Synthetic'),false,'encrypted source');
        $expect(app(App\ClubUnio\Mobile\MobilePushOutbox::class)->deliverOne(),false,'delivery remains closed');
        $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$actors['admin']->id)->update(['role'=>'member']);
        $expect(app(App\ClubUnio\Mobile\MobileAdminNoticeAccess::class)->publicationCurrent($tenant,$id),false,'sender authority freshly revoked');
        [$status]=$call('GET',$base.'/push-admin/notices/'.$id,[],$tokens['admin']); $expect($status,403,'revoked sender receipt');
    }
    if (Illuminate\Support\Facades\Http::recorded()->isNotEmpty()
        || Illuminate\Support\Facades\Mail::sent(Illuminate\Mail\Mailable::class)->isNotEmpty()
        || Illuminate\Support\Facades\Mail::queued(Illuminate\Mail\Mailable::class)->isNotEmpty()) throw new LogicException('Unexpected external request or mail');
    $db->rollBack();
    echo "Personal admin notices ".($enabled?'enabled':'closed foundation')." verified: $checks HTTP/source checks, synthetic rollback, no provider action.\n";
} catch (Throwable $error) {
    if ($db && $db->transactionLevel()>0) $db->rollBack();
    fwrite(STDERR,'Admin-notice gate failed at '.$stage.': '.get_class($error)." (private values suppressed).\n"); exit(1);
}
