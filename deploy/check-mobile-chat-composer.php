<?php

namespace ClubUnioRelease;

use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Mobile\ChatService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\{DB,Http,Mail,Queue};
use Illuminate\Support\Str;

/** Shared standalone kernel proof. The test harness may include this class without running the CLI. */
final class ChatComposerProbe
{
    public string $stage='bootstrap';
    private int $checks=0;
    private function expect(bool $condition,string $stage): void
    {
        $this->stage=$stage;
        if (!$condition) throw new \LogicException('Synthetic reaction participant contract failed');
        $this->checks++;
    }
    public function run($app): int
    {
        $db=DB::connection('clubunio');
        if ($db->getDriverName()!=='pgsql' || $db->getDatabaseName()!=='db_acdf8d_dclubora' || $db->transactionLevel()!==0) throw new \LogicException('Standalone reviewed PostgreSQL required');
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        $kernel=$app->make(Kernel::class);
        $call=function(string $method,string $path,string $token,array $body=[],int $expected=200) use($kernel): array {
            $this->stage=$method.' '.$path;
            $request=Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path,$method,[],[],[],
                ['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json',
                    'CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token],json_encode($body,JSON_THROW_ON_ERROR));
            $response=$kernel->handle($request);
            $this->expect($response->getStatusCode()===$expected,$this->stage.' expected'.$expected.' actual'.$response->getStatusCode());
            return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
        };
        $actorIds=[]; $tenantIds=[];
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-chat-composer-probe'");
            $db->statement("SET LOCAL lock_timeout TO '2s'");
            $db->statement("SET LOCAL statement_timeout TO '15s'");
            $tenant=Tenant::create(['slug'=>'chat-composer-'.bin2hex(random_bytes(10)),'name'=>'Synthetic reaction participants']);
            $foreign=Tenant::create(['slug'=>'chat-composer-foreign-'.bin2hex(random_bytes(8)),'name'=>'Foreign synthetic']);
            $tenantIds=[(int)$tenant->id,(int)$foreign->id];
            $actors=[]; $tokens=[]; $sessions=[];
            for($i=0;$i<3;$i++) {
                $actor=new Account; $actor->forceFill(['username'=>'reaction-check-'.bin2hex(random_bytes(12)),
                    'display_name'=>'Synthetic participant '.$i,'password'=>'no-password-login','active'=>true,'api_only'=>false])->save();
                $actors[]=$actor; $actorIds[]=(int)$actor->id;
                // Composite keys deliberately use insert(), never insertGetId().
                $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'admin','portal_enabled'=>true]);
                $session=(string)Str::uuid(); $secret=bin2hex(random_bytes(32)); $sessions[]=$session;
                $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
                    'device_name'=>'Synthetic composer probe','expires_at'=>now()->addMinutes(10)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
                $tokens[]=$session.'.'.$secret;
            }
            $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$foreign->id,'account_id'=>$actors[2]->id,'role'=>'admin','portal_enabled'=>true]);
            $service=app(ChatService::class);
            $chat=$service->ensure($tenant,'direct',null,null,'composer-'.bin2hex(random_bytes(12)));
            foreach(array_slice($actors,0,2) as $actor) $service->table($tenant,'chat_memberships')->insert(['tenant_id'=>$tenant->id,'conversation_id'=>$chat->id,'account_id'=>$actor->id]);
            $this->expect(app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($actors[1],$tenant),'Synthetic other actor can enter');
            $this->expect(app(\App\ClubUnio\Mobile\MobileAccess::class)->canContact($actors[0],$actors[1],$tenant),'Synthetic direct contact authorized');
            $this->expect($service->allowed($actors[0],$tenant,$chat),'Synthetic direct conversation authorized');
            $base='clubs/'.$tenant->id.'/';
            $message=$call('POST',$base.'chats/'.$chat->id.'/messages',$tokens[0],['client_id'=>(string)Str::uuid(),'text'=>'Synthetic participant check'],201)['data'];
            $id=(int)$message['id']; $url=$base.'messages/'.$id.'/reactions'; $mutation=$base.'messages/'.$id.'/reaction';
            $call('POST',$mutation,$tokens[0],['revision'=>1,'emoji'=>'❤️']);
            $call('POST',$mutation,$tokens[1],['revision'=>1,'emoji'=>'👍']);
            $before=$service->table($tenant,'chat_changes')->where('message_id',$id)->count();
            $first=$call('GET',$url.'?limit=1',$tokens[0]);
            $this->expect(count($first['data'])===1 && $first['meta']===['has_more'=>true,'next_after'=>$actorIds[0]],'Bounded first participant page');
            $row=$first['data'][0]; $keys=array_keys($row); sort($keys);
            $this->expect($keys===['account_id','emoji','mine','name'] && $row['account_id']===$actorIds[0]
                && $row['name']==='Synthetic participant 0' && $row['mine']===true && $row['emoji']==='❤️','Names only; own marker exact');
            $second=$call('GET',$url.'?limit=1&after='.$actorIds[0],$tokens[0]);
            $this->expect(count($second['data'])===1 && $second['data'][0]['account_id']===$actorIds[1]
                && $second['data'][0]['mine']===false && !$second['meta']['has_more'],'Second page stable account cursor');
            $empty=$call('GET',$url.'?after='.$actorIds[1],$tokens[0]);
            $this->expect($empty['data']===[] && $empty['meta']['next_after']===$actorIds[1],'Empty page keeps cursor');
            $call('GET',$url.'?limit=51',$tokens[0],[],422);
            $call('GET',$url.'?after=-1',$tokens[0],[],422);
            $call('GET',$url.'?email=true',$tokens[0],[],422);
            $this->expect($service->table($tenant,'chat_reactions')->where('message_id',$id)->count()===2
                && $service->table($tenant,'chat_changes')->where('message_id',$id)->count()===$before,'Participant reads never mutate reactions');
            $call('GET',$url,$tokens[2],[],404);
            $call('GET','clubs/'.$foreign->id.'/messages/'.$id.'/reactions',$tokens[2],[],404);
            $service->table($tenant,'chat_memberships')->where('conversation_id',$chat->id)->where('account_id',$actorIds[1])->update(['joined_after'=>$id]);
            $call('GET',$url,$tokens[1],[],404);
            $service->table($tenant,'chat_memberships')->where('conversation_id',$chat->id)->where('account_id',$actorIds[1])->update(['joined_after'=>0]);
            $call('POST',$base.'messages/'.$id.'/delete',$tokens[0],['revision'=>1]);
            $call('GET',$url,$tokens[0],[],404);
            $next=$call('POST',$base.'chats/'.$chat->id.'/messages',$tokens[0],['client_id'=>(string)Str::uuid(),'text'=>'Synthetic block proof'],201)['data'];
            $nextUrl=$base.'messages/'.$next['id'].'/reactions';
            $call('GET',$nextUrl,$tokens[0]);
            $service->table($tenant,'chat_blocks')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actorIds[1],'other_account_id'=>$actorIds[0],'created_at'=>now()->toIso8601String()]);
            $call('GET',$nextUrl,$tokens[0],[],404);
            $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$actorIds[0])->delete();
            $call('GET',$nextUrl,$tokens[0],[],403);
            $db->table('clubora.mobile_sessions')->where('id',$sessions[0])->update(['revoked_at'=>now()->toIso8601String()]);
            $call('GET',$nextUrl,$tokens[0],[],401);
            $this->expect(Http::recorded()->isEmpty() && Mail::sent(Mailable::class)->isEmpty() && Mail::queued(Mailable::class)->isEmpty(),'No provider or mail');
        } finally { $db->rollBack(); }
        $this->expect($db->transactionLevel()===0 && !$db->table('clubora.tenants')->whereIn('id',$tenantIds)->exists()
            && !$db->table('clubora.accounts')->whereIn('id',$actorIds)->exists(),'Verified synthetic rollback');
        return $this->checks;
    }
}

// Included by local tests only to exercise exactly the same standalone probe class.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')!==realpath(__FILE__)) return;
$probe=null;
try {
    if (PHP_SAPI!=='cli' || array_slice($argv,1)!==['--synthetic-rollback']) throw new \LogicException('Explicit synthetic rollback required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app=require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url')!=='https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')) throw new \LogicException('Exact enabled DEV required');
    foreach(['clubunio-mobile.ai.enabled','clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled','clubunio-mobile.keybox.provider_enabled'] as $flag)
        if (config($flag,false)) throw new \LogicException('External providers must remain closed');
    $probe=new ChatComposerProbe; $checks=$probe->run($app);
    echo "Chat reaction participants: $checks HTTP/privacy checks; fixtures rolled back, no providers.\n";
} catch (\Throwable $error) {
    fwrite(STDERR,'Chat composer gate failed at '.($probe?->stage ?? 'bootstrap').': '.get_class($error).' (private details suppressed).'.PHP_EOL); exit(1);
}
