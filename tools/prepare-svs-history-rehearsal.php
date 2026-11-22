<?php
declare(strict_types=1);
// Apply only the supplemental schema to a verified independent PROD restore.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';
if(count($argv)!==2||!preg_match('/\Aclubunio_svs_rehearsal_[a-f0-9]{8}\z/',$argv[1]))throw new LogicException('Rehearsal only');
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=config('database.connections.clubunio');$c['database']=$argv[1];config(['database.connections.clubunio'=>$c]);Illuminate\Support\Facades\DB::purge('clubunio');
$db=Illuminate\Support\Facades\DB::connection('clubunio');App\ClubUnio\Services\SvsRehearsalTarget::authorize($db,$argv[1]);
$file='2026_11_22_020000_svs_historical_import';
$db->transaction(function()use($db,$root,$file){
    if($db->table('clubunio.migrations')->where('migration',$file)->exists())throw new LogicException('Already prepared');
    (require $root.'/database/clubunio/'.$file.'.php')->up();
    $db->table('clubunio.migrations')->insert(['migration'=>$file,'batch'=>1+(int)$db->table('clubunio.migrations')->max('batch')]);
});
echo "Isolated supplemental schema prepared\n";
