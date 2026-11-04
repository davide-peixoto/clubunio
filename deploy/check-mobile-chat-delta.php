<?php

namespace ClubUnioRelease;

use App\ClubUnio\Mobile\{ChatControls,ChatService};
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail,Queue};
use Illuminate\Support\Str;

final class MobileChatDeltaProbe
{
    public string $stage='bootstrap';
    public int $idleQueries=0;
    private int $checks=0;
    private function expect(bool $condition,string $stage): void
    {
        $this->stage=$stage;
        if (!$condition) throw new \LogicException('Synthetic chat delta contract failed');
        $this->checks++;
    }
    public function run($app): int
    {
        $db=DB::connection('clubunio');
        if ($db->getDriverName()!=='pgsql' || $db->getDatabaseName()!=='db_acdf8d_dclubora' || $db->transactionLevel()!==0) throw new \LogicException('Standalone reviewed PostgreSQL required');
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        $kernel=$app->make(Kernel::class); $ids=[]; $tenantId=null; $foreign=null;
        $uploads=config('clubunio-mobile.uploads_enabled'); $originalNow=\Carbon\Carbon::getTestNow();
        $call=function(string $method,string $path,string $token,array $body=[],int $expected=200) use($kernel): array {
            $this->stage=$method.' '.$path;
            $r=Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path,$method,[],[],[],
                ['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json',
                    'CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token],json_encode($body,JSON_THROW_ON_ERROR));
            $response=$kernel->handle($r);
            $this->expect($response->getStatusCode()===$expected,$this->stage.' expected'.$expected.' actual'.$response->getStatusCode());
            return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
        };
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-chat-delta-rollback'");
            $db->statement("SET LOCAL lock_timeout TO '2s'"); $db->statement("SET LOCAL statement_timeout TO '15s'");
            $tenant=Tenant::create(['slug'=>'chat-delta-'.bin2hex(random_bytes(10)),'name'=>'Synthetic chat delta']); $tenantId=(int)$tenant->id;
            $actors=[]; $tokens=[];
            for($i=0;$i<3;$i++) {
                $a=new Account; $a->forceFill(['username'=>'chat-delta-'.bin2hex(random_bytes(12)), 'display_name'=>'Synthetic delta '.$i,
                    'password'=>'not-an-authentication-fixture','active'=>true,'api_only'=>false])->save();
                $actors[]=$a; $ids[]=(int)$a->id;
                $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenantId,'account_id'=>$a->id,'role'=>'admin','portal_enabled'=>true]);
                $session=(string)Str::uuid(); $secret=bin2hex(random_bytes(32));
                $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$a->id,'token_hash'=>hash('sha256',$secret),
                    'device_name'=>'Synthetic chat delta','expires_at'=>now()->addHours(2)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
                $tokens[]=$session.'.'.$secret;
            }
            $s=app(ChatService::class); $chat=$s->direct($actors[0],$tenant,$actors[1]->id);
            $base='clubs/'.$tenantId.'/'; $path=$base.'chats/'.$chat->id;
            $snapshot=$call('GET',$path.'/messages',$tokens[0]);
            $meta=$snapshot['meta'];
            $this->expect($meta['delta_version']===1 && $meta['joined_after']===0 && $meta['can_write'] && !$meta['can_moderate']
                && is_string($meta['server_time']) && $snapshot['data']===[],'Initial empty snapshot has explicit delta contract');
            $send=fn(string $text)=>$call('POST',$path.'/messages',$tokens[1],['client_id'=>(string)Str::uuid(),'text'=>$text],201)['data'];
            $first=$send('Synthetic delta creation');
            $page=$call('GET',$path.'/changes?after='.$meta['change_cursor'],$tokens[0]);
            $this->expect(count($page['data'])===1 && $page['data'][0]['message']['id']===$first['id'] && !$page['meta']['reset_required'],'Real creation is in delta feed');
            $cursor=$page['meta']['cursor'];
            $db->flushQueryLog(); $db->enableQueryLog();
            try { $idle=$call('GET',$path.'/changes?after='.$cursor,$tokens[0]); $queries=$db->getQueryLog(); }
            finally { $db->disableQueryLog(); $db->flushQueryLog(); }
            $this->idleQueries=count($queries);
            $dtoReads=array_filter($queries,fn($q)=>str_contains($q['query'],'from "clubora"."chat_messages"')
                ||str_contains($q['query'],'from "clubora"."chat_reactions"')||str_contains($q['query'],'from "clubora"."chat_attachments"')
                ||str_contains($q['query'],'from "clubora"."chat_votes"'));
            $this->expect($idle['data']===[] && $idle['meta']['cursor']===$cursor && !$dtoReads,'Idle checks fresh scope without any message/attachment/reaction/vote DTO query');
            $call('POST',$base.'messages/'.$first['id'].'/edit',$tokens[1],['revision'=>1,'text'=>'Synthetic edit']);
            $call('POST',$base.'messages/'.$first['id'].'/reaction',$tokens[0],['revision'=>2,'emoji'=>'👍']);
            $changed=$call('GET',$path.'/changes?after='.$cursor,$tokens[0]);
            $this->expect(count($changed['data'])===2 && $changed['data'][0]['message']['revision']===2
                && $changed['data'][1]['message']['revision']===2 && $changed['data'][0]['message']['reactions'][0]['count']===1,'Same-revision reaction and earlier edit events project current DTO');
            $repeat=$call('GET',$path.'/changes?after='.$cursor,$tokens[0]);
            $this->expect($repeat['data']===$changed['data'],'Replay of old event cursors cannot resurrect an older message revision');
            $cursor=$changed['meta']['cursor'];
            $poll=$call('POST',$path.'/messages',$tokens[0],['client_id'=>(string)Str::uuid(),'text'=>'Synthetic poll','kind'=>'poll',
                'poll'=>['options'=>['Yes','No'],'multiple'=>false,'closes_at'=>now()->addMinutes(10)->toIso8601String()]],201)['data'];
            $choice=$poll['poll']['options'][0]['id'];
            $call('POST',$base.'polls/'.$poll['poll']['id'].'/vote',$tokens[1],['choices'=>[$choice]]);
            $voted=$call('GET',$path.'/changes?after='.$cursor,$tokens[0]);
            $this->expect(count($voted['data'])===2 && $voted['data'][1]['message']['revision']===1
                && $voted['data'][1]['message']['poll']['options'][0]['count']===1,'Vote changes content without incrementing message revision');
            $call('POST',$base.'messages/'.$poll['id'].'/delete',$tokens[0],['revision'=>1]);
            $deleted=$call('GET',$path.'/changes?after='.$voted['meta']['cursor'],$tokens[0]);
            $this->expect($deleted['data'][0]['message']['deleted'] && $deleted['data'][0]['message']['poll']===null,'Delete is an explicit delta tombstone');
            config(['clubunio-mobile.uploads_enabled'=>true]); // Synthetic clean fixture only; no scanner/provider.
            $attachment=(string)Str::uuid(); $bytes="%PDF-1.4\n%%EOF\n";
            $db->table('clubora.chat_attachments')->insert(['id'=>$attachment,'tenant_id'=>$tenantId,'conversation_id'=>$chat->id,
                'account_id'=>$actors[1]->id,'client_id'=>(string)Str::uuid(),'filename_encrypted'=>Crypt::encryptString('Synthetic.pdf'),
                'bytes_encrypted'=>Crypt::encryptString($bytes),'sha256'=>hash('sha256',$bytes),'byte_count'=>strlen($bytes),
                'mime_type'=>'application/pdf','state'=>'clean','scanner_version'=>'synthetic','scanned_at'=>now()->toIso8601String()]);
            $attached=$call('POST',$path.'/messages',$tokens[1],['client_id'=>(string)Str::uuid(),'text'=>'Synthetic attachment','attachment_id'=>$attachment],201)['data'];
            $withFile=$call('GET',$path.'/changes?after='.$deleted['meta']['cursor'],$tokens[0]);
            $this->expect($withFile['data'][0]['message']['attachment']['id']===$attachment,'Send records delta only after clean attachment binding');
            $call('POST',$base.'messages/'.$attached['id'].'/delete',$tokens[1],['revision'=>1]);
            $without=$call('GET',$path.'/changes?after='.$withFile['meta']['cursor'],$tokens[0]);
            $this->expect($without['data'][0]['message']['attachment']===null && $db->table('clubora.chat_attachments')->where('id',$attachment)->value('bytes_encrypted')===null,'Attachment removal follows message tombstone');
            config(['clubunio-mobile.uploads_enabled'=>$uploads]);
            $ownerSnapshot=$call('GET',$path.'/messages',$tokens[1]);
            $ownerRow=current(array_filter($ownerSnapshot['data'],fn($m)=>$m['id']===$first['id']));
            $this->expect($ownerRow['can_edit'],'Own initial edit window open');
            \Carbon\Carbon::setTestNow(now()->addMinutes(31));
            $clock=$call('GET',$path.'/changes?after='.$ownerSnapshot['meta']['change_cursor'],$tokens[1]);
            $this->expect($clock['data']===[] && \Carbon\CarbonImmutable::parse($clock['meta']['server_time'])->gt(\Carbon\CarbonImmutable::parse($first['created_at'])->addMinutes(30)),
                'Idle server time refreshes edit and poll deadlines without a DTO reload');
            $call('POST',$base.'messages/'.$first['id'].'/edit',$tokens[1],['revision'=>2,'text'=>'Expired edit'],409);
            \Carbon\Carbon::setTestNow($originalNow);
            // Permission-only changes must be visible even with an empty event feed.
            $announcements=$s->ensure($tenant,'announcements');
            $announcementPath=$base.'chats/'.$announcements->id;
            $adminScope=$call('GET',$announcementPath.'/messages',$tokens[0]);
            $this->expect($adminScope['meta']['can_write'] && $adminScope['meta']['can_moderate'],'Announcement admin initial capabilities');
            $db->table('clubora.tenant_accounts')->where('tenant_id',$tenantId)->where('account_id',$actors[0]->id)->update(['role'=>'office']);
            $officeScope=$call('GET',$announcementPath.'/changes?after='.$adminScope['meta']['change_cursor'],$tokens[0]);
            $this->expect($officeScope['data']===[] && !$officeScope['meta']['can_write'] && !$officeScope['meta']['can_moderate'],'Empty delta refreshes revoked moderator/write capability');
            $db->table('clubora.tenant_accounts')->where('tenant_id',$tenantId)->where('account_id',$actors[0]->id)->update(['role'=>'admin']);
            $seed=function(string $text,?object $target=null) use($s,$tenant,$actors): object {
                $target ??= $s->table($tenant,'chat_conversations')->where('kind','direct')->firstOrFail();
                $id=$s->table($tenant,'chat_messages')->insertGetId(['tenant_id'=>$tenant->id,'conversation_id'=>$target->id,
                    'account_id'=>$actors[1]->id,'client_id'=>(string)Str::uuid(),'content_hash'=>hash('sha256',$text),'kind'=>'text',
                    'body_encrypted'=>Crypt::encryptString($text),'created_at'=>now()->toIso8601String()]);
                $row=$s->table($tenant,'chat_messages')->where('id',$id)->first(); app(ChatControls::class)->record($actors[1],$tenant,$row,'send'); return $row;
            };
            $cursor=$s->table($tenant,'chat_changes')->where('conversation_id',$chat->id)->max('id');
            $expected=[];
            for($i=0;$i<60;$i++) { $expected[]=(int)$seed('Burst '.$i)->id; if($i===30) $seed('Foreign chat cursor gap',$announcements); }
            $one=$call('GET',$path.'/changes?after='.$cursor,$tokens[0]);
            $two=$call('GET',$path.'/changes?after='.$one['meta']['cursor'],$tokens[0]);
            $received=array_merge(array_column(array_column($one['data'],'message'),'id'),array_column(array_column($two['data'],'message'),'id'));
            $this->expect(count($one['data'])===50 && $one['meta']['has_more'] && count($two['data'])===10 && !$two['meta']['has_more']
                && $received===$expected,'More than50 creations cross pages without gaps; global cursor IDs need not be contiguous');
            // Inject a synthetic commit immediately after the highwater SQL returns.
            $armed=false; $raceRow=null;
            $db->listen(function($query) use(&$armed,&$raceRow,$seed) {
                if ($armed && str_contains($query->sql,'max("id")') && str_contains($query->sql,'"chat_changes"')) {
                    $armed=false; $raceRow=$seed('After highwater capture');
                }
            });
            $armed=true;
            $race=$call('GET',$path.'/changes?after='.$two['meta']['cursor'],$tokens[0]);
            $this->expect(!$armed && $race['data']===[] && $race['meta']['cursor']===$two['meta']['cursor'],'Delta highwater excludes a later commit instead of skipping it');
            $raceNext=$call('GET',$path.'/changes?after='.$race['meta']['cursor'],$tokens[0]);
            $this->expect(count($raceNext['data'])===1 && $raceNext['data'][0]['message']['id']===(int)$raceRow->id,'Later commit appears on the next delta');
            $armed=true;
            $snapshotRace=$call('GET',$path.'/messages',$tokens[0]);
            $replay=$call('GET',$path.'/changes?after='.$snapshotRace['meta']['change_cursor'],$tokens[0]);
            $this->expect(!$armed && in_array((int)$raceRow->id,array_column($snapshotRace['data'],'id'),true)
                && count($replay['data'])===1 && $replay['data'][0]['message']['id']===(int)$raceRow->id,'Initial snapshot captures cursor before DTOs; racing creation is replayable');
            $future=$call('GET',$path.'/changes?after='.($replay['meta']['cursor']+1000000),$tokens[0]);
            $this->expect($future['data']===[] && $future['meta']['reset_required'] && $future['meta']['cursor']===$replay['meta']['cursor'],'Future/lost cursor explicitly requests full reset');
            $db->table('clubora.chat_memberships')->where('tenant_id',$tenantId)->where('conversation_id',$chat->id)->where('account_id',$actors[0]->id)->update(['left_at'=>now()->toIso8601String()]);
            $floor=(int)$s->table($tenant,'chat_messages')->where('conversation_id',$chat->id)->max('id');
            $call('GET',$path.'/changes?after=0',$tokens[0],[],404); // Direct membership cannot silently rejoin on GET.
            $call('POST',$base.'direct',$tokens[0],['account_id'=>$actors[1]->id]);
            $rejoin=$call('GET',$path.'/changes?after=0',$tokens[0]);
            $this->expect($rejoin['data']===[] && $rejoin['meta']['joined_after']===$floor && $rejoin['meta']['cursor']===$replay['meta']['cursor'],'Rejoin boundary filters old messages and old change events');
            $this->expect($call('GET',$path.'/messages',$tokens[0])['data']===[],'Rejoined initial snapshot cannot recover old history');
            $new=$send('After rejoin');
            $fresh=$call('GET',$path.'/changes?after='.$rejoin['meta']['cursor'],$tokens[0]);
            $this->expect(count($fresh['data'])===1 && $fresh['data'][0]['message']['id']===$new['id'],'Newly authorized post-rejoin creation remains visible');
            $call('GET',$path.'/changes?after=0',$tokens[2],[],404);
            $db->table('clubora.chat_blocks')->insert(['tenant_id'=>$tenantId,'account_id'=>$actors[1]->id,'other_account_id'=>$actors[0]->id]);
            $call('GET',$path.'/changes?after='.$fresh['meta']['cursor'],$tokens[0],[],404);
            $db->table('clubora.chat_blocks')->where('tenant_id',$tenantId)->delete();
            $foreign=Tenant::create(['slug'=>'delta-foreign-'.bin2hex(random_bytes(10)),'name'=>'Synthetic foreign delta']);
            $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$foreign->id,'account_id'=>$actors[0]->id,'role'=>'admin','portal_enabled'=>true]);
            $call('GET','clubs/'.$foreign->id.'/chats/'.$chat->id.'/changes?after=0',$tokens[0],[],404);
            $db->table('clubora.mobile_sessions')->where('account_id',$actors[0]->id)->update(['revoked_at'=>now()->toIso8601String()]);
            $call('GET',$path.'/changes?after='.$fresh['meta']['cursor'],$tokens[0],[],401);
            $this->expect(Http::recorded()->isEmpty() && Mail::sent(Mailable::class)->isEmpty() && Mail::queued(Mailable::class)->isEmpty(),'No provider or mail');
        } finally {
            $db->disableQueryLog(); $db->flushQueryLog(); $db->rollBack(); config(['clubunio-mobile.uploads_enabled'=>$uploads]); \Carbon\Carbon::setTestNow($originalNow);
        }
        $this->expect($db->transactionLevel()===0 && !$db->table('clubora.tenants')->whereIn('id',[$tenantId,$foreign->id])->exists()
            && !$db->table('clubora.accounts')->whereIn('id',$ids)->exists(),'Verified synthetic rollback');
        return $this->checks;
    }
}

if (defined('CLUBUNIO_CHAT_DELTA_INCLUDE_ONLY') && constant('CLUBUNIO_CHAT_DELTA_INCLUDE_ONLY')===true) return;
$probe=null;
try {
    if(PHP_SAPI!=='cli' || array_slice($argv,1)!==['--synthetic-rollback']) throw new \LogicException('Explicit synthetic rollback required');
    require dirname(__DIR__).'/vendor/autoload.php'; $app=require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('app.url')!=='https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')) throw new \LogicException('Exact enabled DEV required');
    foreach(['clubunio-mobile.ai.enabled','clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled','clubunio-mobile.keybox.provider_enabled'] as $flag)
        if(config($flag,false)) throw new \LogicException('External providers must remain closed');
    $probe=new MobileChatDeltaProbe; $checks=$probe->run($app);
    echo 'Chat delta: '.$checks.' checks; idleSQL='.$probe->idleQueries."; verified synthetic rollback, no providers.\n";
} catch(\Throwable $error) {
    fwrite(STDERR,'Chat delta gate failed at '.($probe?->stage ?? 'bootstrap').': '.get_class($error).' (private details suppressed).'.PHP_EOL); exit(1);
}
