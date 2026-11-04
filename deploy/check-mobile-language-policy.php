<?php

namespace ClubUnioRelease;

use App\ClubUnio\Mobile\{ChatService,MobileLanguages};
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\{DB,Http,Mail,Queue};
use Illuminate\Support\Str;

final class MobileLanguagePolicyProbe
{
    public string $stage='bootstrap';
    private int $checks=0;
    private function expect(bool $condition,string $stage): void
    {
        $this->stage=$stage;
        if (!$condition) throw new \LogicException('Synthetic language policy contract failed');
        $this->checks++;
    }
    public function run($app): int
    {
        $db=DB::connection('clubunio');
        if ($db->getDriverName()!=='pgsql' || $db->getDatabaseName()!=='db_acdf8d_dclubora' || $db->transactionLevel()!==0) throw new \LogicException('Standalone reviewed PostgreSQL required');
        $policy=app(MobileLanguages::class)->policy();
        $this->expect($policy===['enabled'=>['de','en'],'default'=>'de'],'Actual central DE/EN release policy');
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
        $ids=[]; $tenantId=null; $originalAi=config('clubunio-mobile.ai.enabled');
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-mobile-language-policy-probe'");
            $db->statement("SET LOCAL lock_timeout TO '2s'"); $db->statement("SET LOCAL statement_timeout TO '15s'");
            $tenant=Tenant::create(['slug'=>'language-policy-'.bin2hex(random_bytes(10)),'name'=>'Synthetic language policy']); $tenantId=(int)$tenant->id;
            $actors=[]; $tokens=[];
            for($i=0;$i<2;$i++) {
                $actor=new Account; $actor->forceFill(['username'=>'language-policy-'.bin2hex(random_bytes(12)),
                    'display_name'=>'Synthetic language '.$i,'password'=>'no-password-login','active'=>true,'api_only'=>false])->save();
                $actors[]=$actor; $ids[]=(int)$actor->id;
                $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenantId,'account_id'=>$actor->id,'role'=>'admin','portal_enabled'=>true]);
                $session=(string)Str::uuid(); $secret=bin2hex(random_bytes(32));
                $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
                    'device_name'=>'Synthetic language policy','expires_at'=>now()->addMinutes(10)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
                $tokens[]=$session.'.'.$secret;
            }
            $base='clubs/'.$tenantId.'/'; $preferences=$base.'chat-preferences';
            foreach(['context','profile'] as $path) {
                $data=$call('GET',$path,$tokens[0])['data'];
                $this->expect($data['language']==='de' && $data['language_policy']===$policy && !$data['language_fallback'],'Default policy in '.$path);
            }
            $db->table('clubora.mobile_preferences')->insert(['account_id'=>$ids[0],'language'=>'fr']);
            $db->table('clubora.chat_preferences')->insert(['tenant_id'=>$tenantId,'account_id'=>$ids[0],'translation_enabled'=>true,'target_language'=>'fr','revision'=>7]);
            $before=$db->table('clubora.chat_preferences')->where('tenant_id',$tenantId)->where('account_id',$ids[0])->first();
            foreach(['context','profile'] as $path) {
                $data=$call('GET',$path,$tokens[0])['data'];
                $this->expect($data['language']==='de' && $data['language_fallback'] && $data['language_policy']===$policy,'Disabled saved preference fallback in '.$path);
            }
            $chat=$call('GET',$preferences,$tokens[0])['data'];
            $this->expect($chat['target_language']==='de' && !$chat['translation_enabled'] && $chat['language_fallback']
                && $chat['revision']===7 && $chat['language_policy']===$policy,'Disabled chat target never inherits opt-in');
            $service=app(ChatService::class); $conversation=$service->ensure($tenant,'direct',null,null,'language-'.bin2hex(random_bytes(10)));
            foreach($actors as $actor) $service->table($tenant,'chat_memberships')->insert(['tenant_id'=>$tenantId,'conversation_id'=>$conversation->id,'account_id'=>$actor->id]);
            $dto=$call('GET',$base.'chats/'.$conversation->id,$tokens[0])['data'];
            $this->expect($dto['chat_preferences']===$chat,'Conversation shares the same effective policy');
            $after=$db->table('clubora.chat_preferences')->where('tenant_id',$tenantId)->where('account_id',$ids[0])->first();
            $this->expect((array)$before===(array)$after && $db->table('clubora.mobile_preferences')->where('account_id',$ids[0])->value('language')==='fr','GET never mutates saved disabled language or consent');
            $call('POST','preferences',$tokens[0],['language'=>'fr'],422);
            $call('POST','preferences',$tokens[0],['language'=>'pt-PT'],422);
            $call('POST',$preferences,$tokens[0],['revision'=>7,'translation_enabled'=>true,'target_language'=>'fr'],422);
            // Process-local AI flag only to prove rejection before provider/reservation.
            // The actual language policy is never changed by this gate.
            config(['clubunio-mobile.ai.enabled'=>true]);
            foreach(['translate','friendly'] as $operation) $call('POST',$base.'ai',$tokens[0],
                ['request_key'=>(string)Str::uuid(),'operation'=>$operation,'language'=>'fr','text'=>'Synthetic private draft','consent'=>true],422);
            config(['clubunio-mobile.ai.enabled'=>$originalAi]);
            $this->expect(!$db->table('clubora.mobile_ai_work')->where('account_id',$ids[0])->exists(),'Disabled language reserves no AI work');
            $saved=$call('POST','preferences',$tokens[0],['language'=>'en'])['data'];
            $this->expect($saved['language']==='en' && !$saved['language_fallback'] && $saved['language_policy']===$policy,'Allowed account preference save');
            $other=$call('GET','context',$tokens[1])['data']; $this->expect($other['language']==='de','Account language isolation');
            $saved=$call('POST',$preferences,$tokens[0],['revision'=>7,'translation_enabled'=>false,'target_language'=>'en'])['data'];
            $this->expect($saved['target_language']==='en' && !$saved['translation_enabled'] && !$saved['language_fallback']
                && $saved['revision']===8 && $saved['language_policy']===$policy,'Explicit allowed chat save without automatic consent');
            $this->expect(Http::recorded()->isEmpty() && Mail::sent(Mailable::class)->isEmpty() && Mail::queued(Mailable::class)->isEmpty(),'No provider or mail');
        } finally { $db->rollBack(); config(['clubunio-mobile.ai.enabled'=>$originalAi]); }
        $this->expect($db->transactionLevel()===0 && !$db->table('clubora.tenants')->where('id',$tenantId)->exists()
            && !$db->table('clubora.accounts')->whereIn('id',$ids)->exists(),'Verified synthetic rollback');
        return $this->checks;
    }
}

if (defined('CLUBUNIO_MOBILE_LANGUAGE_POLICY_INCLUDE_ONLY') && constant('CLUBUNIO_MOBILE_LANGUAGE_POLICY_INCLUDE_ONLY')===true) return;
$probe=null;
try {
    if (PHP_SAPI!=='cli' || array_slice($argv,1)!==['--synthetic-rollback']) throw new \LogicException('Explicit synthetic rollback required');
    require dirname(__DIR__).'/vendor/autoload.php'; $app=require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url')!=='https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')) throw new \LogicException('Exact enabled DEV required');
    foreach(['clubunio-mobile.ai.enabled','clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled','clubunio-mobile.keybox.provider_enabled'] as $flag)
        if (config($flag,false)) throw new \LogicException('External providers must remain closed');
    $probe=new MobileLanguagePolicyProbe; $checks=$probe->run($app);
    echo "Central mobile DE/EN language policy: $checks checks; synthetic rollback, no providers.\n";
} catch (\Throwable $error) {
    fwrite(STDERR,'Language policy gate failed at '.($probe?->stage ?? 'bootstrap').': '.get_class($error).' (private details suppressed).'.PHP_EOL); exit(1);
}
