<?php

/** All fixtures are synthetic; Http is faked before any operational flag is changed. */
function clubunioKeyboxEmergencyChecks($app,$db,callable $expect): void
{
    $mode='oauth5xx'; $oauth=0; $pinCalls=0;
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function($request) use(&$mode,&$oauth,&$pinCalls) {
        if($request->url()==='https://auth.igloohome.co/oauth2/token') {
            $oauth++;
            if($mode==='oauthConnection') throw new Illuminate\Http\Client\ConnectionException('synthetic');
            return Illuminate\Support\Facades\Http::response($mode==='oauth5xx'?[]:($mode==='oauth401'?[]:['access_token'=>'synthetic-token']),$mode==='oauth5xx'?503:($mode==='oauth401'?401:200));
        }
        if(str_starts_with($request->url(),'https://api.igloodeveloper.co/igloohome/devices/')&&str_ends_with($request->url(),'/algopin/hourly')) {
            $pinCalls++;
            if($mode==='pinConnection') throw new Illuminate\Http\Client\ConnectionException('synthetic');
            return Illuminate\Support\Facades\Http::response($mode==='success'?['pin'=>'13572468','pinId'=>'synthetic-pin']:[], $mode==='success'?200:503);
        }
        throw new LogicException('Unexpected synthetic provider target');
    });
    config(['app.key'=>'base64:'.base64_encode(random_bytes(32)),'cache.default'=>'array','clubunio-security.enabled'=>false,
        'clubunio-verein-oidc.invites_enabled'=>false,'clubunio-verein-oidc.only'=>false,'clubunio-mobile.enabled'=>true,'clubunio-mobile.native_keybox_enabled'=>true,
        'clubunio-mobile.keybox'=>['emergency_enabled'=>true,'provider_enabled'=>true,'issuance_enabled'=>true,'operations_reviewed'=>true,
            'offline_revocation_acknowledged'=>true,'dispatch_after_response'=>false,'protocol'=>'legacy']]);
    $suffix=bin2hex(random_bytes(7));
    $tenant=App\ClubUnio\Models\Tenant::create(['slug'=>'keybox-emergency-'.$suffix,'name'=>'Synthetic Emergency Club']);
    $actor=new App\ClubUnio\Models\Account;
    $actor->forceFill(['username'=>'emergency-'.$suffix.'@example.invalid','display_name'=>'Synthetic Emergency User',
        'password'=>password_hash($suffix,PASSWORD_BCRYPT,['cost'=>4]),'active'=>true,'api_only'=>false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'office','portal_enabled'=>true]);
    $session=Illuminate\Support\Str::uuid()->toString(); $secret=bin2hex(random_bytes(32));
    $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$actor->id,'token_hash'=>hash('sha256',$secret),
        'device_name'=>'Synthetic emergency fixture','expires_at'=>now()->addDay()->toIso8601String(),'created_at'=>now()->toIso8601String()]);
    foreach(['IGLOOHOME_CLIENT_ID'=>'synthetic-id','IGLOOHOME_CLIENT_SECRET'=>'synthetic-secret'] as $key=>$value)
        $db->table('clubora.tenant_settings')->insert(['tenant_id'=>$tenant->id,'area'=>'keybox','key'=>$key,'label'=>'Synthetic private setting','value_type'=>'string',
            'encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString($value),'enabled'=>true,'is_secret'=>true]);
    $approved=[];
    $box=function(string $label) use($db,$tenant,$actor,&$approved) {
        $id=$db->table('clubora.mobile_keyboxes')->insertGetId(['tenant_id'=>$tenant->id,'name'=>$label,'active'=>true,
            'provider_device_cipher'=>Illuminate\Support\Facades\Crypt::encryptString('synthetic-'.$label),
            'emergency_code_cipher'=>Illuminate\Support\Facades\Crypt::encryptString('86421357')]);
        $db->table('clubora.mobile_keybox_grants')->insert(['tenant_id'=>$tenant->id,'keybox_id'=>$id,'account_id'=>$actor->id,'active'=>true]);
        $approved[]=$id;
        config(['clubunio-mobile.keybox.tenant_slugs'=>[$tenant->slug],'clubunio-mobile.keybox.approved_keybox_ids.'.$tenant->slug=>$approved]);
        return $id;
    };
    $request=function(array $body) use($app,$db,$actor,$tenant,$session,$secret) {
        $r=Illuminate\Http\Request::create('https://synthetic.example.invalid/api/mobile/v1/keyboxes','POST',$body,[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$session.'.'.$secret]);
        $r->attributes->set('mobile.actor',$actor); $r->attributes->set('mobile.tenant',$tenant);
        $r->attributes->set('mobile.session',$db->table('clubora.mobile_sessions')->where('id',$session)->first());
        $app->instance('request',$r); return $r;
    };
    $service=app(App\ClubUnio\Mobile\MobileKeyboxService::class);
    $prepare=function(int $id,?string $key=null) use($service,$request) {
        return $service->prepare($request(['request_key'=>$key??Illuminate\Support\Str::uuid()->toString(),'keybox_revision'=>1]),$id,null);
    };
    $deny=function(callable $action,int $status) use($expect) {
        try { $action(); $expect(false,'expected denial'); }
        catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $expect($error->getStatusCode()===$status,'correct denial status'); }
    };
    $id=$box('garderobe'); $firstKey=Illuminate\Support\Str::uuid()->toString(); $firstId=null;
    for($i=0;$i<3;$i++) {
        $held=$prepare($id,$i===0?$firstKey:null); $firstId??=$held['id'];
        $service->execute($held['id']); $service->execute($held['id']);
        $result=$service->command($actor,$tenant,$id,$held['id']);
        $expect($result['state']==='failed'&&$result['providerFailure']===true,'definite OAuth outage is terminal confirmed failure');
        $expect($result['emergencyAvailable']===($i===2),'only third confirmed failure enables emergency');
        $expect(!isset($result['pin'])&&!isset($result['emergencyCode']),'polling never exposes a secret');
    }
    $expect($oauth===3&&$pinCalls===0,'no retry and no PIN endpoint calls during OAuth outage');
    $replay=$prepare($id,$firstKey); $service->execute($replay['id']);
    $expect($replay['id']===$firstId&&$replay['state']==='failed'&&$oauth===3,'failed UUID replay never reissues');
    $expect($db->table('clubora.mobile_keybox_commands')->where('keybox_id',$id)->where('state','failed')->count()===3,'same-scope failed attempts retain separate immutable UUIDs');
    $code=$service->emergency($request(['keybox_revision'=>1]),$id,null);
    $expect($code['emergencyCode']==='86421357'&&$code['emergencySeconds']>0&&$code['emergencySeconds']<=60,'explicit request reveals configured emergency for bounded period');
    $again=$service->emergency($request(['keybox_revision'=>1]),$id,null);
    $expect($again['emergencyExpiresAt']===$code['emergencyExpiresAt'],'repeated disclosure does not extend deadline');
    $cipher=$db->table('clubora.mobile_keyboxes')->where('id',$id)->value('emergency_code_cipher');
    $expect(!str_contains($cipher,'86421357'),'emergency secret encrypted at rest');
    $model=App\ClubUnio\Models\MobileKeybox::findOrFail($id);
    $expect(!array_key_exists('emergency_code_cipher',$model->toArray()),'model serialization suppresses emergency secret');
    $db->table('clubora.mobile_keybox_commands')->where('keybox_id',$id)->update(['emergency_exposed_at'=>now()->subSeconds(61)->toIso8601String()]);
    $deny(fn()=> $service->emergency($request(['keybox_revision'=>1]),$id,null),409);
    $db->table('clubora.mobile_keybox_grants')->where('keybox_id',$id)->update(['active'=>false,'revision'=>2]);
    $db->transaction(fn()=> $service->invalidateBox($tenant,$id,$actor->id));
    $expect($db->table('clubora.mobile_keybox_commands')->where('keybox_id',$id)->where('remote_revocation_state','required')->count()===3,
        'revoking disclosed static emergency requires physical action and retains failed evidence');
    $deny(fn()=> $service->emergency($request(['keybox_revision'=>1]),$id,null),404);
    $db->table('clubora.mobile_keybox_grants')->where('keybox_id',$id)->update(['active'=>true,'revision'=>3]);
    $db->table('clubora.mobile_keybox_commands')->where('keybox_id',$id)->update(['valid_from'=>now()->subHours(3)->toIso8601String(),'valid_until'=>now()->subHours(2)->toIso8601String()]);
    $expect($service->show($actor,$tenant,$id)['issuanceEnabled']===false,'static emergency disclosure blocks issuance even after old command window expired');
    Illuminate\Support\Facades\RateLimiter::clear('mobile-keybox:'.$tenant->id.':'.$actor->id);
    $bad=$box('ballhaus'); $mode='oauth401'; $held=$prepare($bad); $service->execute($held['id']);
    $rejected=$service->command($actor,$tenant,$bad,$held['id']);
    $expect($rejected['state']==='failed'&&$rejected['providerFailure']===false&&$rejected['emergencyAvailable']===false,'OAuth credential rejection does not qualify');
    $mode='pinConnection'; $held=$prepare($bad); $service->execute($held['id']); $service->execute($held['id']);
    $uncertain=$service->command($actor,$tenant,$bad,$held['id']);
    $expect($uncertain['state']==='attention'&&!isset($uncertain['emergencyCode']),'uncertain physical PIN outcome remains attention');
    $before=$oauth; $deny(fn()=> $prepare($bad),503);
    $expect($oauth===$before,'uncertain physical outcome blocks subsequent provider request');
    $deny(fn()=> $service->emergency($request(['keybox_revision'=>1]),$bad,null),409);
    $mode='oauthConnection'; $fresh=$box('connection'); $held=$prepare($fresh); $service->execute($held['id']);
    $expect($service->command($actor,$tenant,$fresh,$held['id'])['providerFailure']===true,'pre-PIN connection failure counts safely');
    $deny(fn()=> $service->emergency($request(['keybox_revision'=>2]),$fresh,null),409);
    $second=new App\ClubUnio\Models\Account;
    $second->forceFill(['username'=>'emergency-second-'.$suffix.'@example.invalid','display_name'=>'Synthetic second user',
        'password'=>password_hash($suffix,PASSWORD_BCRYPT,['cost'=>4]),'active'=>true,'api_only'=>false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$second->id,'role'=>'office','portal_enabled'=>true]);
    $secondSession=Illuminate\Support\Str::uuid()->toString();$secondSecret=bin2hex(random_bytes(32));
    $db->table('clubora.mobile_sessions')->insert(['id'=>$secondSession,'account_id'=>$second->id,'token_hash'=>hash('sha256',$secondSecret),
        'device_name'=>'Synthetic second fixture','expires_at'=>now()->addDay()->toIso8601String(),'created_at'=>now()->toIso8601String()]);
    $secondRequest=function(array $body)use($app,$db,$second,$tenant,$secondSession,$secondSecret) {
        $r=Illuminate\Http\Request::create(config('app.url').'/api/mobile/v1/keyboxes','POST',$body,[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$secondSession.'.'.$secondSecret]);
        $r->attributes->set('mobile.actor',$second);$r->attributes->set('mobile.tenant',$tenant);
        $r->attributes->set('mobile.session',$db->table('clubora.mobile_sessions')->where('id',$secondSession)->first());$app->instance('request',$r);return $r;
    };
    Illuminate\Support\Facades\RateLimiter::clear('mobile-keybox:'.$tenant->id.':'.$actor->id);
    $independent=$box('independent');$mode='success';
    $db->table('clubora.mobile_keybox_grants')->insert(['tenant_id'=>$tenant->id,'keybox_id'=>$independent,'account_id'=>$second->id,'active'=>true]);
    $one=$prepare($independent);
    $secondBody=['request_key'=>Illuminate\Support\Str::uuid()->toString(),'keybox_revision'=>1];
    $deny(fn()=> $service->prepare($secondRequest($secondBody),$independent,null),409);
    $service->execute($one['id']);
    $two=$service->prepare($secondRequest($secondBody),$independent,null);$service->execute($two['id']);
    $expect($one['id']!==$two['id']&&$service->command($second,$tenant,$independent,$two['id'])['state']==='issued',
        'independently authorized second user receives own command in same physical window');
    $expect($service->command($actor,$tenant,$independent,$one['id'])['state']==='issued','first user code remains valid');
    if($db->getDriverName()==='pgsql') {
        Illuminate\Support\Facades\RateLimiter::clear('mobile-keybox:'.$tenant->id.':'.$actor->id);
        $season=$db->table('clubora.seasons')->insertGetId(['tenant_id'=>$tenant->id,'name'=>'Synthetic emergency season','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
        $team=$db->table('clubora.teams')->insertGetId(['tenant_id'=>$tenant->id,'name'=>'Synthetic emergency team']);
        $teamSeason=$db->table('clubora.team_seasons')->insertGetId(['tenant_id'=>$tenant->id,'team_id'=>$team,'season_id'=>$season,'label'=>'Synthetic emergency squad']);
        $db->table('clubora.tenant_settings')->insert(['tenant_id'=>$tenant->id,'area'=>'general','key'=>'SaisonID','label'=>'Synthetic current season','value_type'=>'string',
            'encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString((string)$season),'enabled'=>true,'is_secret'=>false]);
        $bus=$db->table('clubora.buses')->insertGetId(['tenant_id'=>$tenant->id,'name'=>'Synthetic emergency bus']);
        foreach(['GENEHMIGT','STORNIERT'] as $status) $db->table('clubora.bus_statuses')->insert(['tenant_id'=>$tenant->id,'code'=>$status,'name'=>$status]);
        $reservation=$db->table('clubora.bus_reservations')->insertGetId(['tenant_id'=>$tenant->id,'bus_id'=>$bus,'team_season_id'=>$teamSeason,'status'=>'GENEHMIGT',
            'starts_at'=>now()->subHour()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String()]);
        $busBox=$box('bus'); $db->table('clubora.mobile_keyboxes')->where('id',$busBox)->update(['bus_id'=>$bus]); $mode='oauth5xx';
        for($i=0;$i<3;$i++) {
            $work=$service->prepare($request(['request_key'=>Illuminate\Support\Str::uuid()->toString(),'keybox_revision'=>1,'reservation_revision'=>1]),null,$reservation);
            $service->execute($work['id']);
        }
        $busResult=$service->bus($actor,$tenant,$reservation);
        $expect($busResult['emergencyAvailable']===true&&!isset($busResult['emergencyCode']),'bus GET offers availability without emergency secret');
        $disclosure=$service->emergency($request(['keybox_revision'=>1,'reservation_revision'=>1]),null,$reservation);
        $expect($disclosure['emergencySeconds']>0&&$disclosure['emergencySeconds']<=60,'approved in-window bus emergency supported');
        $deny(fn()=> $service->emergency($request(['keybox_revision'=>1,'reservation_revision'=>2]),null,$reservation),409);
        $db->table('clubora.bus_reservations')->where('id',$reservation)->update(['starts_at'=>now()->subHours(6)->toIso8601String(),'ends_at'=>now()->subHours(5)->toIso8601String(),'revision'=>2]);
        $deny(fn()=> $service->emergency($request(['keybox_revision'=>1,'reservation_revision'=>2]),null,$reservation),409);
        $db->table('clubora.bus_reservations')->where('id',$reservation)->update(['status'=>'STORNIERT','revision'=>3]);
        $deny(fn()=> $service->emergency($request(['keybox_revision'=>1,'reservation_revision'=>3]),null,$reservation),403);
    }
    $db->table('clubora.mobile_sessions')->where('id',$session)->update(['revoked_at'=>now()->toIso8601String()]);
    $deny(fn()=> $service->emergency($request(['keybox_revision'=>1]),$fresh,null),401);
    $expect($pinCalls===3,'one uncertain and two independently issued fake physical calls');
    $db->table('clubora.mobile_sessions')->where('id',$session)->update(['revoked_at'=>null]);
    Illuminate\Support\Facades\RateLimiter::clear('mobile-keybox:'.$tenant->id.':'.$actor->id);
    $authorityBox=$box('authority');$mode='oauth5xx';
    for($i=0;$i<3;$i++) {$work=$prepare($authorityBox);$service->execute($work['id']);}
    $service->emergency($request(['keybox_revision'=>1]),$authorityBox,null);
    $db->table('clubora.mobile_sessions')->where('id',$session)->update(['revoked_at'=>now()->toIso8601String()]);
    $db->table('clubora.accounts')->where('id',$actor->id)->increment('revision');
    $expect($service->show($actor,$tenant,$authorityBox)['issuanceEnabled']===true,'logout and cosmetic account revision do not revoke physical grant');
    $expect(!$db->table('clubora.mobile_keybox_commands')->where('keybox_id',$authorityBox)->where('remote_revocation_state','required')->exists(),
        'logout alone does not manufacture physical remediation evidence');
    $db->table('clubora.mobile_keybox_grants')->insert(['tenant_id'=>$tenant->id,'keybox_id'=>$authorityBox,'account_id'=>$second->id,'active'=>true]);
    $db->table('clubora.accounts')->where('id',$actor->id)->update(['active'=>false]);
    $expect($service->show($second,$tenant,$authorityBox)['issuanceEnabled']===false,'account deactivation blocks disclosed static emergency for all users');
    $expect($db->table('clubora.mobile_keybox_commands')->where('keybox_id',$authorityBox)->where('remote_revocation_state','required')->exists(),
        'lost account authority records physical emergency remediation');
    $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$second->id)->update(['role'=>'admin']);
    config(['clubunio-mobile.keybox.administration_enabled'=>true]);
    $admin=app(App\ClubUnio\Mobile\MobileKeyboxAdministration::class);
    $input=['name'=>'Synthetic rotated authority','provider_device_id'=>null,'emergency_code'=>'77778888','bus_id'=>null,'active'=>true,'revision'=>1,'reason'=>'Synthetic confirmed physical administration'];
    $admin->saveBox($second,$tenant,$authorityBox,$input);
    $expect($db->table('clubora.mobile_keybox_commands')->where('keybox_id',$authorityBox)->where('remote_revocation_state','required')->exists(),
        'changing stored emergency code without physical confirmation never clears remediation');
    $rotationDenied=function(array $data)use($admin,$second,$tenant,$authorityBox,$expect) {
        try{$admin->saveBox($second,$tenant,$authorityBox,$data);$expect(false,'expected rotation validation');}
        catch(Illuminate\Validation\ValidationException $error){$expect(isset($error->errors()['emergency_rotated']),'rotation validation is explicit');}
    };
    $rotationDenied(array_replace($input,['revision'=>2,'emergency_rotated'=>true,'emergency_code'=>null]));
    $rotationDenied(array_replace($input,['revision'=>2,'emergency_rotated'=>true]));
    $rotationDenied(array_replace($input,['revision'=>2,'emergency_rotated'=>true,'emergency_code'=>'24682468','provider_device_id'=>'other-synthetic-device']));
    $rotated=$admin->saveBox($second,$tenant,$authorityBox,array_replace($input,['revision'=>2,'emergency_rotated'=>true,'emergency_code'=>'24682468']));
    $expect((int)$rotated->revision===3&&!$db->table('clubora.mobile_keybox_commands')->where('keybox_id',$authorityBox)->where('remote_revocation_state','required')->exists(),
        'explicit physical rotation of changed secret confirms static emergency evidence');
    $expect($service->show($second,$tenant,$authorityBox)['issuanceEnabled']===true,'physically confirmed static rotation restores eligible issuance');
    $db->transaction(fn()=> $service->invalidateBox($tenant,$authorityBox));
    $expect(!$db->table('clubora.mobile_keybox_commands')->where('keybox_id',$authorityBox)->where('remote_revocation_state','required')->exists(),
        'later invalidation never reopens already physically confirmed static evidence');
    $admin->saveBox($second,$tenant,$bad,['name'=>'Synthetic uncertain physical box','provider_device_id'=>null,'emergency_code'=>'24682468',
        'emergency_rotated'=>true,'bus_id'=>null,'active'=>true,'revision'=>1,'reason'=>'Synthetic emergency rotation with separate uncertain PIN']);
    $expect($db->table('clubora.mobile_keybox_commands')->where('keybox_id',$bad)->where('state','invalidated')->where('remote_revocation_state','required')->exists(),
        'emergency rotation does not confirm uncertain normal PIN evidence');
    $expect(!str_contains(json_encode($db->table('clubora.object_changes')->where('tenant_id',$tenant->id)->get()),'86421357'),'audit contains no emergency secret');
}

if(defined('CLUBUNIO_KEYBOX_EMERGENCY_TEST_LOADER')) return;
if(($argv[1]??'')!=='--synthetic-rollback'||!in_array($argv[2]??'', ['clubunio_dev','clubunio_stage','clubunio_prod'],true)) throw new LogicException('Explicit synthetic target required');
require dirname(__DIR__).'/vendor/autoload.php'; $app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=Illuminate\Support\Facades\DB::connection('clubunio');
$origins=['clubunio_dev'=>'https://dev.clubunio.ch','clubunio_stage'=>'https://stage.clubunio.com','clubunio_prod'=>'https://app.clubunio.com'];
if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!==$argv[2]||$db->selectOne('select current_database() as name')->name!==$argv[2]||config('app.url')!==$origins[$argv[2]]) throw new LogicException('Unexpected database');
$db->beginTransaction();
try {
    $db->statement("SET LOCAL application_name='keybox-emergency-synthetic-rollback'"); $db->statement("SET LOCAL lock_timeout='5s'");
    foreach(['office','admin'] as $role) $db->table('clubora.account_roles')->insertOrIgnore(['code'=>$role,'name'=>'Synthetic '.$role,'description'=>'Rollback only','sort_order'=>0,'active'=>true]);
    $checks=0; clubunioKeyboxEmergencyChecks($app,$db,function(bool $ok,string $case)use(&$checks){ if(!$ok)throw new RuntimeException('Synthetic assertion failed: '.$case);$checks++; });
    echo 'Keybox emergency checks passed: '.$checks."; all fixtures rollback; provider HTTP faked; no physical actions.\n";
} catch(Throwable $error) { fwrite(STDERR,'Synthetic check failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().(str_starts_with($error->getMessage(),'Synthetic assertion failed:')?' '.$error->getMessage():'')."\n");$failed=true; }
finally { $db->rollBack(); }
if($failed??false)exit(1);
