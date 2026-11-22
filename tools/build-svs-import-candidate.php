<?php
declare(strict_types=1);

// Isolated, disposable staging projection; never reads the live SQL Server.
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
require __DIR__.'/svs-lab-autoload.php';
$phase='guard';
try {
    if (count($argv)!==4 || !preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[1])) throw new LogicException('Disposable candidate DB required');
    [$unused,$name,$snapshot,$manifestHash]=$argv;
    if (!hash_equals($manifestHash,hash_file('sha256',$snapshot.'/manifest.json'))) throw new LogicException('Snapshot hash mismatch');
    $manifest=json_decode(file_get_contents($snapshot.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if ($manifest['source']!=='db_acdf8d_mysvs'||$manifest['status']!=='complete') throw new LogicException('Verified source required');
    $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['logging.default'=>'null','mail.default'=>'array','queue.default'=>'sync','database.default'=>'clubunio','database.migrations.table'=>'clubunio.migrations']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    if (config('clubunio.outbound_enabled')) throw new LogicException('Outbound must be off');
    $connection=config('database.connections.clubunio');$connection['database']=$name;
    config(['database.connections.clubunio'=>$connection]);
    Illuminate\Support\Facades\DB::purge('clubunio');$db=Illuminate\Support\Facades\DB::connection('clubunio');
    App\ClubUnio\Services\StageMigrationTarget::authorize($db,$name);
    if ($db->selectOne("SELECT count(*) AS n FROM pg_namespace WHERE nspname NOT IN ('public','pg_catalog','information_schema') AND nspname NOT LIKE 'pg_%'")->n!=0) throw new LogicException('Candidate is not empty');
    $quote=fn($s)=>'"'.str_replace('"','""',$s).'"';
    $db->transaction(function()use($db,$manifest,$snapshot,$root,$quote,&$phase){
        $db->statement('CREATE SCHEMA legacy_prod');$db->statement('REVOKE ALL ON SCHEMA legacy_prod FROM PUBLIC');
        foreach($manifest['tables'] as $source=>$table){
            $phase='load '.$source;
            $file=$snapshot.'/'.$table['file'];
            if (basename($table['file'])!==$table['file']||!hash_equals($table['file_sha256'],hash_file('sha256',$file))) throw new LogicException('Snapshot file mismatch');
            [$schema,$name]=explode('.',$source,2);$target=$schema==='dbo'?$name:$schema.'__'.$name;
            $defs=[];
            foreach($table['columns'] as $c){
                $type=match(strtolower($c['type_name'])){
                    'bigint'=>'bigint','int'=>'integer','smallint','tinyint'=>'smallint','bit'=>'boolean',
                    'numeric','decimal'=>'numeric('.(int)$c['precision'].','.(int)$c['scale'].')',
                    'money'=>'numeric(19,4)','smallmoney'=>'numeric(10,4)','float'=>'double precision','real'=>'real',
                    'date'=>'date','datetime','smalldatetime'=>'timestamp without time zone',
                    'binary','varbinary','image','timestamp','rowversion'=>'bytea',
                    default=>'text',
                };
                $defs[]=$quote($c['name']).' '.$type;
            }
            $defs[]='"_migration_row" bigint PRIMARY KEY';
            $db->statement('CREATE TABLE legacy_prod.'.$quote($target).' ('.implode(',',$defs).')');
            $gz=gzopen($file,'rb');$batch=[];$count=0;$hash=hash_init('sha256');
            while(($line=gzgets($gz))!==false){
                hash_update($hash,$line);$row=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                foreach($table['columns'] as $c){if(in_array(strtolower($c['type_name']),['binary','varbinary','image','timestamp','rowversion'],true)&&$row[$c['name']]!==null)$row[$c['name']]='\\x'.$row[$c['name']];}
                $row['_migration_row']=++$count;$batch[]=$row;
                if(count($batch)>=250){$db->insert('INSERT INTO legacy_prod.'.$quote($target).' SELECT * FROM json_populate_recordset(NULL::legacy_prod.'.$quote($target).',?::json)',[json_encode($batch,JSON_THROW_ON_ERROR)]);$batch=[];}
            }
            gzclose($gz);
            if($batch)$db->insert('INSERT INTO legacy_prod.'.$quote($target).' SELECT * FROM json_populate_recordset(NULL::legacy_prod.'.$quote($target).',?::json)',[json_encode($batch,JSON_THROW_ON_ERROR)]);
            if($count!==$table['rows']||!hash_equals($table['row_stream_sha256'],hash_final($hash)))throw new LogicException('Snapshot row verification failed');
        }
        $db->statement('CREATE SCHEMA clubunio');$db->statement('REVOKE ALL ON SCHEMA clubunio FROM PUBLIC');
        $checkpoints=[
            '2026_10_11_000001_create_foundation'=>'clubunio:import-foundation',
            '2026_10_11_000002_add_club_objects'=>'clubunio:import-objects',
            '2026_10_11_000003_expand_members'=>'clubunio:import-member-details',
            '2026_10_11_000004_team_assignments'=>'clubunio:import-team-assignments',
            '2026_10_11_000005_member_dossier'=>'clubunio:import-member-dossier',
            '2026_10_11_000008_account_member_access'=>'clubunio:import-account-links',
            '2026_10_11_000010_registration_details'=>'clubunio:import-registration-details',
            '2026_10_11_000015_contributions_training'=>'clubunio:import-contributions-training',
            '2026_10_12_000019_status_styles'=>'clubunio:import-facilities',
            '2026_10_12_000022_team_planning'=>'clubunio:planning-import',
            '2026_10_12_000032_football_clubs'=>'clubunio:import-football-clubs',
        ];
        $files=glob($root.'/database/clubunio/*.php');sort($files,SORT_STRING);
        foreach($files as $file){
            $phase=basename($file,'.php');
            if(Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'clubunio','--path'=>'database/clubunio/'.basename($file),'--force'=>true])!==0)throw new RuntimeException('Candidate migration failed');
            if(isset($checkpoints[$phase])){if(Illuminate\Support\Facades\Artisan::call($checkpoints[$phase])!==0)throw new RuntimeException('Candidate import failed');}
        }
        $tenant=App\ClubUnio\Models\Tenant::where('slug','sv-sissach')->sole();
        app(App\ClubUnio\Services\MessageTemplateService::class)->seed($tenant);
        app(App\ClubUnio\Services\StatusStyleService::class)->seed($tenant);
        $db->table('clubunio.accounts')->update(['active'=>false,'password'=>'']);
        $db->table('clubunio.tenant_accounts')->update(['portal_enabled'=>false]);
        $db->table('clubunio.tenant_settings')->where('is_secret',true)->update(['enabled'=>false,'encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString('')]);
        $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $db->statement('CREATE SCHEMA svs_projection');
        $db->statement('REVOKE ALL ON SCHEMA svs_projection FROM PUBLIC');
        $db->statement('CREATE TABLE svs_projection.receipt (source_hash char(64) NOT NULL, projection_hash char(64) NOT NULL)');
        $db->table('svs_projection.receipt')->insert(['source_hash'=>hash_file('sha256',$snapshot.'/manifest.json'),'projection_hash'=>App\ClubUnio\Services\SvsProjectionVersion::digest($root)]);
    });
    echo json_encode(['candidate'=>$argv[1],'snapshot_sha256'=>$manifestHash,'status'=>'built','outbound'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'Candidate failed at '.$phase.' ['.get_class($e).']; no source values logged.'.PHP_EOL);
    if($e instanceof Illuminate\Database\QueryException){
        // Constraint name/SQLSTATE only; database exception text can contain private rows.
        fwrite(STDERR,'SQLSTATE '.$e->getCode().PHP_EOL);
    }
    exit(1);
}
