<?php
// HTTP-kernel integration using synthetic accounts; every row is rolled back.
try {
    if (PHP_SAPI !== 'cli' || array_slice($argv,1) !== ['--synthetic-rollback']) throw new LogicException('Explicit rollback mode required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if (config('app.url') !== 'https://dev.clubunio.ch' || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new LogicException('Exact standalone DEV required');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Mail::fake(); Illuminate\Support\Facades\Queue::fake();
    $assert = function ($condition, $stage): void { if (!$condition) throw new LogicException($stage); };
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $call = function ($method, $path, $token, $data = []) use ($kernel): array {
        $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, [], [], [],
            ['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token], json_encode($data));
        $response = $kernel->handle($request);
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    };
    $db->beginTransaction();
    $ids = [];
    try {
        $db->statement("SET LOCAL statement_timeout TO '15s'");
        $tenant = App\ClubUnio\Models\Tenant::query()->firstOrFail();
        $actors = []; $tokens = [];
        for ($i=0; $i<3; $i++) {
            $actor = new App\ClubUnio\Models\Account;
            $actor->forceFill(['username'=>'chat-check-'.bin2hex(random_bytes(12)), 'display_name'=>'Synthetic chat check',
                'password'=>'no-password-login', 'active'=>true, 'api_only'=>false])->save();
            $ids[] = $actor->id; $actors[] = $actor;
            $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'admin','portal_enabled'=>true]);
            $session=(string)Illuminate\Support\Str::uuid(); $secret=bin2hex(random_bytes(32));
            $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
                'device_name'=>'Synthetic chat check','expires_at'=>now()->addMinutes(10)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
            $tokens[]=$session.'.'.$secret;
        }
        $prefix='clubs/'.$tenant->id.'/';
        [$status,$defaults]=$call('GET',$prefix.'chat-preferences',$tokens[0]);
        $assert($status===200 && $defaults['data']===['revision'=>0,'translation_enabled'=>false,'target_language'=>'de'],'Default preferences');
        $settings=['revision'=>0,'translation_enabled'=>true,'target_language'=>'fr'];
        [$status,$saved]=$call('POST',$prefix.'chat-preferences',$tokens[0],$settings);
        $assert($status===200 && $saved['data']['revision']===1,'Save preference');
        [$status]=$call('POST',$prefix.'chat-preferences',$tokens[0],$settings); $assert($status===409,'Stale preference');
        [$status,$other]=$call('GET',$prefix.'chat-preferences',$tokens[1]); $assert($status===200 && !$other['data']['translation_enabled'],'Account preference isolation');
        $service=app(App\ClubUnio\Mobile\ChatService::class);
        $chat=$service->ensure($tenant,'direct',null,null,'synthetic-'.bin2hex(random_bytes(12)));
        foreach(array_slice($actors,0,2) as $actor) $service->table($tenant,'chat_memberships')->insert(['tenant_id'=>$tenant->id,'conversation_id'=>$chat->id,'account_id'=>$actor->id]);
        [$status,$message]=$call('POST',$prefix.'chats/'.$chat->id.'/messages',$tokens[0],['client_id'=>(string)Illuminate\Support\Str::uuid(),'text'=>'Synthetic reaction check']);
        $assert($status===201,'Send message'); $id=$message['data']['id']; $url=$prefix.'messages/'.$id.'/reaction';
        [$status,$reaction]=$call('POST',$url,$tokens[1],['revision'=>1,'emoji'=>'👍']);
        $assert($status===200 && $reaction['data']['revision']===1 && $reaction['data']['reactions']===[['emoji'=>'👍','count'=>1,'mine'=>true]],'Persist reaction');
        $count=$service->table($tenant,'chat_changes')->where('message_id',$id)->count();
        [$status]=$call('POST',$url,$tokens[1],['revision'=>1,'emoji'=>'👍']);
        $assert($status===200 && $service->table($tenant,'chat_changes')->where('message_id',$id)->count()===$count,'Desired-state idempotency');
        [$status,$changes]=$call('GET',$prefix.'chats/'.$chat->id.'/changes?after=0',$tokens[0]);
        $assert($status===200 && $changes['data'][0]['message']['reactions'][0]['count']===1 && !$changes['data'][0]['message']['reactions'][0]['mine'],'Reaction change feed');
        [$status]=$call('POST',$url,$tokens[2],['revision'=>1,'emoji'=>'👍']); $assert($status===404,'Unrelated direct conversation denied');
        [$status]=$call('POST',$url,$tokens[1],['revision'=>2,'emoji'=>'👍']); $assert($status===409,'Stale text denied');
        [$status,$removed]=$call('POST',$url,$tokens[1],['revision'=>1,'emoji'=>null]); $assert($status===200 && $removed['data']['reactions']===[],'Remove own reaction');
        [$status,$freshChat]=$call('GET',$prefix.'chats/'.$chat->id,$tokens[0]);
        $assert($status===200 && $freshChat['data']['chat_preferences']['target_language']==='fr' && !$freshChat['data']['can_link_event'],'Fresh conversation preferences');
        $assert(Illuminate\Support\Facades\Http::recorded()->isEmpty(),'No provider call');
        $assert(Illuminate\Support\Facades\Mail::sent(Illuminate\Mail\Mailable::class)->isEmpty()
            && Illuminate\Support\Facades\Mail::queued(Illuminate\Mail\Mailable::class)->isEmpty(),'No mail');
    } finally { $db->rollBack(); }
    $assert(!$db->table('clubora.accounts')->whereIn('id',$ids)->exists() && $db->transactionLevel()===0,'Verified fixture rollback');
    echo "Chat settings/reactions/change feed/privacy HTTP checks passed; fixtures rolled back; no providers.\n";
} catch (Throwable $error) { fwrite(STDERR,'Chat experience gate: '.get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); }
