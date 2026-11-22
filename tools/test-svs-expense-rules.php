<?php
declare(strict_types=1);
// Business-rule negative cases on the disposable projection, all rolled back.
$root=dirname(__DIR__);require __DIR__.'/svs-lab-autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['logging.default'=>'null']);Illuminate\Support\Facades\Http::preventStrayRequests();
if(count($argv)!==2||!preg_match('/\Aclubunio_stage_svs_candidate_[a-f0-9]{8}\z/',$argv[1]))throw new LogicException('Disposable projection required');
$c=config('database.connections.clubunio');$c['database']=$argv[1];config(['database.connections.clubunio'=>$c]);Illuminate\Support\Facades\DB::purge('clubunio');$db=Illuminate\Support\Facades\DB::connection('clubunio');App\ClubUnio\Services\StageMigrationTarget::authorize($db,$argv[1]);
$tenant=$db->table('clubunio.tenants')->where('slug','sv-sissach')->sole()->id;$importer=new App\ClubUnio\Services\SvsSupplementImporter($db);$reflection=new ReflectionClass($importer);$reflection->getProperty('tenant')->setValue($importer,$tenant);$method=$reflection->getMethod('expenses');
$passed=[];
try{
 foreach(['reviewed','unknown','ambiguous'] as $case){
  $db->beginTransaction();try{
   $source=$db->table('legacy_prod.Spesenbelege')->where('SpesenID',102)->sole();$expense=$db->table('clubunio.expenses')->where('legacy_id',102)->sole();
   $old=$db->table('clubunio.team_seasons')->where('legacy_id',$source->TeamSaisonID)->sole();
   $db->table('clubunio.expenses')->where('id',$expense->id)->update(['team_season_id'=>$old->id]);$db->table('clubunio.svs_expense_season_reviews')->where('expense_id',$expense->id)->delete();
   if($case==='unknown'){$db->table('legacy_prod.Spesenbelege')->where('SpesenID',102)->update(['SpesenID'=>900000001]);$db->table('clubunio.expenses')->where('id',$expense->id)->update(['legacy_id'=>900000001]);}
   if($case==='ambiguous'){$duplicate=(array)$db->table('legacy_prod.TeamsSaison')->where('TeamSaisonID',1471)->sole();$duplicate['TeamSaisonID']=900000001;$duplicate['_migration_row']=900000001;$db->table('legacy_prod.TeamsSaison')->insert($duplicate);}
   $blocked=false;try{$method->invoke($importer);}catch(LogicException|Illuminate\Database\MultipleRecordsFoundException $e){$blocked=true;}
   if(($case==='reviewed'&&($blocked||$db->table('clubunio.expenses')->where('id',$expense->id)->value('team_season_id')!==$expense->team_season_id))||($case!=='reviewed'&&(!$blocked||$db->table('clubunio.expenses')->where('id',$expense->id)->value('team_season_id')!==$old->id)))throw new RuntimeException('Rule assertion: '.$case);
   $passed[$case]=true;
  }finally{$db->rollBack();}
 }
 $definition=json_decode($db->table('legacy_prod.DokumentVorlagen')->first()->Definition,true,512,JSON_THROW_ON_ERROR);
 foreach(['Menge','PreisRappen'] as $field){$fixture=$definition;$fixture['Positionen'][0][$field]=1.5;$blocked=false;try{$reflection->getMethod('definition')->invoke($importer,$fixture);}catch(Illuminate\Validation\ValidationException){$blocked=true;}if(!$blocked)throw new RuntimeException('Fractional source item must fail validation');$passed['fractional_'.$field.'_rejected']=true;}
 echo json_encode(['status'=>'passed','cases'=>$passed,'all_fixtures_rolled_back'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Expense rule test failed ['.get_class($e).']; private values suppressed.'.PHP_EOL);if($e instanceof RuntimeException&&!($e instanceof Illuminate\Database\QueryException))fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
