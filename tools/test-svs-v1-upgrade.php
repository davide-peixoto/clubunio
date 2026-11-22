<?php
declare(strict_types=1);
// Build an old ledger on a new isolated schema, then upgrade without resetting hashes.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
if(count($argv)!==4||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[1])||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[2])||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[3]))throw new LogicException('Isolated upgrade test required');
$base=config('database.connections.clubunio');foreach(['old'=>$argv[1],'new'=>$argv[2],'target'=>$argv[3]] as $key=>$database){$c=$base;$c['database']=$database;config(['database.connections.'.$key=>$c]);}
$old=Illuminate\Support\Facades\DB::connection('old');$new=Illuminate\Support\Facades\DB::connection('new');$t=Illuminate\Support\Facades\DB::connection('target');
$private=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));$crypt=new Illuminate\Encryption\Encrypter(base64_decode(substr($private['APP_KEY'],7),true),'AES-256-CBC');
$v1=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v1.json'),true,512,JSON_THROW_ON_ERROR);$v2=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v2.json'),true,512,JSON_THROW_ON_ERROR);
$known='36bff90ccdb89434e8372968241fb1354d3acc46a5539cba47167200a0792b3c';$receipt=$new->table('svs_projection.receipt')->sole();$version=hash('sha256',hash_file('sha256',$root.'/deploy/svs-import-mapping-v2.json').$receipt->projection_hash);
$make=fn()=>new App\ClubUnio\Services\SvsDeltaImporter($new,$t,$v2,app('encrypter'),$crypt,$version);$assert=function($ok,$label){if(!$ok)throw new RuntimeException($label);};
try{
    if(!$t->getSchemaBuilder()->hasTable('migration_control.svs_state')){
        $runner=new App\ClubUnio\Services\SvsDeltaImporter($old,$t,$v1,app('encrypter'),$crypt,$known);$r=$runner->run(true,$receipt->source_hash,$argv[3]);$assert($r['inserted']===37550,'v1_fixture_size');
    }
    if(getenv('SVS_V1_FIXTURE_ONLY')==='1'){
        $assert(!$t->table('migration_control.svs_state')->where('mapping_version','<>',$known)->exists(),'fixture_must_be_v1');
        echo json_encode(['v1_fixture'=>'prepared','baseline_rows'=>$t->table('migration_control.svs_state')->count()],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
    }
    $ledger=fn()=>$t->selectOne("select md5(string_agg(entity||identity_hash||target_pk::text||baseline::text||mapping_version,'' order by entity,identity_hash)) h from migration_control.svs_state")->h;$before=$ledger();
    $blocked=false;try{$make()->run(true,$receipt->source_hash,$argv[3]);}catch(LogicException $e){$blocked=$e->getMessage()==='Mapping upgrade requires explicit baseline migration';}$assert($blocked&&$ledger()===$before,'implicit_upgrade_rejected');
    // Deliberate local edit of one reviewed field must conflict and roll back all appended history and baseline version changes.
    $t->beginTransaction();try{
        $e=$t->table('clubunio.expenses')->where('legacy_id',102)->sole();$t->table('clubunio.expenses')->where('id',$e->id)->update(['team_season_id'=>null]);
        $blocked=false;try{$make()->run(true,$receipt->source_hash,$argv[3],true);}catch(LogicException $error){$blocked=str_contains($error->getMessage(),'conflicts');}
        $assert($blocked&&$ledger()===$before&&$t->table('clubunio.document_series')->count()===0,'upgrade_conflict_atomic_rollback');
    }finally{$t->rollBack();}
    $oldBaselines=$t->table('migration_control.svs_state')->orderBy('entity')->orderBy('identity_hash')->get();
    $r=$make()->run(true,$receipt->source_hash,$argv[3],true);$assert($r['inserted']===361&&$r['updated']===14&&$r['missing_review']===0&&$r['baseline_upgraded']===true,'exact_v1_upgrade_delta');
    $changed=0;foreach($oldBaselines as $state){$actual=$t->table('migration_control.svs_state')->where('entity',$state->entity)->where('identity_hash',$state->identity_hash)->sole();$assert($actual->target_pk===$state->target_pk,'native_ids_preserved');if($actual->baseline!==$state->baseline){$assert($state->entity==='expenses','only_expense_baselines_changed');$a=json_decode($actual->baseline,true);$b=json_decode($state->baseline,true);$assert(array_diff_key($a,['team_season_id'=>0])===array_diff_key($b,['team_season_id'=>0]),'only_reviewed_field_rebased');$changed++;}}
    $assert($changed===14,'fourteen_baseline_fields_changed');$repeat=$make()->run(true,$receipt->source_hash,$argv[3]);$assert($repeat['inserted']===0&&$repeat['updated']===0,'upgrade_replay_no_writes');
    echo json_encode(['implicit_upgrade_rejected'=>true,'conflict_rollback'=>true,'old_ids_preserved'=>true,'old_baselines_preserved_except_14_reviewed_fields'=>true,'upgrade'=>array_diff_key($r,['tables'=>0]),'replay'=>array_diff_key($repeat,['tables'=>0]),'status'=>'passed'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,'Upgrade test failed ['.get_class($error).']; private values suppressed.'.PHP_EOL);if($error instanceof LogicException||($error instanceof RuntimeException&&!($error instanceof Illuminate\Database\QueryException)))fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
