<?php
declare(strict_types=1);
// Reviewed native-schema integration test. Only isolated databases, all writes rolled back.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
if(count($argv)!==3||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[1])
    ||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[2]))throw new LogicException('Two isolated databases required');
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
$base=config('database.connections.clubunio');
foreach(['extension_source'=>$argv[1],'extension_target'=>$argv[2]] as $name=>$database){$c=$base;$c['database']=$database;config(['database.connections.'.$name=>$c]);}
$source=Illuminate\Support\Facades\DB::connection('extension_source');$target=Illuminate\Support\Facades\DB::connection('extension_target');
if($source->selectOne('select current_database() n')->n!==$argv[1]||$target->selectOne('select current_database() n')->n!==$argv[2])throw new LogicException('Wrong isolated target');
$mapping=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v2.json'),true,512,JSON_THROW_ON_ERROR);
$receipt=$source->table('svs_projection.receipt')->sole();$version=hash('sha256',hash_file('sha256',$root.'/deploy/svs-import-mapping-v2.json').$receipt->projection_hash);
$private=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));
$crypt=new Illuminate\Encryption\Encrypter(base64_decode(substr($private['APP_KEY'],7),true),'AES-256-CBC');
$make=fn()=>new App\ClubUnio\Services\SvsDeltaImporter($source,$target,$mapping,app('encrypter'),$crypt,$version);
$assert=function(bool $ok,string $message){if(!$ok)throw new RuntimeException($message);};
$digest=function(string $table)use($target):string{
    if(!preg_match('/\A[a-z_]+\z/',$table))throw new LogicException('Invalid fixture table');
    return $target->selectOne('SELECT coalesce(md5(string_agg(to_jsonb(t)::text,\'\' ORDER BY to_jsonb(t)::text)),\'empty\') digest FROM clubunio."'.$table.'" t')->digest;
};
$source->beginTransaction();$target->beginTransaction();
try{
    $tenant=$target->table('clubunio.tenants')->where('slug','sv-sissach')->value('id');
    $actor=$target->table('clubunio.accounts')->orderBy('id')->value('id');
    $link=$target->table('clubunio.account_member_links')->where('tenant_id',$tenant)->first();
    $assert($link!==null,'Parent consent fixture unavailable');
    $consent=['kind'=>'parent','state'=>'approved','adult_consent_at'=>'2026-11-24 00:00:00+00','adult_consent_by'=>$actor,'adult_consent_source'=>'office'];
    $target->table('clubunio.account_member_links')->where('id',$link->id)->update($consent);
    $target->table('clubunio.account_parent_email_proofs')->updateOrInsert(['account_id'=>$actor],['email'=>'schema-review@example.invalid','verified_at'=>'2026-11-24 00:00:00+00','issuer_hash'=>str_repeat('a',64)]);
    $owner=$target->table('clubunio.tenant_accounts')->where('tenant_id',$tenant)->orderBy('account_id')->value('account_id');
    $task=$target->table('clubunio.workspace_tasks')->insertGetId(['tenant_id'=>$tenant,'created_by'=>$owner,'assigned_to'=>$owner,'title_encrypted'=>$crypt->encryptString('Synthetic review task'),'body_encrypted'=>$crypt->encryptString('Synthetic review body')]);
    $target->table('clubunio.workspace_task_events')->insert(['tenant_id'=>$tenant,'task_id'=>$task,'actor_id'=>$owner,'operation'=>'created','revision'=>1]);
    $target->table('clubunio.workspace_capture_commands')->insert(['tenant_id'=>$tenant,'account_id'=>$owner,'command_key'=>'d7bdf453-7284-4dfe-98bf-001da1f29972','input_hash'=>str_repeat('b',64),'result_url'=>'/synthetic-schema-review']);
    $match=$target->table('clubunio.sfv_schedule')->where('tenant_id',$tenant)->orderBy('match_id')->value('match_id');
    $field=$target->table('clubunio.training_options')->where('tenant_id',$tenant)->where('category','field')->orderBy('id')->value('id');
    $target->table('clubunio.veo_recording_adjustments')->updateOrInsert(['tenant_id'=>$tenant,'match_id'=>$match],['field_id'=>$field,'starts_at'=>'2026-11-25 10:00:00+00','ends_at'=>'2026-11-25 12:00:00+00','basis_hash'=>str_repeat('c',64),'revision'=>1,'updated_by'=>$owner,'fixture_starts_at'=>'2026-11-25 10:00:00+00']);
    $protectedTables=['tenant_ai_connections','tenant_ai_stores','tenant_ai_documents','tenant_ai_usage','tenant_ai_events','mobile_keyboxes','mobile_keybox_commands','chat_preferences','support_triage','workspace_tasks','workspace_task_events','chat_mentions','workspace_capture_commands','veo_recording_adjustments'];
    $protected=[];foreach($protectedTables as $table)$protected[$table]=$digest($table);
    $proof=$target->table('clubunio.account_parent_email_proofs')->get()->toJson();
    $accountFlags=$target->table('clubunio.accounts')->orderBy('id')->get(['id','active','api_only','password'])->toJson();
    $period=$target->table('clubunio.tenant_settings')->where('tenant_id',$tenant)->where('key','SIGNAGE_PERIOD_ID')->value('encrypted_value');
    $r=$make()->run(true,$receipt->source_hash,$argv[2]);
    $assert($r['inserted']===0&&$r['updated']===0,'Accepted copy must be converged');
    $actual=(array)$target->table('clubunio.account_member_links')->where('id',$link->id)->sole();foreach($consent as $k=>$v)$assert((string)$actual[$k]===(string)$v,'Local consent changed');
    $assert($proof===$target->table('clubunio.account_parent_email_proofs')->get()->toJson(),'Parent proof changed');
    $assert($accountFlags===$target->table('clubunio.accounts')->orderBy('id')->get(['id','active','api_only','password'])->toJson(),'Account security flags changed');
    $assert($period===$target->table('clubunio.tenant_settings')->where('tenant_id',$tenant)->where('key','SIGNAGE_PERIOD_ID')->value('encrypted_value'),'Local Winter setting changed');
    foreach($protectedTables as $table)$assert($protected[$table]===$digest($table),'Native data changed');
    $note=$source->table('clubunio.member_notes')->orderBy('id')->first();
    $native=$target->table('clubunio.member_notes')->where('tenant_id',$tenant)->where('legacy_id',$note->legacy_id)->sole();
    $source->table('clubunio.member_notes')->where('id',$note->id)->update(['body'=>'Synthetic source field update']);
    $target->table('clubunio.member_notes')->where('id',$native->id)->update(['active'=>!$native->active]);
    $r=$make()->run(true,$receipt->source_hash,$argv[2]);$after=$target->table('clubunio.member_notes')->where('id',$native->id)->sole();
    $assert($r['updated']===1&&$after->body==='Synthetic source field update'&&$after->active!==$native->active,'Independent field merge failed');
    $source->table('clubunio.member_notes')->where('id',$note->id)->update(['body'=>'Synthetic conflicting source']);
    $target->table('clubunio.member_notes')->where('id',$native->id)->update(['body'=>'Synthetic conflicting local']);
    $head=$target->table('migration_control.svs_runs')->max('id');$conflicted=false;
    try{$make()->run(true,$receipt->source_hash,$argv[2]);}catch(LogicException $e){$conflicted=str_contains($e->getMessage(),'conflicts');}
    $assert($conflicted&&$head===$target->table('migration_control.svs_runs')->max('id'),'Conflict must roll back ledger');
    $assert($target->table('clubunio.member_notes')->where('id',$native->id)->value('body')==='Synthetic conflicting local','Conflict overwrote local field');
    echo json_encode(['status'=>'passed','local_consent_preserved'=>true,'parent_proof_preserved'=>true,'native_tables_unchanged'=>count($protectedTables),'account_security_preserved'=>true,'winter_period_preserved'=>true,'independent_field_merge'=>true,'conflict_rollback'=>true,'live_writes'=>false]),PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Schema extension test failed ['.get_class($e).']; private values suppressed.'.PHP_EOL);$failed=true;}finally{while($target->transactionLevel()>0)$target->rollBack();while($source->transactionLevel()>0)$source->rollBack();}

if(isset($failed))exit(1);
