<?php

/** Reviewed translation catalog and authorization checks; never sends a paid request. */
function clubunioChatTranslationLanguageChecks($app,$db,callable $expect): void
{
    Illuminate\Support\Facades\Http::preventStrayRequests(); $calls=0;$revokeAfter=false;
    Illuminate\Support\Facades\Http::fake(function($request)use(&$calls,&$revokeAfter){
        if($request->url()!=='https://api.openai.com/v1/responses')throw new LogicException('Unexpected synthetic provider');
        $calls++;
        if($revokeAfter)config(['clubunio-chat-translation.enabled'=>['de','en']]);
        return Illuminate\Support\Facades\Http::response(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'Synthetic translated text']]]],
            'usage'=>['input_tokens'=>10,'output_tokens'=>5]]);
    });
    config(['app.key'=>'base64:'.base64_encode(random_bytes(32)),'clubunio-verein-oidc.invites_enabled'=>false,
        'clubunio-languages.enabled'=>['de'=>'Deutsch','en'=>'English'],'clubunio-languages.default'=>'de',
        'clubunio-mobile.ai.enabled'=>true,'clubunio-mobile.ai.translation_provider'=>'openai','clubunio-mobile.ai.writing_provider'=>'openai',
        'clubunio-tenant-ai.enabled'=>false,
        'clubunio-mobile.ai.openai.key'=>'synthetic-no-paid-request','clubunio-mobile.ai.openai.model'=>App\ClubUnio\Services\OpenAiModels::TEXT,
        'clubunio-mobile.ai.monthly_limit_microusd'=>20000000]);
    $enabled=config('clubunio-chat-translation.enabled');
    $expect(count(app(App\ClubUnio\Mobile\ChatTranslationLanguages::class)->policy()['enabled'])===24,'reviewed independent translation catalog has24targets');
    $expect(app(App\ClubUnio\Mobile\MobileLanguages::class)->policy()['enabled']===['de','en'],'UI catalog remainsDEEN');
    $tenant=App\ClubUnio\Models\Tenant::create(['slug'=>'chat-language-'.bin2hex(random_bytes(7)),'name'=>'Synthetic Chat Language Club']);
    $actor=new App\ClubUnio\Models\Account;
    $actor->forceFill(['username'=>'chat-language-'.bin2hex(random_bytes(9)).'@example.invalid','display_name'=>'Synthetic Chat Language User',
        'password'=>'synthetic-no-login','active'=>true,'api_only'=>false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'admin','portal_enabled'=>true]);
    $app->instance('request',Illuminate\Http\Request::create(config('app.url').'/api/mobile/v1/synthetic','POST'));
    $preferences=app(App\ClubUnio\Mobile\ChatPreferences::class);$ai=app(App\ClubUnio\Mobile\MobileAiService::class);
    $input=['request_key'=>(string)Illuminate\Support\Str::uuid(),'operation'=>'translate','language'=>'fr','text'=>'Synthetic private draft','consent'=>true];
    $deny=function(callable $call,int $expected)use($expect){
        try{$call();$expect(false,'expected language/consent rejection');}
        catch(Illuminate\Validation\ValidationException){$expect($expected===422,'validation failed closed');}
        catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error){$expect($error->getStatusCode()===$expected,'authorization failed closed');}
    };
    $off=$preferences->get($actor,$tenant);$expect(!$off['translation_enabled']&&$off['target_language']==='de','translation defaults off');
    $deny(fn()=> $ai->execute($actor,$tenant,$input),403);
    $saved=$preferences->save($actor,$tenant,['revision'=>0,'translation_enabled'=>false,'target_language'=>'fr']);
    $expect($saved['target_language']==='fr'&&!$saved['translation_enabled'],'choosing translation target never implies consent');
    $saved=$preferences->save($actor,$tenant,['revision'=>1,'translation_enabled'=>true,'target_language'=>'fr']);
    $expect($saved['translation_enabled']&&count($saved['language_policy']['names'])===24,'explicit consent with localized24targetnames');
    $first=$ai->execute($actor,$tenant,$input);$expect($first['language']==='fr'&&!$first['cached']&&$calls===1,'new translation uses reviewed target');
    $cached=$ai->execute($actor,$tenant,$input);$expect($cached['cached']&&$calls===1,'authorized same request uses cache');
    config(['clubunio-chat-translation.enabled'=>['de','en']]);
    $fallback=$preferences->get($actor,$tenant);
    $expect($fallback['language_fallback']&&!$fallback['translation_enabled']&&$fallback['target_language']==='de','revoked saved target disables effective consent');
    $raw=$db->table('clubora.chat_preferences')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->first();
    $expect($raw->target_language==='fr'&&(bool)$raw->translation_enabled,'policy fallback does not silently rewrite saved consent');
    $deny(fn()=> $ai->execute($actor,$tenant,$input),422);$expect($calls===1,'revocation rejects before cached result');
    config(['clubunio-chat-translation.enabled'=>$enabled]);
    $armed=true;$intercepted=false;
    $db->listen(function($query)use(&$armed,&$intercepted,$db){
        if($armed&&$db->transactionLevel()>0&&str_contains($query->sql,'"tenants"')){
            $armed=false;$intercepted=true;config(['clubunio-chat-translation.enabled'=>['de','en']]);
        }
    });
    $deny(fn()=> $ai->execute($actor,$tenant,$input),422);
    $expect($intercepted&&$calls===1,'catalog rechecked inside lock before selecting cache');
    config(['clubunio-chat-translation.enabled'=>$enabled]);$revokeAfter=true;
    $afterInput=array_replace($input,['request_key'=>(string)Illuminate\Support\Str::uuid(),'text'=>'Synthetic second private draft']);
    $deny(fn()=> $ai->execute($actor,$tenant,$afterInput),422);$revokeAfter=false;
    $expect($calls===2&&$db->table('clubora.mobile_ai_work')->where('account_id',$actor->id)->where('request_key',$afterInput['request_key'])->value('state')==='attention',
        'catalog revoked during provider call withholds result and never caches success');
    config(['clubunio-chat-translation.enabled'=>$enabled]);
    $deny(fn()=> $ai->execute($actor,$tenant,array_replace($input,['language'=>'zz','request_key'=>(string)Illuminate\Support\Str::uuid()])),422);
    $deny(fn()=> $ai->execute($actor,$tenant,array_replace($input,['operation'=>'friendly','language'=>'fr','request_key'=>(string)Illuminate\Support\Str::uuid()])),422);
    $expect($calls===2,'invalid translation and untranslated writing target make no provider call');
    config(['clubunio-mobile.ai.translation_provider'=>'unreviewed']);
    $expect(app(App\ClubUnio\Mobile\ChatTranslationLanguages::class)->policy()['enabled']===[],'unreviewed provider fails closed');
    $deny(fn()=> $ai->execute($actor,$tenant,$input),422);
    config(['clubunio-mobile.ai.translation_provider'=>'openai']);
    $alias=$preferences->save($actor,$tenant,['revision'=>2,'translation_enabled'=>false,'target_language'=>'pt']);
    $expect($alias['target_language']==='pt-PT'&&!$alias['translation_enabled'],'legacy Portuguese alias normalized without consent');
    $version=$alias['revision'];
    foreach($enabled as $language){
        $stored=$preferences->save($actor,$tenant,['revision'=>$version,'translation_enabled'=>false,'target_language'=>$language]);
        $version=$stored['revision'];
        $expect($stored['target_language']===$language&&!$stored['translation_enabled'],'all reviewed targets persist without enabling consent');
    }
    if($db->getDriverName()==='pgsql'){
        $db->statement('SAVEPOINT chat_invalid_language');
        try{
            $db->table('clubora.chat_preferences')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->update(['target_language'=>'unsafe_locale']);
            $rejected=false;
        }catch(Illuminate\Database\QueryException){$rejected=true;}
        finally{$db->statement('ROLLBACK TO SAVEPOINT chat_invalid_language');$db->statement('RELEASE SAVEPOINT chat_invalid_language');}
        $expect($rejected,'database still rejects malformed language identifiers');
    }
}

if(defined('CLUBUNIO_CHAT_TRANSLATION_TEST_LOADER'))return;
if(($argv[1]??'')!=='--synthetic-rollback'||!in_array($argv[2]??'', ['clubunio_dev','clubunio_stage','clubunio_prod'],true))throw new LogicException('Explicit synthetic target required');
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('clubunio');$origins=['clubunio_dev'=>'https://dev.clubunio.ch','clubunio_stage'=>'https://stage.clubunio.com','clubunio_prod'=>'https://app.clubunio.com'];
if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!==$argv[2]||$db->selectOne('select current_database() as name')->name!==$argv[2]||config('app.url')!==$origins[$argv[2]])throw new LogicException('Unexpected database');
$db->beginTransaction();
try{
    $db->statement("SET LOCAL application_name='chat-translation-language-synthetic-rollback'");$db->statement("SET LOCAL lock_timeout='5s'");
    $db->table('clubora.account_roles')->insertOrIgnore(['code'=>'admin','name'=>'Synthetic admin','description'=>'Rollback only','sort_order'=>0,'active'=>true]);
    $checks=0;clubunioChatTranslationLanguageChecks($app,$db,function(bool $ok,string $case)use(&$checks){if(!$ok)throw new RuntimeException('Synthetic assertion failed: '.$case);$checks++;});
    echo 'Chat translation language checks passed: '.$checks."; rollback; all provider HTTP faked; no paid requests.\n";
}catch(Throwable $error){fwrite(STDERR,'Synthetic check failed after '.($checks??0).' checks: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().(str_starts_with($error->getMessage(),'Synthetic assertion failed:')?' '.$error->getMessage():'')."\n");$failed=true;}
finally{$db->rollBack();}
if($failed??false)exit(1);
