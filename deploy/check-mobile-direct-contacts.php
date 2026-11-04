<?php

namespace ClubUnioRelease;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail,Queue};
use Illuminate\Support\Str;

/** Real kernel and production policy; only synthetic rows in one verified rollback. */
final class MobileDirectContactsProbe
{
    public string $stage = 'bootstrap';
    public array $queryBudget = [];
    public array $searchBudgets = [];
    private int $checks = 0;
    private function expect(bool $condition, string $stage): void
    {
        $this->stage = $stage;
        if (!$condition) throw new \LogicException('Synthetic direct contact contract failed');
        $this->checks++;
    }
    public function run($app): int
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new \LogicException('Standalone reviewed PostgreSQL required');
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        $kernel = $app->make(Kernel::class); $ids = []; $tenantId = null;
        $call = function(string $method, string $path, string $token, array $body = [], int $expected = 200) use($kernel): array {
            $this->stage = $method.' '.$path;
            $r = Request::create('https://dev.clubunio.ch/api/mobile/v1/'.$path, $method, [], [], [],
                ['HTTPS'=>'on','HTTP_HOST'=>'dev.clubunio.ch','REMOTE_ADDR'=>'127.0.0.1','HTTP_ACCEPT'=>'application/json',
                    'CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token], json_encode($body,JSON_THROW_ON_ERROR));
            $response = $kernel->handle($r);
            $this->expect($response->getStatusCode() === $expected, $this->stage.' expected'.$expected.' actual'.$response->getStatusCode());
            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        };
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-direct-contacts-rollback'");
            $db->statement("SET LOCAL lock_timeout TO '2s'"); $db->statement("SET LOCAL statement_timeout TO '15s'");
            $tenant = Tenant::create(['slug'=>'direct-contacts-'.bin2hex(random_bytes(10)), 'name'=>'Synthetic direct contacts']); $tenantId = (int)$tenant->id;
            $insert = fn(string $table, array $data): int => $db->table('clubora.'.$table)->insertGetId($data + ['tenant_id'=>$tenantId]);
            $actors=[]; $members=[]; $tokens=[];
            $make = function(string $key, string $role='member', string $birth='1980-01-01') use($db,$tenantId,$insert,&$actors,&$members,&$tokens,&$ids): void {
                $a=new Account; $a->forceFill(['username'=>'direct-'.bin2hex(random_bytes(12)), 'display_name'=>'Synthetic '.$key,
                    'password'=>'not-a-password-login', 'active'=>true, 'api_only'=>false])->save();
                $ids[]=(int)$a->id; $actors[$key]=$a;
                $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$tenantId,'account_id'=>$a->id,'role'=>$role,'portal_enabled'=>$role!=='profile', 'access_profile_id'=>$role==='profile' ? $insert('access_profiles',['name'=>'Synthetic contact profile','permissions'=>'[]']) : null]);
                $members[$key]=$insert('members',['first_name'=>'Synthetic','last_name'=>$key,'birth_date'=>$birth]);
                $insert('account_member_links',['account_id'=>$a->id,'member_id'=>$members[$key],'kind'=>'self','state'=>'approved','source'=>'synthetic-test']);
                $session=(string)Str::uuid(); $secret=bin2hex(random_bytes(32));
                $db->table('clubora.mobile_sessions')->insert(['id'=>$session,'account_id'=>$a->id,'token_hash'=>hash('sha256',$secret),
                    'device_name'=>'Synthetic contacts','expires_at'=>now()->addMinutes(10)->toIso8601String(),'created_at'=>now()->toIso8601String()]);
                $tokens[$key]=$session.'.'.$secret;
            };
            foreach(['player'=>'member','peer'=>'member','parent'=>'member','trainer'=>'trainer','assistant'=>'trainer',
                'foreignStaff'=>'trainer','foreignPlayer'=>'member','child'=>'member','childStaff'=>'trainer',
                'pendingParent'=>'member','lead'=>'technical_lead','admin'=>'admin','office'=>'office','profile'=>'profile',
                'inactive'=>'member','api'=>'member','closedPortal'=>'member','ended'=>'member'] as $key=>$role) {
                $make($key,$role,in_array($key,['player','peer','child','foreignPlayer']) ? '2014-01-01' : '1980-01-01');
            }
            $season=$insert('seasons',['name'=>'Synthetic current','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
            $old=$insert('seasons',['name'=>'Synthetic old']);
            $insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic season','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$season)]);
            $teams=[];
            foreach(['own','other','child','old','inactive'] as $key) $teams[$key]=$insert('team_seasons',[
                'team_id'=>$insert('teams',['name'=>'Synthetic '.$key]),'season_id'=>$key==='old' ? $old : $season,
                'label'=>'Synthetic '.$key,'active'=>$key!=='inactive']);
            $trainerFunction=$insert('staff_functions',['code'=>'TRAINER','name'=>'Trainer']);
            $assistantFunction=$insert('staff_functions',['code'=>'ASSISTANT_TRAINER','name'=>'Assistenztrainer']);
            $helperFunction=$insert('staff_functions',['code'=>'HELPER','name'=>'Helfer']);
            $assign=function(string $table,string $key,string $team,?int $function=null) use($insert,&$members,$teams,$trainerFunction): int {
                $data=['member_id'=>$members[$key],'team_season_id'=>$teams[$team]];
                if ($table==='team_staff') $data['staff_function_id']=$function ?? $trainerFunction;
                return $insert($table,$data);
            };
            foreach(['player','peer','inactive','api','closedPortal','ended','profile'] as $key) $assign('team_players',$key,'own');
            $assign('team_staff','trainer','own'); $assign('team_staff','assistant','own',$assistantFunction);
            $assign('team_staff','trainer','child',$helperFunction);
            $foreignStaffAssignment=$assign('team_staff','foreignStaff','other'); $assign('team_players','foreignPlayer','other');
            $assign('team_players','child','child'); $assign('team_staff','childStaff','child');
            foreach(['parent','trainer','pendingParent'] as $key) $insert('account_member_links',['account_id'=>$actors[$key]->id,
                'member_id'=>$members['child'],'kind'=>'parent','state'=>$key==='pendingParent' ? 'pending' : 'approved','source'=>'synthetic-test']);
            // A player's parent in the trainer's actual roster is also a legitimate trainer contact.
            $parentLink=$insert('account_member_links',['account_id'=>$actors['parent']->id,'member_id'=>$members['player'],'kind'=>'parent','state'=>'approved','source'=>'synthetic-test']);
            $db->table('clubora.accounts')->where('id',$actors['inactive']->id)->update(['active'=>false]);
            $db->table('clubora.accounts')->where('id',$actors['api']->id)->update(['api_only'=>true]);
            $db->table('clubora.tenant_accounts')->where('account_id',$actors['closedPortal']->id)->update(['portal_enabled'=>false]);
            $db->table('clubora.members')->where('id',$members['ended'])->update(['exit_date'=>now()->subDay()->toDateString()]);
            $base='clubs/'.$tenantId.'/';
            $contacts=function(string $key,string $query='') use($call,$base,&$tokens): array {
                $data=$call('GET',$base.'contacts'.$query,$tokens[$key])['data'];
                foreach($data as $row) $this->expect(array_keys($row)===['id','name'] && is_int($row['id']) && is_string($row['name']),'Minimal contact projection');
                return array_column($data,'id');
            };
            $checkSet=function(array $actual,array $keys,string $label) use(&$actors): void {
                $expected=array_map(fn($key)=>(int)$actors[$key]->id,$keys); sort($actual); sort($expected);
                $this->expect($actual===$expected,$label);
            };
            $checkSet($contacts('player'),['peer','trainer','assistant'],'Player sees only actual own player/staff accounts');
            $checkSet($contacts('parent'),['trainer','assistant','childStaff'],'Parent sees staff only across both confirmed child teams');
            $db->table('clubora.account_member_links')->where('tenant_id',$tenantId)->where('account_id',$actors['parent']->id)->where('kind','self')->delete();
            $checkSet($contacts('parent'),['trainer','assistant','childStaff'],'Confirmed parent without a self link still has legitimate access');
            $insert('account_member_links',['account_id'=>$actors['parent']->id,'member_id'=>$members['parent'],'kind'=>'self','state'=>'approved','source'=>'synthetic-test']);
            $checkSet($contacts('pendingParent'),[],'Pending parent grants no child team');
            $checkSet($contacts('trainer'),['player','peer','assistant','parent','foreignStaff','childStaff'],'Trainer combines roster parents and club staff; helper in own child team never grants child roster');
            $this->expect(in_array($actors['player']->id,$contacts('assistant'),true),'Canonical assistant trainer has own roster grant');
            $checkSet($contacts('foreignStaff'),['foreignPlayer','trainer','assistant','childStaff'],'Trainer cannot see other teams players or their parents');
            foreach(['admin','office','lead'] as $key) {
                $found=$contacts($key);
                $this->expect(in_array($actors['foreignPlayer']->id,$found,true) && in_array($actors['parent']->id,$found,true),'Broad authorized '.$key);
                foreach(['profile','inactive','api','closedPortal','ended'] as $denied) $this->expect(!in_array($actors[$denied]->id,$found,true),'Broad list excludes '.$denied);
            }
            $checkSet($contacts('player','?q=peer&limit=1'),['peer'],'Server scoped text search');
            $checkSet($contacts('player','?q=%25'),[],'Literal wildcard is not a browse escape');
            $call('GET',$base.'contacts?limit=41',$tokens['player'],[],422);
            $call('GET',$base.'contacts?q='.str_repeat('x',121),$tokens['player'],[],422);
            $call('GET',$base.'contacts',$tokens['profile'],[],403);
            $direct=function(string $from,string $to,int $status=200) use($call,$base,&$tokens,&$actors): array {
                return $call('POST',$base.'direct',$tokens[$from],['account_id'=>$actors[$to]->id],$status);
            };
            $direct('player','foreignPlayer',404); $direct('parent','player',404); $direct('parent','foreignPlayer',404);
            $direct('player','admin',404); // Reverse broad role alone cannot initiate.
            $chat=$direct('admin','player')['data']['id'];
            $direct('player','admin'); // A legitimate established conversation permits a reply.
            $call('POST',$base.'chats/'.$chat.'/messages',$tokens['player'],['client_id'=>(string)Str::uuid(),'text'=>'Synthetic authorized reply'],201);
            $db->table('clubora.tenant_accounts')->where('account_id',$actors['admin']->id)->where('tenant_id',$tenantId)->update(['role'=>'member']);
            $call('GET',$base.'chats/'.$chat.'/messages',$tokens['player'],[],404);
            $call('POST',$base.'chats/'.$chat.'/messages',$tokens['player'],['client_id'=>(string)Str::uuid(),'text'=>'Must not send after role revocation'],404);
            $trainerChat=$direct('parent','trainer')['data']['id'];
            $db->table('clubora.account_member_links')->where('tenant_id',$tenantId)->where('account_id',$actors['parent']->id)->where('kind','parent')->update(['state'=>'revoked']);
            $call('GET',$base.'chats/'.$trainerChat.'/messages',$tokens['parent'],[],404);
            $db->table('clubora.account_member_links')->where('tenant_id',$tenantId)->where('account_id',$actors['parent']->id)->where('kind','parent')->update(['state'=>'approved']);
            $db->table('clubora.chat_blocks')->insert(['tenant_id'=>$tenantId,'account_id'=>$actors['trainer']->id,'other_account_id'=>$actors['parent']->id]);
            $call('GET',$base.'chats/'.$trainerChat.'/messages',$tokens['parent'],[],404);
            $call('POST',$base.'chats/'.$trainerChat.'/messages',$tokens['trainer'],['client_id'=>(string)Str::uuid(),'text'=>'Blocked existing chat'],404);
            $db->table('clubora.chat_blocks')->where('tenant_id',$tenantId)->delete();
            $db->table('clubora.chat_blocks')->insert(['tenant_id'=>$tenantId,'account_id'=>$actors['peer']->id,'other_account_id'=>$actors['player']->id]);
            $this->expect(!in_array($actors['peer']->id,$contacts('player'),true),'Reciprocal block removes discovery');
            $direct('player','peer',404); $direct('peer','player',404);
            $db->table('clubora.chat_blocks')->where('tenant_id',$tenantId)->delete();
            $db->table('clubora.team_staff')->where('id',$foreignStaffAssignment)->update(['active'=>false]);
            $this->expect(!in_array($actors['foreignStaff']->id,$contacts('trainer'),true),'Inactive staff assignment cannot grant club staff contact');
            // Current-season and inactive-team isolation even with a real active self link.
            $assign('team_staff','foreignStaff','old'); $assign('team_staff','foreignStaff','inactive');
            $this->expect(!in_array($actors['foreignStaff']->id,$contacts('trainer'),true),'Old or inactive teams cannot grant club staff contact');
            // Membership in another tenant is not a chat identity here.
            $make('foreignTenant');
            $db->table('clubora.account_member_links')->where('tenant_id',$tenantId)->where('account_id',$actors['foreignTenant']->id)->delete();
            $db->table('clubora.tenant_accounts')->where('tenant_id',$tenantId)->where('account_id',$actors['foreignTenant']->id)->delete();
            $foreignTenant=Tenant::create(['slug'=>'direct-foreign-'.bin2hex(random_bytes(10)),'name'=>'Synthetic foreign contacts']);
            $db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$foreignTenant->id,'account_id'=>$actors['foreignTenant']->id,'role'=>'admin','portal_enabled'=>true]);
            $assign('team_players','foreignTenant','own'); $direct('player','foreignTenant',404);
            // Measure the full authenticated HTTP path with one and then one hundred searchable candidates.
            $make('Budget000'); $assign('team_players','Budget000','own'); $assign('team_staff','Budget000','own');
            $measure=function(string $key) use($db,$contacts): array {
                $db->flushQueryLog(); $db->enableQueryLog(); $started=hrtime(true);
                try { $found=$contacts($key,'?q=Budget&limit=40'); $queries=$db->getQueryLog(); }
                finally { $db->disableQueryLog(); $db->flushQueryLog(); }
                return ['found'=>count($found),'sql'=>count($queries),'elapsed_ms'=>round((hrtime(true)-$started)/1e6,2)];
            };
            $before=[]; foreach(['player','trainer','parent'] as $key) $before[$key]=$measure($key);
            for($i=1;$i<100;$i++) {
                $key='Budget'.str_pad((string)$i,3,'0',STR_PAD_LEFT); $make($key);
                $assign('team_players',$key,'own'); $assign('team_staff',$key,'own');
            }
            foreach(['player','trainer','parent'] as $key) {
                $after=$measure($key); $small=$before[$key]; $this->searchBudgets[$key]=[$small,$after];
                $this->expect($small['found']===1 && $after['found']===40 && $small['sql']===$after['sql'] && $after['sql']<=24,
                    $key.' one versus one hundred candidate HTTP SQL budget');
            }
            $this->queryBudget=[$this->searchBudgets['player'][0]['sql'],$this->searchBudgets['player'][1]['sql']];
            $this->expect(Http::recorded()->isEmpty() && Mail::sent(Mailable::class)->isEmpty() && Mail::queued(Mailable::class)->isEmpty(),'No provider or mail');
        } finally { $db->disableQueryLog(); $db->flushQueryLog(); $db->rollBack(); }
        $this->expect($db->transactionLevel()===0 && !$db->table('clubora.tenants')->where('id',$tenantId)->exists()
            && !$db->table('clubora.accounts')->whereIn('id',$ids)->exists()
            && !$db->table('clubora.tenants')->where('id',$foreignTenant->id)->exists(),'Verified synthetic rollback');
        return $this->checks;
    }
}

if (defined('CLUBUNIO_DIRECT_CONTACTS_INCLUDE_ONLY') && constant('CLUBUNIO_DIRECT_CONTACTS_INCLUDE_ONLY')===true) return;
$probe=null;
try {
    if (PHP_SAPI!=='cli' || array_slice($argv,1)!==['--synthetic-rollback']) throw new \LogicException('Explicit synthetic rollback required');
    require dirname(__DIR__).'/vendor/autoload.php'; $app=require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url')!=='https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')) throw new \LogicException('Exact enabled DEV required');
    foreach(['clubunio-mobile.ai.enabled','clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled','clubunio-mobile.keybox.provider_enabled'] as $flag)
        if (config($flag,false)) throw new \LogicException('External providers must remain closed');
    $probe=new MobileDirectContactsProbe; $checks=$probe->run($app);
    echo 'Direct contacts: '.$checks.' checks; searches='.json_encode($probe->searchBudgets,JSON_THROW_ON_ERROR)."; verified synthetic rollback, no providers.\n";
} catch (\Throwable $error) {
    fwrite(STDERR,'Direct contacts gate failed at '.($probe?->stage ?? 'bootstrap').': '.get_class($error).' (private details suppressed).'.PHP_EOL); exit(1);
}
