<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Tenant,Account};use Illuminate\Support\Facades\{DB,Crypt,Http,Mail};use App\ClubUnio\Services\LookupTranslations;
Http::preventStrayRequests();Http::fake();Mail::fake();
$t=Tenant::where('slug','fc-horizon-demo')->where('name','FC Horizon · DEMO')->firstOrFail();$db=DB::connection('clubunio');
if($db->table('clubora.training_options')->where('tenant_id',$t->id)->exists()){echo "Display fixtures already exist.\n";exit;}
$db->transaction(function()use($t,$db){
$insert=fn($table,$data)=>$db->table('clubora.'.$table)->insertGetId($data+['tenant_id'=>$t->id]);
$actor=Account::where('username','fc-horizon-demo@example.invalid')->firstOrFail();
$translate=function($table,$id,$source,$en)use($actor,$t){app(LookupTranslations::class)->save($actor,$t,null,['source_table'=>$table,'source_id'=>(string)$id,'source_name'=>$source,'locale'=>'en','label'=>$en,'revision'=>0,'reason'=>'English display variant for fictional marketing demo data.']);};
$period=$insert('training_options',['category'=>'period','code'=>'2026','name'=>'2026/2027']);
$weekday=$insert('training_options',['category'=>'weekday','code'=>(string)(now('Europe/Zurich')->dayOfWeek+1),'name'=>'DEMO']);
$field=$insert('training_options',['category'=>'field','code'=>'H','name'=>'Horizon Arena']);
$insert('tenant_settings',['area'=>'general','key'=>'SIGNAGE_PERIOD_ID','label'=>'Signage','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$period),'enabled'=>true,'is_secret'=>false]);
foreach($db->table('clubora.team_seasons')->where('tenant_id',$t->id)->orderBy('id')->get() as $i=>$team){$insert('training_slots',['team_season_id'=>$team->id,'period_id'=>$period,'weekday_id'=>$weekday,'starts_at'=>($i%2?'18:30':'17:00'),'ends_at'=>($i%2?'20:00':'18:30'),'field_id'=>$field,'all_fields'=>true,'without_main'=>true,'synthetic_only'=>true]);}
$status=$db->table('clubora.expense_statuses')->where('tenant_id',$t->id)->first();$translate('expense_statuses',$status->id,$status->name,'Open');
echo "Fictional training schedule and English expense status added to FC Horizon only.\n";
});
