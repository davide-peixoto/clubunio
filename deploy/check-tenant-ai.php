<?php

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{TenantAiConfiguration, TenantAiGateway, TenantAiKnowledge, OpenAiModels};
use Illuminate\Support\Facades\{Crypt, DB, Http, Storage};
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); });
$db = DB::connection('clubunio');
if (PHP_SAPI!=='cli' || !in_array($db->getDatabaseName(),['clubunio_dev','clubunio_stage','clubunio_prod'],true)) exit(1);
$checks=0;
$expect = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Tenant AI check failed: '.$name);
    $checks++;
};
$status = static function (int $expected, callable $operation) use ($expect): void {
    try { $operation(); throw new RuntimeException('Expected rejection'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $expect($e->getStatusCode()===$expected,'rejection '.$expected); }
};
config(['clubunio-tenant-ai.enabled'=>true,'clubunio-storage.profiles_enabled'=>true,'clubunio-storage.managed_enabled'=>true]);
Http::preventStrayRequests();
$seen=[];
Http::fake(function ($request) use (&$seen) {
    $seen[] = $request;
    $url=$request->url();
    if (str_ends_with($url,'/models')) return Http::response(['data'=>[['id'=>OpenAiModels::TEXT]]]);
    if (str_ends_with($url,'/responses')) return Http::response(['status'=>'completed','usage'=>['input_tokens'=>100,'output_tokens'=>10],
        'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'Synthetic response']]]]]);
    if (str_ends_with($url,'/vector_stores') && $request->method()==='POST') return Http::response(['id'=>'vs_qa_'.bin2hex(random_bytes(8))]);
    if (str_ends_with($url,'/files') && $request->method()==='POST') return Http::response(['id'=>'file-qa-'.bin2hex(random_bytes(8)),'status'=>'completed']);
    if ($request->method()==='DELETE' && str_contains($url,'/vector_stores/')) return Http::response(['deleted'=>true]);
    throw new RuntimeException('Unexpected synthetic provider request');
});
$db->beginTransaction();
$cleanup=[];
try {
    $suffix=bin2hex(random_bytes(6));
    $qaRoot=sys_get_temp_dir().'/clubunio-tenant-ai-qa-'.$suffix;
    config(['filesystems.disks.local.root'=>$qaRoot]);
    Storage::forgetDisk('local');
    $tenant=Tenant::forceCreate(['slug'=>'qa-tenant-ai-'.$suffix,'name'=>'Synthetic AI club']);
    $other=Tenant::forceCreate(['slug'=>'qa-foreign-ai-'.$suffix,'name'=>'Synthetic foreign club']);
    $actor=Account::forceCreate(['username'=>'qa-ai-'.$suffix.'@example.invalid','display_name'=>'Synthetic AI admin',
        'password'=>password_hash(Str::random(40),PASSWORD_BCRYPT),'active'=>true,'api_only'=>false]);
    $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenant->id,'account_id'=>$actor->id,'role'=>'admin']);
    $config=app(TenantAiConfiguration::class); $knowledge=app(TenantAiKnowledge::class);
    $key='sk-synthetic-'.$suffix; $project='proj_qa_'.$suffix;
    $input=['mode'=>'own','project_id'=>$project,'api_key'=>$key,'enabled'=>false,'privacy_reviewed'=>true,
        'monthly_limit_microusd'=>30000000,'revision'=>0];
    $config->save($actor,$tenant,$input);
    $row=$config->table()->where('tenant_id',$tenant->id)->first();
    $expect($row->encrypted_key!==$key && Crypt::decryptString($row->encrypted_key)===$key,'encrypted key');
    $expect(!str_contains(json_encode($config->summary($tenant)),$key),'masked summary');
    $config->verify($actor,$tenant);
    $input['api_key']=''; $input['revision']=1; $input['enabled']=true;
    $config->save($actor,$tenant,$input);
    $expect($config->credentials($tenant)['project']===$project,'verified own project');
    $status(403,fn()=>$knowledge->stores($actor,$other));
    $status(403,fn()=>$config->save($actor,$other,$input));
    $result=app(TenantAiGateway::class)->respond($tenant,'mobile',['model'=>OpenAiModels::TEXT,'store'=>false,'input'=>'Synthetic','max_output_tokens'=>100]);
    $expect($result->successful(),'scoped response');
    $request=end($seen);
    $expect($request->hasHeader('Authorization','Bearer '.$key) && $request->hasHeader('OpenAI-Project',$project),'scoped headers');
    $expect((int)$config->table('usage')->where('tenant_id',$tenant->id)->value('actual_microusd')===15,'usage ledger');
    $config->table()->where('tenant_id',$tenant->id)->update(['monthly_limit_microusd'=>1]);
    $before=count($seen);
    $status(429,fn()=>app(TenantAiGateway::class)->respond($tenant,'mobile',['model'=>OpenAiModels::TEXT,'store'=>false,'input'=>'Synthetic']));
    $expect(count($seen)===$before,'budget blocks before HTTP');
    $config->table()->where('tenant_id',$tenant->id)->update(['monthly_limit_microusd'=>30000000]);
    $knowledge->createStore($actor,$tenant,'Synthetic training','training');
    $store=$config->table('stores')->where('tenant_id',$tenant->id)->first();
    $input['revision']=2; $input['enabled']=false; $input['project_id']='proj_other_'.$suffix;
    $status(409,fn()=>$config->save($actor,$tenant,$input));
    $status(404,fn()=>$knowledge->upload($actor,$tenant,999999999,Illuminate\Http\UploadedFile::fake()->createWithContent('qa.txt','Synthetic knowledge')));
    $profile=(string)Str::uuid();
    $db->table('clubora.tenant_storage_profiles')->insert(['id'=>$profile,'tenant_id'=>$tenant->id,'provider'=>'managed','account'=>'','container'=>'','active'=>true]);
    $knowledge->upload($actor,$tenant,$store->id,Illuminate\Http\UploadedFile::fake()->createWithContent('qa.txt','Synthetic club knowledge, no personal data.'));
    $document=$config->table('documents')->where('tenant_id',$tenant->id)->first();
    $cleanup[]='managed-documents/'.$document->folder.$document->filename;
    $expect($document->state==='ready' && Storage::disk('local')->exists($cleanup[0]),'private original and index');
    $status(404,fn()=>$knowledge->remove($actor,$tenant,999999999));
    $knowledge->remove($actor,$tenant,$document->id);
    $expect($config->table('documents')->where('id',$document->id)->value('state')==='archived','archive after detach');
    $expect(Storage::disk('local')->exists($cleanup[0]),'original retained');
    $expect(!collect($seen)->contains(fn($r)=>$r->method()==='DELETE' && preg_match('~/v1/files/~',$r->url())),'no global file deletion');
    $knowledge->removeStore($actor,$tenant,$store->id);
    $expect(count($knowledge->stores($actor,$tenant))===0,'store archived');
    $config->table()->where('tenant_id',$tenant->id)->update(['mode'=>'clubunio']);
    $status(403,fn()=>$config->save($actor,$tenant,['mode'=>'clubunio','project_id'=>$project,'api_key'=>'sk-synthetic-foreignkey',
        'enabled'=>false,'privacy_reviewed'=>true,'monthly_limit_microusd'=>30000000,'revision'=>$config->summary($tenant)['revision']]));
    $db->table('clubora.platform_admins')->insert(['account_id'=>$actor->id,'active'=>true]);
    $managed=['mode'=>'clubunio','project_id'=>'proj_managed_'.$suffix,'api_key'=>'sk-managed-'.$suffix,
        'enabled'=>false,'privacy_reviewed'=>true,'monthly_limit_microusd'=>30000000,'revision'=>0];
    $config->save($actor,$other,$managed,true); $config->verify($actor,$other,true);
    $expect($config->summary($other)['verified'],'platform provisioning without club role');
    $status(403,fn()=>$knowledge->stores($actor,$other));
    echo json_encode(['database'=>$db->getDatabaseName(),'synthetic_checks'=>$checks,'provider'=>'fake',
        'original_storage'=>'private_managed','all_data'=>'rolled_back','real_notifications'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->rollBack();
    foreach ($cleanup as $path) Storage::disk('local')->delete($path);
    if (isset($qaRoot) && dirname($qaRoot)===sys_get_temp_dir() && preg_match('/^clubunio-tenant-ai-qa-[a-f0-9]{12}$/D',basename($qaRoot))) {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($qaRoot);
    }
}
