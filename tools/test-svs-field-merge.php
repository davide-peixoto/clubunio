<?php
declare(strict_types=1);
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
$base=config('database.connections.clubunio');
$candidate='clubunio_stage_svs_candidate_a1b2c3d4';$copy='clubunio_svs_rehearsal_b2c3d4e5';
foreach(['s'=>$candidate,'t'=>$copy] as $name=>$db){$c=$base;$c['database']=$db;config(['database.connections.'.$name=>$c]);}
$s=Illuminate\Support\Facades\DB::connection('s');$t=Illuminate\Support\Facades\DB::connection('t');
if($s->selectOne('select current_database() as n')->n!==$candidate||$t->selectOne('select current_database() as n')->n!==$copy)throw new LogicException('Only isolated test databases allowed');
$s->beginTransaction();$t->beginTransaction();
try{
    $note=$s->table('clubunio.member_notes')->first();
    $s->table('clubunio.member_notes')->where('id',$note->id)->update(['body'=>'Synthetic remote field update']);
    $tenant=$t->table('clubunio.tenants')->where('slug','sv-sissach')->value('id');
    $t->table('clubunio.member_notes')->where('tenant_id',$tenant)->where('legacy_id',$note->legacy_id)->update(['active'=>!$note->active,'updated_at'=>'2000-01-01 00:00:00']);
    $mapping=json_decode(file_get_contents($root.'/deploy/svs-import-mapping-v1.json'),true,512,JSON_THROW_ON_ERROR);
    $receipt=$s->table('svs_projection.receipt')->sole();
    $version=hash('sha256',hash_file('sha256',$root.'/deploy/svs-import-mapping-v1.json').$receipt->projection_hash);
    $v=Dotenv\Dotenv::parse(file_get_contents($root.'/.env.import-target.local'));
    $crypt=new Illuminate\Encryption\Encrypter(base64_decode(substr($v['APP_KEY'],7),true),'AES-256-CBC');
    $runner=new App\ClubUnio\Services\SvsDeltaImporter($s,$t,$mapping,app('encrypter'),$crypt,$version);
    $r=$runner->run(true,$receipt->source_hash,$copy);
    $actual=$t->table('clubunio.member_notes')->where('tenant_id',$tenant)->where('legacy_id',$note->legacy_id)->sole();
    if($r['updated']!==1||$r['inserted']!==0||$actual->body!=='Synthetic remote field update'||$actual->active===$note->active||str_starts_with($actual->updated_at,'2000-01-01'))throw new RuntimeException('Independent field merge or modification timestamp failed');
    echo 'Same-record independent fields merged; local field preserved; both outer test transactions rolled back.'.PHP_EOL;
}catch(Throwable $e){$failed=true;fwrite(STDERR,'Field merge test failed ['.get_class($e).']; private data suppressed.'.PHP_EOL);}
finally{$s->rollBack();$t->rollBack();}
if(isset($failed))exit(1);
