<?php
declare(strict_types=1);
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
if(count($argv)!==3||$argv[1]!=='clubunio_prod')throw new LogicException('Exact production target required');
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
$c=config('database.connections.clubunio');$c['database']='clubunio_prod';config(['database.connections.clubunio'=>$c]);Illuminate\Support\Facades\DB::purge('clubunio');$db=Illuminate\Support\Facades\DB::connection('clubunio');
App\ClubUnio\Services\SvsProductionTarget::authorize($db,getenv('CLUBUNIO_SVS_PRODUCTION_PERMIT')?:'', $argv[2]);
$name='2026_11_22_020000_svs_historical_import';
$db->transaction(function()use($db,$root,$name){if($db->table('clubunio.migrations')->where('migration',$name)->exists())return;(require $root.'/database/clubunio/'.$name.'.php')->up();$db->table('clubunio.migrations')->insert(['migration'=>$name,'batch'=>1+(int)$db->table('clubunio.migrations')->max('batch')]);});
echo "Authorized PROD history schema ready\n";
