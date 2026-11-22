<?php
declare(strict_types=1);
// Review tool: explicit native tables only; output contracts for isolated rehearsal.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(count($argv)!==3||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[1])||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[2]))throw new LogicException('Isolated contract databases required');
$base=config('database.connections.clubunio');
foreach(['contract_source'=>$argv[1],'contract_target'=>$argv[2]] as $name=>$database){$c=$base;$c['database']=$database;config(['database.connections.'.$name=>$c]);}
$source=Illuminate\Support\Facades\DB::connection('contract_source');$target=Illuminate\Support\Facades\DB::connection('contract_target');
if($source->selectOne('select current_database() n')->n!==$argv[1]||$target->selectOne('select current_database() n')->n!==$argv[2])throw new LogicException('Wrong database');
$source->statement('SET search_path TO public');$target->statement('SET search_path TO public');
$mapping=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v1.json'),true,512,JSON_THROW_ON_ERROR);
$tables=['mobile_app_announcements','document_series_templates','document_series','document_series_recipients','member_change_requests','legacy_material_receipts','legacy_training_plans','team_guests','sfv_schedule','svs_expense_season_reviews'];
foreach($tables as $table){
    $columns=$source->select("select column_name as name,data_type as type,is_identity as identity,column_default as default from information_schema.columns where table_schema='clubunio' and table_name=? order by ordinal_position",[$table]);
    $fks=$source->select("select c.confrelid::regclass::text parent, (select json_agg(a.attname order by k.n) from unnest(c.conkey) with ordinality k(i,n) join pg_attribute a on a.attrelid=c.conrelid and a.attnum=k.i) columns,(select json_agg(a.attname order by k.n) from unnest(c.confkey) with ordinality k(i,n) join pg_attribute a on a.attrelid=c.confrelid and a.attnum=k.i) referenced from pg_constraint c where c.contype='f' and c.conrelid=?::regclass order by c.conname",['clubunio.'.$table]);
    $fk=[];foreach($fks as $f)$fk[]=['parent'=>$f->parent,'columns'=>json_decode($f->columns,true),'referenced'=>json_decode($f->referenced,true)];
    $identity=match($table){'team_guests'=>['tenant_id','legacy_source_id'],'sfv_schedule'=>['tenant_id','match_id'],'svs_expense_season_reviews'=>['tenant_id','source_expense_id','source_hash','rule_version'],default=>['tenant_id','legacy_source_table','legacy_source_id','legacy_source_hash']};
    $mapping[$table]=['columns'=>$columns,'pk'=>['id'],'identity'=>$identity,'fks'=>$fk];
}
$mapping['document_series_templates']['after']=['document_types'];
$mapping['document_series_recipients']['after']=['document_types'];
$mapping['member_change_requests']['after']=['countries','genders','billing_recipients'];
ksort($mapping);
file_put_contents($root.'/deploy/svs-import-mapping-v2.json',json_encode($mapping,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
$columns=$target->select("select table_name,ordinal_position::integer as ordinal_position,column_name,data_type,is_nullable,column_default,is_identity,numeric_precision::integer as numeric_precision,numeric_scale::integer as numeric_scale,character_maximum_length::integer as character_maximum_length,datetime_precision::integer as datetime_precision,collation_name from information_schema.columns where table_schema='clubunio' order by table_name,ordinal_position");
$contract=['columns_sha256'=>hash('sha256',json_encode($columns,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'migrations'=>$target->table('clubunio.migrations')->orderBy('migration')->pluck('migration')->all()];
file_put_contents($root.'/deploy/svs-target-schema-v2.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo json_encode(['tables'=>count($mapping),'migrations'=>count($contract['migrations'])]).PHP_EOL;
