<?php

/** Public curated help and audience-scoped search. All synthetic rows roll back. */
function clubunioSupportHelpChecks($app,$db,callable $expect):void
{
    Illuminate\Support\Facades\Http::preventStrayRequests();Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Queue::fake();
    Illuminate\Support\Facades\Http::fake(['https://api.openai.com/v1/responses'=>Illuminate\Support\Facades\Http::response(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Synthetic public summary']]]]])]);
    config(['clubunio-support.enabled'=>true,'clubunio-support.triage_enabled'=>true,'clubunio-support.openai_key'=>'synthetic',
        'clubunio-support.openai_project'=>'proj_synthetic','clubunio-support.model'=>'synthetic','clubunio-verein-oidc.invites_enabled'=>false]);
    $guide=app(App\ClubUnio\Services\SupportGuide::class);
    $expect(count($guide->faq('de'))===18&&array_keys($guide->faq('de'))===array_keys($guide->faq('en')),'eighteen matching public topics inDEEN');
    foreach(['de','en'] as $locale)foreach($guide->faq($locale) as $topic=>$article)$expect(is_string($article['title'])&&trim($article['title'])!==''&&is_string($article['body'])&&trim($article['body'])!=='','complete public help article');
    $expect(isset($guide->faq('en','recovery code')['login']),'English FAQ searches article body');
    $expect(isset($guide->faq('de','Wiederherstellungscode')['login']),'German FAQ searches article body');
    $suffix=bin2hex(random_bytes(7));$needle='syntheticneedle'.$suffix;
    $tenant=App\ClubUnio\Models\Tenant::create(['slug'=>'support-help-'.$suffix,'name'=>'Synthetic Support Club']);
    $other=App\ClubUnio\Models\Tenant::create(['slug'=>'support-other-'.$suffix,'name'=>'Other Synthetic Support Club']);
    $makeActor=function(string $role)use($db,$tenant,$suffix){
        $actor=new App\ClubUnio\Models\Account;$actor->forceFill(['username'=>'support-'.$role.'-'.$suffix.'@example.invalid','display_name'=>'Synthetic '.$role,
            'password'=>'synthetic-no-login','active'=>true,'api_only'=>false,'preferred_locale'=>'de'])->save();
        $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>$role,'portal_enabled'=>true]);return $actor;
    };
    $staff=$makeActor('office');$member=$makeActor('member');
    $person=new App\ClubUnio\Models\Member;$person->forceFill(['tenant_id'=>$tenant->id,'first_name'=>'Synthetic','last_name'=>'Support Member','birth_date'=>'1990-01-01'])->save();
    $db->table('clubora.account_member_links')->insert(['tenant_id'=>$tenant->id,'account_id'=>$member->id,'member_id'=>$person->id,'kind'=>'self','state'=>'approved','source'=>'synthetic_support']);
    $controller=app(App\ClubUnio\Http\SupportCenterController::class);
    $call=function(string $method,array $data=[],?object $actor=null,?int $club=null,bool $mobile=true)use($app,$controller){
        $data += ['surface'=>'app'];
        $r=Illuminate\Http\Request::create(config('app.url').'/api/mobile/v1/support',$method,$data,[],[],['HTTP_ACCEPT'=>'application/json']);
        if($mobile&&$actor)$r->attributes->set('mobile.actor',$actor);
        $app->instance('request',$r);$r->setUserResolver(fn($guard=null)=>$actor);
        return json_decode($controller($r,$club)->getContent(),true,512,JSON_THROW_ON_ERROR)['data'];
    };
    $app->setLocale('en');$empty=$call('GET');
    $expect(count($empty['faq'])>=18,'curated public help available without tenant or seeded DB articles');
    $expect(!isset($empty['editableArticles'])&&!isset($empty['assignees']),'anonymous help exposes no editor or account options');
    $rows=[];
    foreach([['public',null,true,'en'],['club',$tenant->id,true,'en'],['club_staff',$tenant->id,true,'en'],
        ['club',$other->id,true,'en'],['platform',null,true,'en'],['club',$tenant->id,false,'en'],['public',null,true,'de']] as $i=>[$audience,$club,$published,$locale]){
        $rows[$i]=$db->table('clubora.support_articles')->insertGetId(['tenant_id'=>$club,'audience'=>$audience,'published'=>$published,
            'locale'=>$locale,'title'=>'Neutral fixture '.$suffix.' '.$i,'body'=>$needle.' body '.$i]);
    }
    $public=$call('GET',['q'=>$needle]);$expect(array_column($public['articles'],'id')===[$rows[0]],'body search preserves public locale and publication constraints');
    $personal=$call('GET',['q'=>$needle],$member,$tenant->id);$visible=array_column($personal['articles'],'id');sort($visible);
    $expected=[$rows[0],$rows[1]];sort($expected);$expect($visible===$expected,'member body search never leaks staff foreign draft or platform articles');
    $office=$call('GET',['q'=>$needle],$staff,$tenant->id);$visible=array_column($office['articles'],'id');sort($visible);
    $expected=[$rows[0],$rows[1],$rows[2]];sort($expected);$expect($visible===$expected,'staff body search remains within allowed audience and tenant');
    $mobile=$call('GET',[],$staff,$tenant->id);$expect($mobile['faq']['profile']['title']===app(App\ClubUnio\Services\SupportKnowledge::class)->articles('en','app')['profile']['title'],'mobile help uses current app locale over web account preference');
    $web=$call('GET',['q'=>$needle],$staff,$tenant->id,false);$expect(array_column($web['articles'],'id')===[$rows[6]],'web help retains preferred account locale');
    $webFaq=$call('GET',[],$staff,$tenant->id,false);$expect($webFaq['faq']['profile']['title']===app(App\ClubUnio\Services\SupportKnowledge::class)->articles('de','app')['profile']['title'],'web FAQ uses same locale as web DB articles');
    $searched=$call('GET',['q'=>'recovery code'],$member,$tenant->id);$expect(isset($searched['faq']['login'])&&!isset($searched['faq']['profile']),'API FAQ search filters body consistently');
    $missing=$call('GET',['q'=>'unmatched-'.$suffix],$member,$tenant->id);$expect($missing['articles']===[]&&$missing['faq']===[],'unmatched search returns a genuine empty result');
    $denied=function(callable $action,int $status)use($expect){
        try{$action();$expect(false,'expected scoped support denial');}
        catch(Illuminate\Validation\ValidationException){$expect($status===422,'invalid previous topic rejected');}
        catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){$expect($error->getStatusCode()===$status,'correct scoped support denial');}
    };
    $denied(fn()=> $call('GET',[],$member,$other->id),403);
    $denied(fn()=> $call('POST',['operation'=>'ask','body'=>'How exactly?','previous_topic'=>'private-ticket'],$member,$tenant->id),422);
    $denied(fn()=> $call('POST',['operation'=>'ask','body'=>'How exactly?','previous_topic'=>['wallet']],$member,$tenant->id),422);
    $followup=$call('POST',['operation'=>'ask','body'=>'How exactly?','previous_topic'=>'wallet'],$member,$tenant->id);
    $expect($followup['source']==='wallet'&&!$followup['ai'],'safe generic follow-up uses allowlisted curated context');
    $expect($guide->answer('private-marker@example.invalid','en','wallet')['source']===null,'private unrelated follow-up never inherits context');
    foreach(array_diff(App\ClubUnio\Services\SupportGuide::TOPICS,['login','expense']) as $topic)$expect(!$guide->answerForTopic($topic,'en')['ai'],'new curated topic does not add AI calls');
    $legacy=$call('POST',['operation'=>'ask','body'=>'Passkey private-marker@example.invalid'],$member,$tenant->id);$expect($legacy['ai']===true,'legacy public-only AI behavior preserved');
    $ticket=(string)Illuminate\Support\Str::uuid();$db->table('clubora.support_tickets')->insert(['id'=>$ticket,'tenant_id'=>$tenant->id,'requester_id'=>$member->id,'audience'=>'club','subject'=>'Wallet private-marker@example.invalid']);
    (new App\ClubUnio\Jobs\TriageSupportTicket($ticket,$tenant->id,'club','en'))->handle($guide);
    $triage=$db->table('clubora.support_triage')->where('ticket_id',$ticket)->first();
    $expect($triage->topic==='wallet'&&$triage->draft===$guide->faq('en')['wallet']['body']&&!(bool)$triage->provider_generated,'triage uses actual new topic instead of expense fallback');
    $expect(Illuminate\Support\Facades\Http::recorded()->count()===1,'new help and triage add no provider costs');
    foreach(Illuminate\Support\Facades\Http::recorded() as [$request])$expect(!str_contains($request->body(),'private-marker')&&!str_contains($request->body(),$needle)&&$request['store']===false,'provider receives only curated public article');
    $knowledge=app(App\ClubUnio\Services\SupportKnowledge::class);
    foreach(['app'=>25,'web'=>44] as $surface=>$minimum){
        foreach(['de','en'] as $language){
            $guides=$knowledge->articles($language,$surface);
            $expect(count($guides)>=$minimum,'all contextual guide topics available');
            $expect(array_reduce($guides,fn($ok,$guide)=>$ok&&count($guide['steps']??[])>=3&&($guide['surface']??null)===$surface,true),'contextual instructions have actionable steps');
        }
    }
    $mobileDefault=$call('GET',['surface'=>null],$staff,$tenant->id);
    $webDefault=$call('GET',['surface'=>null],$staff,$tenant->id,false);
    $expect($mobileDefault['surface']==='app'&&count($mobileDefault['faq'])>=25,'mobile defaults to app instructions');
    $expect($webDefault['surface']==='web'&&count($webDefault['faq'])>=44,'web defaults to administration instructions');
    $denied(fn()=> $call('GET',['surface'=>'../../private'],$member,$tenant->id),422);
    $expect(Illuminate\Support\Facades\Mail::sent(Illuminate\Mail\Mailable::class)->isEmpty()&&Illuminate\Support\Facades\Mail::queued(Illuminate\Mail\Mailable::class)->isEmpty(),'no outgoing mail');
    $expect(Illuminate\Support\Facades\Queue::pushedJobs()===[],'no queued delivery');
}

if(defined('CLUBUNIO_SUPPORT_HELP_TEST_LOADER'))return;
if(($argv[1]??'')!=='--synthetic-rollback'||!in_array($argv[2]??'', ['clubunio_dev','clubunio_stage','clubunio_prod'],true))throw new LogicException('Explicit synthetic target required');
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('clubunio');$origins=['clubunio_dev'=>'https://dev.clubunio.ch','clubunio_stage'=>'https://stage.clubunio.com','clubunio_prod'=>'https://app.clubunio.com'];
if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!==$argv[2]||$db->selectOne('select current_database() as name')->name!==$argv[2]||config('app.url')!==$origins[$argv[2]])throw new LogicException('Unexpected database');
$db->beginTransaction();
try{
    $db->statement("SET LOCAL application_name='support-help-synthetic-rollback'");$db->statement("SET LOCAL lock_timeout='5s'");
    foreach(['office','member'] as $role)$db->table('clubora.account_roles')->insertOrIgnore(['code'=>$role,'name'=>'Synthetic '.$role,'description'=>'Rollback only','sort_order'=>0,'active'=>true]);
    $checks=0;clubunioSupportHelpChecks($app,$db,function(bool $ok,string $case)use(&$checks){if(!$ok)throw new RuntimeException('Synthetic assertion failed: '.$case);$checks++;});
    echo 'Support help checks passed: '.$checks."; rollback; no external ticket delivery or paid AI requests.\n";
}catch(Throwable $error){fwrite(STDERR,'Synthetic check failed after '.($checks??0).' checks: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().(str_starts_with($error->getMessage(),'Synthetic assertion failed:')?' '.$error->getMessage():'')."\n");$failed=true;}
finally{$db->rollBack();}
if($failed??false)exit(1);
