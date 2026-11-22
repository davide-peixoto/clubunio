<?php
declare(strict_types=1);
// Integration suite on the same isolated databases as the CLI runner, never PROD.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
$base=config('database.connections.clubunio');
$sourceName='clubunio_stage_svs_candidate_a1b2c3d4';$targetName=getenv('SVS_REHEARSAL_DB')?:'clubunio_svs_rehearsal_a1b2c3d4';
$expectedMigrations=(int)(getenv('SVS_EXPECTED_MIGRATIONS')?:143);
if(!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$targetName))throw new LogicException('Isolated test DB name required');
foreach(['svs_test_source'=>$sourceName,'svs_test_target'=>$targetName] as $key=>$db){$c=$base;$c['database']=$db;config(['database.connections.'.$key=>$c]);}
$source=Illuminate\Support\Facades\DB::connection('svs_test_source');$target=Illuminate\Support\Facades\DB::connection('svs_test_target');
if($source->selectOne('select current_database() as n')->n!==$sourceName||$target->selectOne('select current_database() as n')->n!==$targetName)throw new LogicException('Wrong integration DB');
$mapping=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v1.json'),true,512,JSON_THROW_ON_ERROR);
$receipt=$source->table('svs_projection.receipt')->sole();
$version=hash('sha256',hash_file('sha256',$root.'/deploy/svs-import-mapping-v1.json').$receipt->projection_hash);
$private=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));
$targetCrypt=new Illuminate\Encryption\Encrypter(base64_decode(substr($private['APP_KEY'],7),true),'AES-256-CBC');
$make=fn()=>new App\ClubUnio\Services\SvsDeltaImporter($source,$target,$mapping,app('encrypter'),$targetCrypt,$version);
$assert=function(bool $ok,string $name){if(!$ok)throw new RuntimeException('Integration assertion: '.$name);};
$passed=[];
$run=function(string $label)use($make,$targetName,$receipt,&$passed){$r=$make()->run(true,$receipt->source_hash,$targetName);$passed[$label]=array_diff_key($r,['tables'=>0]);return $r;};
$source->beginTransaction();
try{
    $before=$target->table('clubunio.platform_admins')->get()->toJson();
    $originalNote=(array)$source->table('clubunio.member_notes')->orderBy('id')->first();
    $second=(array)$source->table('clubunio.member_notes')->orderBy('id')->skip(1)->first();
    $native=$target->table('clubunio.tenants')->insertGetId(['slug'=>'isolated-native-only','name'=>'Synthetic native-only tenant']);
    $synthetic=$originalNote;unset($synthetic['id']);$synthetic['legacy_id']=900000001;$synthetic['body']='Synthetic source note';
    $source->table('clubunio.member_notes')->insert($synthetic);
    $source->table('clubunio.member_notes')->where('id',$originalNote['id'])->update(['body'=>'Synthetic remote update']);
    $targetTenant=$target->table('clubunio.tenants')->where('slug','sv-sissach')->value('id');
    $target->table('clubunio.member_notes')->where('tenant_id',$targetTenant)->where('legacy_id',$second['legacy_id'])->update(['body'=>'Synthetic local-only edit']);
    $r=$run('insert_update_local_preservation');
    $assert($r['inserted']===1&&$r['updated']===1,'exact delta writes');
    $assert($target->table('clubunio.member_notes')->where('legacy_id',$second['legacy_id'])->where('tenant_id',$targetTenant)->value('body')==='Synthetic local-only edit','local edit preserved');
    $assert($target->table('clubunio.tenants')->where('id',$native)->exists(),'native-only tenant preserved');
    $r=$run('repeat_delta');$assert($r['inserted']===0&&$r['updated']===0,'repeat is no-op');
    $source->table('clubunio.member_notes')->where('legacy_id',900000001)->delete();
    $r=$run('source_deletion_review');$assert($r['missing_review']===1&&$r['inserted']===0&&$r['updated']===0,'deletion is review only');
    $assert($target->table('clubunio.member_notes')->where('legacy_id',900000001)->exists(),'no physical delete');
    $source->table('clubunio.member_notes')->where('id',$second['id'])->update(['body'=>'Conflicting remote edit']);
    $runs=$target->table('migration_control.svs_runs')->count();
    $baselineHash=$target->selectOne("select md5(string_agg(baseline::text,'' order by entity,identity_hash)) as h from migration_control.svs_state")->h;
    try{$make()->run(true,$receipt->source_hash,$targetName);throw new RuntimeException('Conflict accepted');}catch(LogicException $e){$assert(str_contains($e->getMessage(),'conflicts'),'conflict recognized');}
    $assert($target->table('migration_control.svs_runs')->count()===$runs,'conflicting run not committed');
    $assert($target->selectOne("select md5(string_agg(baseline::text,'' order by entity,identity_hash)) as h from migration_control.svs_state")->h===$baselineHash,'baseline not advanced after conflict');
    $passed['conflict_rollback']=true;
    $source->table('clubunio.member_notes')->where('id',$second['id'])->update(['body'=>$second['body']]);
    // Fail late in the dependency order, after an earlier note update was attempted.
    $source->table('clubunio.member_notes')->where('id',$originalNote['id'])->update(['body'=>'Must roll back']);
    $slot=$source->table('clubunio.training_slots')->first();$source->table('clubunio.training_slots')->where('id',$slot->id)->update(['hall'=>!$slot->hall]);
    $target->unprepared("CREATE FUNCTION migration_control.fail_test() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic failure''; END'; CREATE TRIGGER svs_fail_test BEFORE UPDATE ON clubunio.training_slots FOR EACH ROW EXECUTE FUNCTION migration_control.fail_test()");
    try{
        try{$make()->run(true,$receipt->source_hash,$targetName);throw new RuntimeException('Injected error ignored');}catch(Illuminate\Database\QueryException $e){}
        $assert($target->table('clubunio.member_notes')->where('legacy_id',$originalNote['legacy_id'])->value('body')==='Synthetic remote update','earlier write rolled back');
        $assert($target->selectOne("select md5(string_agg(baseline::text,'' order by entity,identity_hash)) as h from migration_control.svs_state")->h===$baselineHash,'baseline rolled back after SQL error');
        $passed['late_failure_rollback']=true;
    }finally{$target->unprepared('DROP TRIGGER svs_fail_test ON clubunio.training_slots; DROP FUNCTION migration_control.fail_test()');}
    try{$make()->run(false,$receipt->source_hash,'clubunio_prod');throw new RuntimeException('Live target allowed');}catch(LogicException $e){$assert(str_contains($e->getMessage(),'isolated'),'live target guard');}$passed['live_prod_rejected']=true;
    $source->table('clubunio.tenants')->update(['slug'=>'foreign-tenant']);
    try{$make()->run(false,$receipt->source_hash,$targetName);throw new RuntimeException('Wrong tenant allowed');}catch(LogicException $e){$assert(str_contains($e->getMessage(),'SV Sissach'),'tenant guard');}$passed['foreign_source_tenant_rejected']=true;
    $source->table('clubunio.tenants')->update(['slug'=>'sv-sissach']);
    $assert($target->table('clubunio.platform_admins')->get()->toJson()===$before,'platform admin preserved');
    $assert($target->table('clubunio.migrations')->count()===$expectedMigrations,'all existing migrations preserved');
    $passed['platform_admin_and_'.$expectedMigrations.'_migrations_preserved']=true;
    $resultFile=$expectedMigrations===143?'integration-results.json':'integration-results-current-prod.json';
    file_put_contents('/root/clubunio-import-lab/'.$resultFile,json_encode($passed,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    echo 'Integration scenarios passed: '.count($passed).'. No live PROD connection used.'.PHP_EOL;
}catch(Throwable $e){
    $failed=true;fwrite(STDERR,'Rehearsal integration failed ['.get_class($e).']; private SQL values suppressed.'.PHP_EOL);
    if($e instanceof RuntimeException&&!$e instanceof Illuminate\Database\QueryException)fwrite(STDERR,$e->getMessage().PHP_EOL);
}finally{$source->rollBack();}
if(isset($failed))exit(1);
