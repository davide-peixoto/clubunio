<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
try{
    $upgradeV1=in_array('--upgrade-v1',$argv,true);
    $argv=array_values(array_filter($argv,fn($v)=>$v!=='--upgrade-v1'));
    if(count($argv)!==5||!in_array($argv[1],['--rehearse','--apply'],true)
        ||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[2])
        ||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[3]))throw new LogicException('Use --rehearse|--apply <candidate DB> <isolated rehearsal DB> <snapshot manifest SHA256>; live PROD is forbidden');
    $private=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));
    $manifestPath=(getenv('CLUBUNIO_SVS_SNAPSHOT_DIR')?:($private['SOURCE_SNAPSHOT_DIR']??'')).'/manifest.json';
    if(!is_file($manifestPath)||!hash_equals($argv[4],hash_file('sha256',$manifestPath)))throw new LogicException('Private source manifest does not match selected snapshot');
    $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    $sourceContract=json_decode(file_get_contents($root.'/deploy/svs-source-schema-v1.json'),true,512,JSON_THROW_ON_ERROR);
    $shape=[];foreach($manifest['tables'] as $table=>$definition)$shape[$table]=['columns'=>$definition['columns'],'primary_key'=>$definition['primary_key']];ksort($shape);
    if($manifest['format']!==$sourceContract['format']||$manifest['source']!==$sourceContract['source']||!hash_equals($sourceContract['shape_sha256'],hash('sha256',json_encode($shape,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))))throw new LogicException('Source schema changed; re-review field mapping first');
    $app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
    if(config('clubunio.outbound_enabled'))throw new LogicException('Outbound must be disabled');
    $base=config('database.connections.clubunio');
    foreach(['projection'=>$argv[2],'rehearsal'=>$argv[3]] as $key=>$name){$c=$base;$c['database']=$name;config(['database.connections.'.$key=>$c]);}
    $source=Illuminate\Support\Facades\DB::connection('projection');$target=Illuminate\Support\Facades\DB::connection('rehearsal');
    $targetContract=json_decode(file_get_contents($root.'/deploy/svs-target-schema-v2.json'),true,512,JSON_THROW_ON_ERROR);
    // regclass defaults are rendered relative to search_path; pin it for hashing.
    $target->statement('SET search_path TO public');
    $columns=$target->select("select table_name,ordinal_position::integer as ordinal_position,column_name,data_type,is_nullable,column_default,is_identity,numeric_precision::integer as numeric_precision,numeric_scale::integer as numeric_scale,character_maximum_length::integer as character_maximum_length,datetime_precision::integer as datetime_precision,collation_name from information_schema.columns where table_schema='clubunio' order by table_name,ordinal_position");
    $target->statement('SET search_path TO clubunio');
    $columnsHash=hash('sha256',json_encode($columns,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    if(!hash_equals($targetContract['columns_sha256'],$columnsHash)||$target->table('clubunio.migrations')->orderBy('migration')->pluck('migration')->all()!==$targetContract['migrations'])throw new LogicException('Target schema changed; re-review target contract first');
    $receipt=$source->table('svs_projection.receipt')->sole();
    if(!hash_equals($receipt->source_hash,$argv[4])||!hash_equals($receipt->projection_hash,App\ClubUnio\Services\SvsProjectionVersion::digest($root)))throw new LogicException('Stale or unverified source projection');
    $mapping=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v2.json'),true,512,JSON_THROW_ON_ERROR);
    // Frozen contract must cover every populated projection table. No silent new modules.
    foreach($source->select("select tablename from pg_tables where schemaname='clubunio'") as $t){
        if(!isset($mapping[$t->tablename])&&!in_array($t->tablename,['imports','migrations'],true)&&$source->table('clubunio.'.$t->tablename)->exists())throw new LogicException('Unmapped populated projection table');
    }
    foreach($mapping as $table=>$contract){
        $columns=$target->getSchemaBuilder()->getColumnListing('clubunio.'.$table);
        if(array_diff(array_column($contract['columns'],'name'),$columns))throw new LogicException('Target schema incompatible with mapping');
    }
    $key=$private['APP_KEY']??'';
    if(!str_starts_with($key,'base64:'))throw new LogicException('Private target encryption key required');
    $crypt=new Illuminate\Encryption\Encrypter(base64_decode(substr($key,7),true),'AES-256-CBC');
    $sourceCrypt=app('encrypter');
    $version=hash('sha256',hash_file('sha256',$root.'/deploy/svs-import-mapping-v2.json').$receipt->projection_hash);
    $runner=new App\ClubUnio\Services\SvsDeltaImporter($source,$target,$mapping,$sourceCrypt,$crypt,$version);
    echo json_encode($runner->run($argv[1]==='--apply',$argv[4],$argv[3],$upgradeV1),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'SVS merge stopped ['.get_class($e).']; no private field values logged.'.PHP_EOL);
    if($e instanceof LogicException)fwrite(STDERR,$e->getMessage().PHP_EOL);
    if(isset($runner))fwrite(STDERR,json_encode($runner->report(),JSON_THROW_ON_ERROR).PHP_EOL);
    if($e instanceof Illuminate\Database\QueryException)fwrite(STDERR,'SQLSTATE '.$e->getCode().PHP_EOL);
    exit(1);
}
