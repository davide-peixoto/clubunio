<?php
// CLI-only synthetic marketing fixture. No legacy imports or provider calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{PlatformAdministrationService,TeamPlanningService};
use Illuminate\Support\Facades\{DB,Http,Mail};
if (($argv[1] ?? '') !== '--create') { throw new LogicException('Use --create for the isolated FC Horizon demo only.'); }
Http::preventStrayRequests(); Http::fake(); Mail::fake();
$db=DB::connection('clubunio');
$actor=Account::where('username','davide.peixoto@svsissach.ch')->firstOrFail();
app(PlatformAdministrationService::class)->authorize($actor);
if (Tenant::where('slug','fc-horizon-demo')->exists()) { echo "FC Horizon demo already exists; no changes.\n"; exit; }
$db->transaction(function() use($db,$actor) {
$t=app(PlatformAdministrationService::class)->create($actor,['name'=>'FC Horizon · DEMO','slug'=>'fc-horizon-demo','username'=>$actor->username,'reason'=>'Explicit user request: fictional marketing demo tenant and product screenshots.']);
$insert=fn($table,$data)=>$db->table('clubora.'.$table)->insertGetId($data+['tenant_id'=>$t->id]);
$season=$insert('seasons',['name'=>'2026/2027','starts_on'=>'2026-07-01','ends_on'=>'2027-06-30']);
$insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Saison','value_type'=>'integer','encrypted_value'=>Illuminate\Support\Facades\Crypt::encryptString((string)$season),'enabled'=>true,'is_secret'=>false]);
$teams=[]; foreach(['U11','U13','U15','U17'] as $name){$id=$insert('teams',['name'=>$name]);$teams[]=$insert('team_seasons',['team_id'=>$id,'season_id'=>$season,'label'=>'FC Horizon '.$name]);}
$members=[];
foreach([['Lena','Sommer'],['Noah','Berg'],['Mia','Keller'],['Luca','Stern'],['Emma','Wald'],['Finn','Blau'],['Lea','Winter'],['Leon','Feld'],['Nora','Bach'],['Elias','Mond'],['Anna','Tal'],['Paul','Sonne']] as $i=>[$first,$last]){
$members[]=$insert('members',['first_name'=>$first,'last_name'=>$last,'email'=>'demo'.($i+1).'@example.invalid','city'=>'Horizon','member_number'=>1001+$i,'birth_date'=>($i<8?'2012':'1988').'-03-15']);}
$status=$insert('expense_statuses',['name'=>'Offen','code'=>'O']);
foreach([48,75,32,120] as $i=>$amount){$expense=$insert('expenses',['member_id'=>$members[$i+8],'season_id'=>$season,'team_season_id'=>$teams[$i],'status_id'=>$status]);$insert('expense_lines',['expense_id'=>$expense,'amount'=>$amount,'notes'=>'DEMO']);}
TeamPlanningService::seedOptions($t->id);
$day=now('Europe/Zurich')->startOfDay();
foreach($teams as $i=>$team){for($d=0;$d<4;$d++){$date=$day->copy()->addDays($d)->setTime(17+$i%2,($i%2)*30);$insert('team_events',['team_season_id'=>$team,'title'=>'FC Horizon U'.[11,13,15,17][$i],'kind'=>'TRAINING','mode'=>'ABMELDEN','starts_at'=>$date->toIso8601String(),'ends_at'=>$date->copy()->addMinutes(90)->toIso8601String(),'location'=>'Horizon Arena','status'=>'AKTIV']);}}
echo 'Created FC Horizon demo tenant '.$t->id.' with 12 fictional members, 4 teams, 16 events and 4 expenses. No provider credentials or outbound actions.'.PHP_EOL;
});
