<?php
namespace Tests\Feature;
use App\ClubUnio\Mobile\MobileKeyboxService;
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileKeyboxTest extends TestCase {
    private $db; private $grammar; private Tenant $tenant; private Account $owner; private Account $trainer;
    private int $box; private int $busBox; private int $reservation; private array $tokens=[];
    protected function setUp(): void {
        parent::setUp(); if(getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Synthetic rollback fixtures only');
        $this->db=DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName()); $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name='clubunio-mobile-keybox-test'"); $this->db->statement("SET LOCAL lock_timeout='5s'");
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.native_keybox_enabled'=>true,'clubunio-mobile.native_bus_enabled'=>true,'cache.default'=>'array','hashing.bcrypt.rounds'=>4,
            'clubunio-mobile.keybox'=>['provider_enabled'=>true,'issuance_enabled'=>true,'operations_reviewed'=>true,'offline_revocation_acknowledged'=>true,'dispatch_after_response'=>false,'protocol'=>'legacy']]);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant=Tenant::create(['slug'=>'qa-keybox-'.bin2hex(random_bytes(6)),'name'=>'Synthetic keybox club']);
        $this->owner=$this->account('admin'); $this->trainer=$this->account('trainer');
        $member=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Trainer']);
        $this->insert('account_member_links',['account_id'=>$this->trainer->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'test']);
        $season=$this->insert('seasons',['name'=>'Selected season','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
        $this->insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Season','value_type'=>'string','encrypted_value'=>Crypt::encryptString((string)$season),'enabled'=>true,'is_secret'=>false]);
        foreach(['IGLOOHOME_CLIENT_ID'=>'fixture-client','IGLOOHOME_CLIENT_SECRET'=>'fixture-secret'] as $key=>$value) {
            $this->insert('tenant_settings',['area'=>'keybox','key'=>$key,'label'=>'Synthetic secret','value_type'=>'string','encrypted_value'=>Crypt::encryptString($value),'enabled'=>true,'is_secret'=>true]);
        }
        $team=$this->insert('teams',['name'=>'Synthetic team']); $ts=$this->insert('team_seasons',['team_id'=>$team,'season_id'=>$season,'label'=>'Selected team']);
        $this->insert('team_staff',['team_season_id'=>$ts,'member_id'=>$member]);
        $bus=$this->insert('buses',['name'=>'Synthetic bus']);
        foreach(['GENEHMIGT','STORNIERT'] as $status) $this->insert('bus_statuses',['code'=>$status,'name'=>$status]);
        $this->reservation=$this->insert('bus_reservations',['bus_id'=>$bus,'team_season_id'=>$ts,'member_id'=>$member,'status'=>'GENEHMIGT',
            'starts_at'=>now()->subHour()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String()]);
        foreach([$this->owner,$this->trainer] as $actor) $this->tokens[$actor->id]=$this->postJson('/api/mobile/v1/session',['username'=>$actor->username,'password'=>'synthetic-password','device_name'=>'Synthetic keybox'])->assertOk()->json('data.token');
        $this->temporarySchema();
        $this->box=$this->db->table('clubora.mobile_keyboxes')->insertGetId(['tenant_id'=>$this->tenant->id,'name'=>'Synthetic general box','provider_device_cipher'=>Crypt::encryptString('fixture-device'),'active'=>true]);
        $this->busBox=$this->db->table('clubora.mobile_keyboxes')->insertGetId(['tenant_id'=>$this->tenant->id,'name'=>'Synthetic bus box','provider_device_cipher'=>Crypt::encryptString('fixture-bus'),'bus_id'=>$bus,'active'=>true]);
        $this->db->table('clubora.mobile_keybox_grants')->insert(['tenant_id'=>$this->tenant->id,'keybox_id'=>$this->box,'account_id'=>$this->trainer->id,'active'=>true]);
        config(['clubunio-mobile.keybox.tenant_slugs'=>[$this->tenant->slug],'clubunio-mobile.keybox.approved_keybox_ids.'.$this->tenant->slug=>[$this->box,$this->busBox]]);
        $this->withToken($this->tokens[$this->owner->id]);
    }
    private function temporarySchema(): void {
        foreach(['tenants'=>'id bigint PRIMARY KEY','buses'=>'tenant_id bigint,id bigint,UNIQUE(tenant_id,id)',
            'tenant_accounts'=>'tenant_id bigint,account_id bigint,UNIQUE(tenant_id,account_id)',
            'bus_reservations'=>'tenant_id bigint,id bigint,UNIQUE(tenant_id,id)','mobile_sessions'=>'id uuid PRIMARY KEY'] as $table=>$definition) {
            $this->db->statement('CREATE TEMP TABLE keybox_fixture_'.$table.'('.$definition.') ON COMMIT DROP');
            $columns=$table==='tenants'?'id':($table==='mobile_sessions'?'id':($table==='tenant_accounts'?'tenant_id,account_id':'tenant_id,id'));
            $query='INSERT INTO pg_temp.keybox_fixture_'.$table.' SELECT '.$columns.' FROM clubora.'.$table;
            if($table==='tenants') $this->db->statement($query.' WHERE id=?',[$this->tenant->id]);
            elseif($table==='mobile_sessions') $this->db->statement($query.' WHERE account_id IN (?,?)',[$this->owner->id,$this->trainer->id]);
            else $this->db->statement($query.' WHERE tenant_id=?',[$this->tenant->id]);
        }
        $ddl=$this->db->pretend(fn ()=>(require database_path('clubunio/2026_11_04_000700_mobile_keybox_codes.php'))->up()); $this->assertCount(1,$ddl); $sql=$ddl[0]['query'];
        foreach(['tenants','buses','tenant_accounts','bus_reservations','mobile_sessions'] as $table) $sql=str_replace('REFERENCES clubora.'.$table.'(','REFERENCES pg_temp.keybox_fixture_'.$table.'(',$sql);
        foreach(['mobile_keyboxes','mobile_keybox_grants','mobile_keybox_commands'] as $table) {
            $sql=str_replace('CREATE TABLE clubora.'.$table,'CREATE TEMP TABLE '.$table,$sql); $sql=str_replace('clubora.'.$table,'pg_temp.'.$table,$sql);
        }
        $sql=preg_replace('/\n\);/',"\n) ON COMMIT DROP;",$sql);
        $this->assertStringNotContainsString('REFERENCES clubora.',$sql); $this->assertStringNotContainsString('ON clubora.',$sql); $this->db->unprepared($sql);
        $this->grammar=$this->db->getQueryGrammar();
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table,$prefix=null) {
                if(is_string($table)) foreach(['mobile_keyboxes','mobile_keybox_grants','mobile_keybox_commands'] as $own) $table=str_replace('clubora.'.$own,'pg_temp.'.$own,$table);
                return parent::wrapTable($table,$prefix);
            }
        });
    }
    protected function tearDown(): void { if(isset($this->db)) { if($this->db->transactionLevel()) $this->db->rollBack(); if($this->grammar) $this->db->setQueryGrammar($this->grammar); } parent::tearDown(); }
    private function insert(string $table,array $row): int { return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id'=>$this->tenant->id]+$row); }
    private function account(string $role): Account {
        $a=new Account; $a->forceFill(['username'=>'qa-keybox-'.bin2hex(random_bytes(5)),'display_name'=>'Synthetic '.$role,'password'=>bcrypt('synthetic-password'),'active'=>true,'api_only'=>false])->save();
        $this->insert('tenant_accounts',['account_id'=>$a->id,'role'=>$role,'portal_enabled'=>true]); return $a;
    }
    private function base(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id; }
    private function fakeProvider(bool $success=true): void {
        Http::fake(['https://auth.igloohome.co/oauth2/token'=>Http::response(['access_token'=>'synthetic-access-token']),
            'https://api.igloodeveloper.co/igloohome/devices/*/algopin/hourly'=>Http::response($success?['pin'=>'123456789','pinId'=>'synthetic-pin-id']:['error'=>'synthetic uncertain provider'], $success?200:503)]);
    }
    public function test_profile_hourly_contract_is_personal_encrypted_at_most_once_and_never_reveals_provider_secrets(): void {
        $url=$this->base().'/keyboxes/'.$this->box.'/codes';
        $this->getJson($this->base().'/keyboxes')->assertOk()->assertJsonCount(2,'data.boxes')->assertDontSee('fixture-secret')->assertDontSee('fixture-device');
        $this->getJson($url)->assertOk()->assertJsonPath('data.revision',1)->assertJsonPath('data.issuanceEnabled',true);
        $input=['request_key'=>(string)Str::uuid(),'keybox_revision'=>1];
        $id=$this->postJson($url,$input)->assertStatus(202)->assertJsonPath('data.state','held')->json('data.id');
        $this->fakeProvider(); app(MobileKeyboxService::class)->execute($id); app(MobileKeyboxService::class)->execute($id);
        $pin=$this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.state','issued')->assertJsonPath('data.pin','123456789')->json('data');
        $this->assertSame(168,(int)\Carbon\CarbonImmutable::parse($pin['startsAt'])->diffInHours(\Carbon\CarbonImmutable::parse($pin['expiresAt'])));
        $cipher=$this->db->table('clubora.mobile_keybox_commands')->where('id',$id)->value('pin_encrypted'); $this->assertStringNotContainsString('123456789',$cipher);
        $this->postJson($url,$input)->assertStatus(202)->assertJsonPath('data.id',$id);
        Http::assertSentCount(2); Http::assertSent(fn ($r)=>str_contains($r->url(),'/algopin/hourly')&&$r['variance']===1&&$r['accessName']==='Synthetic admin');
        $this->withToken($this->tokens[$this->trainer->id])->getJson($url.'/'.$id)->assertNotFound();
        $this->getJson($this->base().'/keyboxes')->assertOk()->assertJsonCount(1,'data.boxes'); Mail::assertNothingSent();
    }
    public function test_revision_and_revoked_session_close_queued_work_without_any_provider_request(): void {
        $url=$this->base().'/keyboxes/'.$this->box.'/codes';
        $this->postJson($url,['request_key'=>(string)Str::uuid(),'keybox_revision'=>2])->assertConflict();
        $id=$this->postJson($url,['request_key'=>(string)Str::uuid(),'keybox_revision'=>1])->assertStatus(202)->json('data.id');
        $this->db->table('clubora.mobile_sessions')->where('account_id',$this->owner->id)->update(['revoked_at'=>now()->toIso8601String()]);
        app(MobileKeyboxService::class)->execute($id);
        $this->assertSame('invalidated',$this->db->table('clubora.mobile_keybox_commands')->where('id',$id)->value('state')); Http::assertNothingSent();
        config(['clubunio-mobile.native_keybox_enabled'=>false]);
        $this->withToken($this->tokens[$this->trainer->id])->getJson($this->base().'/keyboxes')->assertStatus(503);
        app(MobileKeyboxService::class)->invalidateReservation($this->tenant,$this->reservation); Mail::assertNothingSent();
    }
    public function test_bus_real_reservation_scope_invalidates_local_output_and_blocks_new_issue_pending_physical_revoke(): void {
        $this->withToken($this->tokens[$this->trainer->id]); $url=$this->base().'/bus/reservations/'.$this->reservation.'/keybox-codes';
        $this->getJson($url)->assertOk()->assertJsonPath('data.issuanceEnabled',true)->assertJsonPath('data.simulationMode',false);
        $id=$this->postJson($url,['request_key'=>(string)Str::uuid(),'keybox_revision'=>1,'reservation_revision'=>1])->assertStatus(202)->json('data.id');
        $this->fakeProvider(); app(MobileKeyboxService::class)->execute($id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.pin','123456789');
        $this->withToken($this->tokens[$this->owner->id])->postJson($this->base().'/bus/reservations/'.$this->reservation.'/cancel',['revision'=>1])->assertOk();
        $this->getJson($url)->assertForbidden();
        $row=$this->db->table('clubora.mobile_keybox_commands')->where('id',$id)->first();
        $this->assertSame('required',$row->remote_revocation_state); $this->assertNull($row->pin_encrypted);
        $this->postJson($this->base().'/keyboxes/'.$this->busBox.'/codes',['request_key'=>(string)Str::uuid(),'keybox_revision'=>1])->assertStatus(503); Http::assertSentCount(2); Mail::assertNothingSent();
    }
    public function test_uncertain_provider_result_never_retries_or_returns_a_simulated_pin(): void {
        $url=$this->base().'/keyboxes/'.$this->box.'/codes';
        $id=$this->postJson($url,['request_key'=>(string)Str::uuid(),'keybox_revision'=>1])->assertStatus(202)->json('data.id');
        $this->fakeProvider(false); app(MobileKeyboxService::class)->execute($id); app(MobileKeyboxService::class)->execute($id);
        $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.state','attention')->assertJsonMissingPath('data.pin'); Http::assertSentCount(2);
        $this->getJson($url)->assertOk()->assertJsonPath('data.issuanceEnabled',false); Mail::assertNothingSent();
    }
}
