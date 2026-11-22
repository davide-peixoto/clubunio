<?php
declare(strict_types=1);
// Assertions never print source values. All modifications are isolated/transactional.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
if(count($argv)!==3||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[1])||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[2]))throw new LogicException('Isolated tests only');
$base=config('database.connections.clubunio');foreach(['s'=>$argv[1],'t'=>$argv[2]] as $k=>$name){$c=$base;$c['database']=$name;config(['database.connections.'.$k=>$c]);}
$s=Illuminate\Support\Facades\DB::connection('s');$t=Illuminate\Support\Facades\DB::connection('t');
if($s->selectOne('select current_database() n')->n!==$argv[1]||$t->selectOne('select current_database() n')->n!==$argv[2])throw new LogicException('Wrong database');
$map=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v2.json'),true,512,JSON_THROW_ON_ERROR);$receipt=$s->table('svs_projection.receipt')->sole();
$version=hash('sha256',hash_file('sha256',$root.'/deploy/svs-import-mapping-v2.json').$receipt->projection_hash);
$private=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));$crypt=new Illuminate\Encryption\Encrypter(base64_decode(substr($private['APP_KEY'],7),true),'AES-256-CBC');
$make=fn()=>new App\ClubUnio\Services\SvsDeltaImporter($s,$t,$map,app('encrypter'),$crypt,$version);
$passed=[];$assert=function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed[$label]=true;};
$tenant=$t->table('clubunio.tenants')->where('slug','sv-sissach')->sole()->id;
try{
    $counts=['mobile_app_announcements'=>1,'document_series_templates'=>8,'document_series'=>4,'document_series_recipients'=>17,'legacy_material_receipts'=>19,'member_change_requests'=>18,'legacy_training_plans'=>47,'team_guests'=>2,'sfv_schedule'=>231,'svs_expense_season_reviews'=>14];
    foreach($counts as $table=>$n)$assert($t->table('clubunio.'.$table)->where('tenant_id',$tenant)->count()===$n,'count_'.$table);
    $assert((int)$t->selectOne('select count(*) n from clubunio.expenses e join clubunio.team_seasons ts on ts.tenant_id=e.tenant_id and ts.id=e.team_season_id where e.season_id<>ts.season_id')->n===0,'expense_seasons_consistent');
    $assert($t->table('clubunio.expense_lines')->count()===$s->table('clubunio.expense_lines')->count(),'expense_lines_count');
    // Compare every expense and line except reviewed native team references, IDs and bookkeeping.
    foreach(['expenses','expense_lines'] as $table){
        $columns=array_column($map[$table]['columns'],'name');$excluded=['id','tenant_id','team_season_id','expense_id','created_at','updated_at','revision'];
        foreach($map[$table]['fks'] as $fk)$excluded=array_merge($excluded,$fk['columns']);
        $columns=array_values(array_diff($columns,$excluded));$select=implode(',',array_map(fn($v)=>'"'.$v.'"',$columns));
        $a=$s->select('select '.$select.' from clubunio.'.$table.' order by legacy_id');$b=$t->select('select '.$select.' from clubunio.'.$table.' where tenant_id=? order by legacy_id',[$tenant]);
        $assert(json_encode($a,JSON_THROW_ON_ERROR)===json_encode($b,JSON_THROW_ON_ERROR),$table.'_all_business_values_preserved');
    }
    $assert($t->table('clubunio.document_series')->whereNotNull('created_by')->count()===0,'unresolved_authors_remain_unknown');
    $assert($t->table('clubunio.legacy_material_receipts')->where('integrity_state','source_metadata_only')->count()===19,'receipts_metadata_only');
    $assert($t->table('clubunio.accounts')->whereIn('username',$s->table('clubunio.accounts')->pluck('username'))->where('active',true)->count()===0&&$t->table('clubunio.tenant_accounts')->where('tenant_id',$tenant)->where('portal_enabled',true)->count()===0,'no_user_access_granted');
    foreach($t->table('clubunio.document_series_templates')->get() as $r){$d=json_decode($r->definition,true,512,JSON_THROW_ON_ERROR);$assert(hash_equals($r->definition_hash,App\ClubUnio\Services\DocumentSeriesRenderer::hash($d)),'template_hashes_valid');$assert($t->table('clubunio.document_types')->where('tenant_id',$tenant)->where('id',$d['type_id'])->exists(),'template_native_type_refs');}
    foreach(['document_series','document_series_recipients'] as $table)foreach($t->table('clubunio.'.$table)->get() as $r){
        $d=json_decode($crypt->decryptString($r->snapshot),true,512,JSON_THROW_ON_ERROR);$assert(hash_equals($r->snapshot_hash,App\ClubUnio\Services\DocumentSeriesRenderer::hash($d)),'snapshot_hashes_valid');
        if($table==='document_series')$assert($d['template_id']===$r->template_id&&$d['template_hash']===$t->table('clubunio.document_series_templates')->where('id',$r->template_id)->value('definition_hash'),'series_embedded_refs');
        else $assert($d['member_id']===$r->member_id&&$d['portal_visible']===false&&$t->table('clubunio.document_types')->where('id',$d['definition']['type_id'])->exists(),'recipient_embedded_refs');
    }
    $r=$make()->run(false,$receipt->source_hash,$argv[2]);$assert($r['inserted']===0&&$r['updated']===0&&$r['missing_review']===0,'full_replay_no_changes');
    // Database immutability, each failure confined to a rolled-back transaction.
    foreach(['mobile_app_announcements','document_series_templates','document_series','document_series_recipients','legacy_material_receipts','member_change_requests','legacy_training_plans'] as $table){
        $t->beginTransaction();$blocked=false;try{$t->table('clubunio.'.$table)->where('id',$t->table('clubunio.'.$table)->value('id'))->update(['legacy_read_only'=>false]);}catch(Illuminate\Database\QueryException $e){$blocked=$e->getCode()==='P0001';}finally{$t->rollBack();}$assert($blocked,'immutable_'.$table);
    }
    // Independent source/local fields on a mutable supplementary entity.
    $s->beginTransaction();$t->beginTransaction();
    try{
        $sourceGuest=$s->table('clubunio.team_guests')->first();$nativeGuest=$t->table('clubunio.team_guests')->where('legacy_source_id',$sourceGuest->legacy_source_id)->sole();
        $s->table('clubunio.team_guests')->where('id',$sourceGuest->id)->update(['status'=>$sourceGuest->status==='DABEI'?'OFFEN':'DABEI']);
        $t->table('clubunio.team_guests')->where('id',$nativeGuest->id)->update(['last_name'=>'Synthetic local surname']);
        $r=$make()->run(true,$receipt->source_hash,$argv[2]);$g=$t->table('clubunio.team_guests')->where('id',$nativeGuest->id)->sole();
        $assert($r['updated']===1&&$g->last_name==='Synthetic local surname'&&$g->status!==$sourceGuest->status,'guest_three_way_fields');
        $r=$make()->run(true,$receipt->source_hash,$argv[2]);$assert($r['inserted']===0&&$r['updated']===0,'guest_delta_replay');
    }finally{$s->rollBack();$t->rollBack();}
    $s->beginTransaction();$t->beginTransaction();
    try{
        $type=(array)$s->table('clubunio.document_types')->first();$type['id']=900000001;$type['legacy_id']=900000001;$type['code']='synthetic-svs-type';$type['name']='Synthetic SVS type';$s->table('clubunio.document_types')->insert($type);
        $template=(array)$s->table('clubunio.document_series_templates')->first();unset($template['id']);$template['legacy_source_hash']=hash('sha256','synthetic template version');$template['family_key']='synthetic-native-rebase';
        $d=json_decode($template['definition'],true,512,JSON_THROW_ON_ERROR);$d['type_id']=900000001;$template['definition']=json_encode($d,JSON_THROW_ON_ERROR);$template['definition_hash']=App\ClubUnio\Services\DocumentSeriesRenderer::hash($d);$s->table('clubunio.document_series_templates')->insert($template);
        $r=$make()->run(true,$receipt->source_hash,$argv[2]);$nativeType=$t->table('clubunio.document_types')->where('legacy_id',900000001)->sole();$nativeTemplate=$t->table('clubunio.document_series_templates')->where('family_key','synthetic-native-rebase')->sole();$actual=json_decode($nativeTemplate->definition,true,512,JSON_THROW_ON_ERROR);
        $assert($r['inserted']===2&&$r['updated']===0&&$nativeType->id!==900000001&&$actual['type_id']===$nativeType->id&&App\ClubUnio\Services\DocumentSeriesRenderer::hash($actual)===$nativeTemplate->definition_hash,'embedded_native_ids_rebased');
        $r=$make()->run(true,$receipt->source_hash,$argv[2]);$assert($r['inserted']===0&&$r['updated']===0,'embedded_rebase_replay');
    }finally{$s->rollBack();$t->rollBack();}
    // Append history: bypass only the fixture's immutability triggers, never target triggers.
    $s->beginTransaction();$t->beginTransaction();
    try{
        $table='mobile_app_announcements';$old=(array)$s->table('clubunio.'.$table)->first();unset($old['id']);$old['legacy_source_hash']=hash('sha256','synthetic new historical version');$old['title']='Synthetic historical version';
        $s->table('clubunio.'.$table)->insert($old);
        $r=$make()->run(true,$receipt->source_hash,$argv[2]);$assert($r['inserted']===1&&$r['updated']===0&&$t->table('clubunio.'.$table)->count()===2,'history_version_append');
        $r=$make()->run(true,$receipt->source_hash,$argv[2]);$assert($r['inserted']===0&&$r['updated']===0,'history_version_replay');
    }finally{$s->rollBack();$t->rollBack();}
    $assert(!config('clubunio.outbound_enabled'),'outbound_disabled');
    echo json_encode(['passed'=>$passed,'counts'=>$counts,'status'=>'passed'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'History rehearsal failed ['.get_class($e).']; no private values logged.'.PHP_EOL);if($e instanceof Illuminate\Database\QueryException){fwrite(STDERR,'SQLSTATE '.$e->getCode().'; last assertion '.array_key_last($passed).PHP_EOL);foreach($e->getTrace() as $frame)if(($frame['file']??'')===__FILE__)fwrite(STDERR,'Test line '.$frame['line'].PHP_EOL);}elseif($e instanceof RuntimeException)fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
