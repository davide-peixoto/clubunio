<?php
namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileKeyboxService, MobilePenaltyAccess};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

final class MobileBusProfileEntrypointsTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private string $base;
    protected function setUp(): void {
        parent::setUp();
        if(getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        $this->db=DB::connection('clubunio');
        self::assertSame('pgsql',$this->db->getDriverName()); self::assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction(); Http::preventStrayRequests(); Mail::fake();
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>false,'clubunio-mobile.native_bus_enabled'=>true,
            'clubunio-mobile.native_keybox_enabled'=>true,'clubunio-mobile.keybox.issuance_enabled'=>false,'clubunio-mobile.native_penalty_enabled'=>true,
            'hashing.bcrypt.rounds'=>4,'cache.default'=>'array']);
        $this->tenant=Tenant::create(['slug'=>'entry-'.bin2hex(random_bytes(6)),'name'=>'Synthetic club']);
        $this->actor=new Account;
        $this->actor->forceFill(['username'=>'entry-'.bin2hex(random_bytes(6)),'display_name'=>'Synthetic','password'=>bcrypt('fixture'),'active'=>true,'api_only'=>false])->save();
        $this->insert('tenant_accounts',['account_id'=>$this->actor->id,'role'=>'trainer','portal_enabled'=>true]);
        $member=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Trainer']);
        $this->insert('account_member_links',['account_id'=>$this->actor->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic']);
        $season=$this->insert('seasons',['name'=>'Synthetic current']);
        $this->insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$season)]);
        $token=$this->postJson('/api/mobile/v1/session',['username'=>$this->actor->username,'password'=>'fixture','device_name'=>'Synthetic'])->assertOk()->json('data.token');
        $this->withToken($token)->withHeader('X-ClubUnio-Mobile-Features','bus-v1,penalty-v1');
        $this->base='/api/mobile/v1/clubs/'.$this->tenant->id;
    }
    protected function tearDown(): void { if(isset($this->db)) while($this->db->transactionLevel()) $this->db->rollBack(); parent::tearDown(); }
    private function insert(string $table,array $values): int { return $this->db->table('clubora.'.$table)->insertGetId($values+['tenant_id'=>$this->tenant->id]); }
    public function test_empty_bus_counts_are_a_real_json_object_and_closed_flag_stays_closed(): void {
        foreach(['upcoming','past'] as $period) {
            $response=$this->getJson($this->base.'/bus/reservations?period='.$period)->assertOk()->assertJsonPath('data.total',0)->assertJsonCount(0,'data.reservations');
            self::assertInstanceOf(\stdClass::class,json_decode($response->getContent())->data->counts);
        }
        config(['clubunio-mobile.native_bus_enabled'=>false]); $this->getJson($this->base.'/bus/reservations')->assertStatus(503);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_profile_exposes_only_authorized_legal_keybox_empty_view_without_any_pin_grants(): void {
        $service=app(MobileKeyboxService::class);
        $this->getJson('/api/mobile/v1/profile')->assertOk()->assertJsonPath('data.keybox_tenants',[(int)$this->tenant->id]);
        $this->getJson($this->base.'/keyboxes')->assertOk()->assertJsonCount(0,'data.boxes')->assertJsonPath('data.providerCredentialsExposed',false);
        self::assertSame(0,$this->db->table('clubora.mobile_keybox_grants')->where('tenant_id',$this->tenant->id)->count());
        self::assertSame(0,$this->db->table('clubora.mobile_keybox_commands')->where('tenant_id',$this->tenant->id)->count());
        $foreign=Tenant::create(['slug'=>'entry-other-'.bin2hex(random_bytes(5)),'name'=>'Synthetic foreign']);
        self::assertSame([],$service->profileTenants($this->actor,$foreign));
        config(['clubunio-mobile.native_keybox_enabled'=>false]); self::assertSame([],$service->profileTenants($this->actor));
        config(['clubunio-mobile.native_keybox_enabled'=>true]);
        $this->db->table('clubora.accounts')->where('id',$this->actor->id)->update(['active'=>false]);
        self::assertSame([],$service->profileTenants($this->actor));
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_challenge_requires_explicit_configured_and_personally_visible_option(): void {
        $service=app(MobilePenaltyAccess::class);
        self::assertFalse($service->canPlay($this->actor,$this->tenant));
        $this->getJson($this->base.'/penalty/options')->assertForbidden();
        $this->db->table('clubora.mobile_module_options')->insert(['tenant_id'=>$this->tenant->id,'key'=>'Penalty','name'=>'Vereinschallenge','active'=>true]);
        self::assertTrue($service->canPlay($this->actor,$this->tenant));
        $this->getJson($this->base.'/penalty/options')->assertOk()->assertJsonPath('data.allowed',true)->assertJsonPath('data.tenantName','Synthetic club');
        $this->getJson($this->base.'/modules')->assertOk()->assertJsonFragment(['key'=>'Penalty','available'=>true,'native'=>true]);
        $profile=$this->insert('access_profiles',['name'=>'Synthetic restricted mobile profile','permissions'=>'[]','mobile_modules'=>'["BusReservationen"]']);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id',$this->tenant->id)->where('account_id',$this->actor->id)->update(['mobile_profile_id'=>$profile]);
        self::assertFalse($service->canPlay($this->actor,$this->tenant));
        $this->getJson($this->base.'/penalty/options')->assertForbidden();
        $this->getJson($this->base.'/modules')->assertOk()->assertJsonMissing(['key'=>'Penalty']);
        config(['clubunio-mobile.native_penalty_enabled'=>false]); $this->getJson($this->base.'/penalty/options')->assertStatus(503);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
}
