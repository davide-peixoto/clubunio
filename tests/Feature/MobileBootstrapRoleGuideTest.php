<?php
namespace Tests\Feature;
use App\ClubUnio\Mobile\MobileAppAnnouncements;
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail};
use Tests\TestCase;

class MobileBootstrapRoleGuideTest extends TestCase {
    private $db; private $grammar; private Tenant $tenant; private Account $admin; private Account $trainer; private Account $parent; private array $tokens=[];
    private string $origin='https://bootstrap-fixture.clubunio.test';
    protected function setUp(): void {
        parent::setUp(); if(getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Synthetic rollback fixtures only');
        $this->db=DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName()); $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name='clubunio-mobile-bootstrap-test'"); $this->db->statement("SET LOCAL lock_timeout='5s'");
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap'=>['enabled'=>true,'origins'=>[$this->origin],'maintenance_mode'=>false],
            'clubunio-mobile.native_role_guide_enabled'=>true,'clubunio-mobile.native_planning_enabled'=>true,'clubunio-mobile.native_my_teams_enabled'=>true,
            'clubunio-mobile.native_members_enabled'=>true,'clubunio-mobile.public_identity.enabled'=>false,'clubunio-mobile.push.enabled'=>false,
            'clubunio-domains.central_hosts'=>['bootstrap-fixture.clubunio.test'],'cache.default'=>'array','hashing.bcrypt.rounds'=>4]);
        Http::preventStrayRequests(); Mail::fake();
        $this->tenant=Tenant::create(['slug'=>'qa-bootstrap-'.bin2hex(random_bytes(5)),'name'=>'Synthetic bootstrap club']);
        $this->admin=$this->account('admin'); $this->trainer=$this->account('trainer'); $this->parent=$this->account('member');
        $coach=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Coach']); $child=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Child','birth_date'=>'2014-01-02']);
        $this->insert('account_member_links',['account_id'=>$this->trainer->id,'member_id'=>$coach,'kind'=>'self','state'=>'approved','source'=>'test']);
        $this->insert('account_member_links',['account_id'=>$this->parent->id,'member_id'=>$child,'kind'=>'parent','state'=>'approved','source'=>'test']);
        $season=$this->insert('seasons',['name'=>'Selected','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
        $this->setting('general','SaisonID',(string)$season);
        $team=$this->insert('teams',['name'=>'Synthetic team']); $ts=$this->insert('team_seasons',['team_id'=>$team,'season_id'=>$season,'label'=>'Selected team']);
        $this->insert('team_staff',['team_season_id'=>$ts,'member_id'=>$coach]); $this->insert('team_players',['team_season_id'=>$ts,'member_id'=>$child]);
        foreach(['Teams','Teamplanung','Mitglieder'] as $key) $this->db->table('clubunio.mobile_module_options')->insert(['tenant_id'=>$this->tenant->id,'key'=>$key,'name'=>$key]);
        $this->temporarySchema();
        foreach([$this->admin,$this->trainer,$this->parent] as $actor) $this->tokens[$actor->id]=$this->login($actor);
        $this->withToken($this->tokens[$this->admin->id]);
    }
    private function temporarySchema(): void {
        foreach(['tenants'=>'id bigint PRIMARY KEY','tenant_accounts'=>'tenant_id bigint,account_id bigint,UNIQUE(tenant_id,account_id)'] as $table=>$definition) {
            $this->db->statement('CREATE TEMP TABLE bootstrap_fixture_'.$table.'('.$definition.') ON COMMIT DROP');
            $this->db->statement('INSERT INTO pg_temp.bootstrap_fixture_'.$table.' SELECT '.($table==='tenants'?'id':'tenant_id,account_id').' FROM clubunio.'.$table.' WHERE '.($table==='tenants'?'id':'tenant_id').'=?',[$this->tenant->id]);
        }
        $ddl=$this->db->pretend(fn ()=>(require database_path('clubunio/2026_11_04_000800_mobile_app_announcements.php'))->up());
        $this->assertCount(4,$ddl); $sql=$ddl[0]['query'];
        foreach(['tenants','tenant_accounts'] as $table) $sql=str_replace('REFERENCES clubunio.'.$table.'(','REFERENCES pg_temp.bootstrap_fixture_'.$table.'(',$sql);
        $sql=str_replace('CREATE TABLE clubunio.mobile_app_announcements','CREATE TEMP TABLE mobile_app_announcements',$sql);
        $sql=str_replace('clubunio.mobile_app_announcements','pg_temp.mobile_app_announcements',$sql); $sql=str_replace("\n);","\n) ON COMMIT DROP;",$sql);
        $this->assertStringNotContainsString('REFERENCES clubunio.',$sql); $this->assertStringNotContainsString('ON clubunio.',$sql); $this->db->unprepared($sql);
        $this->db->statement('CREATE TEMP TABLE bootstrap_fixture_settings(tenant_id bigint,area text,key text,label text,value_type text,encrypted_value text,enabled boolean,is_secret boolean,UNIQUE(tenant_id,area,key)) ON COMMIT DROP');
        foreach([1,2] as $repeat) foreach(array_slice($ddl,1) as $query) {
            $sql=str_replace(['clubunio.tenant_settings','clubunio.tenants'],['pg_temp.bootstrap_fixture_settings','pg_temp.bootstrap_fixture_tenants'],$query['query']);
            // Laravel's pretend ledger already substitutes the original bindings.
            $this->db->unprepared($sql);
        }
        $this->assertSame(3,$this->db->table('pg_temp.bootstrap_fixture_settings')->count());
        $this->grammar=$this->db->getQueryGrammar();
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table,$prefix=null) { if(is_string($table)) $table=str_replace('clubunio.mobile_app_announcements','pg_temp.mobile_app_announcements',$table); return parent::wrapTable($table,$prefix); }
            public function wrap($value) { if(is_string($value)) $value=str_replace('clubunio.mobile_app_announcements','pg_temp.mobile_app_announcements',$value); return parent::wrap($value); }
        });
    }
    protected function tearDown(): void { if(isset($this->db)) { if($this->db->transactionLevel()) $this->db->rollBack(); if($this->grammar) $this->db->setQueryGrammar($this->grammar); } parent::tearDown(); }
    private function insert(string $table,array $values): int { return $this->db->table('clubunio.'.$table)->insertGetId(['tenant_id'=>$this->tenant->id]+$values); }
    private function setting(string $area,string $key,string $value): void {
        $this->db->table('clubunio.tenant_settings')->updateOrInsert(['tenant_id'=>$this->tenant->id,'area'=>$area,'key'=>$key],['label'=>'Synthetic '.$key,'value_type'=>'string','encrypted_value'=>Crypt::encryptString($value),'enabled'=>true,'is_secret'=>false]);
    }
    private function account(string $role): Account { $a=new Account; $a->forceFill(['username'=>'qa-bootstrap-'.bin2hex(random_bytes(5)),'display_name'=>'Synthetic '.$role,'password'=>bcrypt('synthetic-password'),'active'=>true,'api_only'=>false])->save(); $this->insert('tenant_accounts',['account_id'=>$a->id,'role'=>$role,'portal_enabled'=>true]); return $a; }
    private function login(Account $actor): string { return $this->postJson($this->origin.'/api/mobile/v1/session',['username'=>$actor->username,'password'=>'synthetic-password','device_name'=>'Synthetic bootstrap'])->assertOk()->json('data.token'); }
    private function base(): string { return $this->origin.'/api/mobile/v1/clubs/'.$this->tenant->id; }
    private function banner(string $title,string $audience='members',array $overrides=[]): array {
        return array_replace(['title'=>$title,'body'=>'Synthetic banner text','audience'=>$audience,'starts_at'=>now()->subHour()->toIso8601String(),'ends_at'=>now()->addHour()->toIso8601String(),
            'active'=>true,'revision'=>0,'public_acknowledged'=>$audience==='public'],$overrides);
    }
    public function test_public_bootstrap_is_host_bound_no_private_banner_and_maintenance_does_not_require_a_token(): void {
        $service=app(MobileAppAnnouncements::class); $service->save($this->admin,$this->tenant,null,$this->banner('Public notice','public'));
        $service->save($this->admin,$this->tenant,null,$this->banner('PRIVATE MEMBER NOTICE'));
        $public=$this->origin.'/api/mobile/v1/public/bootstrap';
        $this->getJson($public)->assertOk()->assertJsonPath('data.tenant_id',null)->assertJsonCount(0,'data.announcements');
        $this->getJson($public.'?tenant='.$this->tenant->id)->assertUnprocessable();
        config(['clubunio-domains.hosts'=>['bootstrap-fixture.clubunio.test'=>$this->tenant->slug]]);
        $this->getJson($public)->assertOk()->assertJsonCount(1,'data.announcements')->assertJsonPath('data.announcements.0.title','Public notice')->assertDontSee('PRIVATE MEMBER NOTICE');
        $this->setting('app','MaintenanceMode','1'); $this->setting('app','MaintenanceMessage','Synthetic maintenance');
        $this->getJson($public)->assertOk()->assertJsonPath('data.loginAllowed',false)->assertJsonPath('data.maintenanceMessage','Synthetic maintenance');
        $this->postJson($this->origin.'/api/mobile/v1/session',['username'=>$this->admin->username,'password'=>'synthetic-password','device_name'=>'Synthetic'])->assertStatus(503);
        $this->getJson($this->base().'/bootstrap')->assertOk()->assertJsonPath('data.maintenanceMode',true);
        $this->getJson($this->base().'/role-guide')->assertStatus(503);
        $this->deleteJson($this->origin.'/api/mobile/v1/session')->assertOk(); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_banner_administration_requires_real_rights_revision_explicit_public_confirmation_and_active_window(): void {
        $service=app(MobileAppAnnouncements::class);
        try { $service->save($this->trainer,$this->tenant,null,$this->banner('Not permitted')); $this->fail('Trainer cannot publish'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        try { $service->save($this->admin,$this->tenant,null,$this->banner('Public unconfirmed','public',['public_acknowledged'=>false])); $this->fail('Explicit public confirmation required'); }
        catch(\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('public_acknowledged',$e->errors()); }
        $row=$service->save($this->admin,$this->tenant,null,$this->banner('Original notice'));
        $service->save($this->admin,$this->tenant,null,$this->banner('Future notice','public',['starts_at'=>now()->addDay()->toIso8601String(),'ends_at'=>now()->addDays(2)->toIso8601String()]));
        $this->getJson($this->base().'/bootstrap')->assertOk()->assertJsonCount(1,'data.announcements')->assertJsonPath('data.announcements.0.title','Original notice');
        $service->save($this->admin,$this->tenant,$row->id,$this->banner('Changed notice','members',['revision'=>1]));
        try { $service->save($this->admin,$this->tenant,$row->id,$this->banner('Stale notice','members',['revision'=>1])); $this->fail('Stale revision rejected'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $service->save($this->admin,$this->tenant,$row->id,$this->banner('Withdrawn','members',['revision'=>2,'active'=>false]));
        $this->getJson($this->base().'/bootstrap')->assertOk()->assertJsonCount(0,'data.announcements'); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_role_guide_uses_real_current_team_parent_and_review_capabilities_without_legacy_userlevel(): void {
        $this->withToken($this->tokens[$this->trainer->id])->getJson($this->base().'/role-guide')->assertOk()->assertJsonPath('data.canManageTeams',true)
            ->assertJsonPath('data.canReviewMemberChanges',false)->assertJsonPath('data.canSendSquadPush',false)->assertJsonMissingPath('data.userLevel');
        $this->withToken($this->tokens[$this->parent->id])->getJson($this->base().'/role-guide')->assertOk()->assertJsonPath('data.canRespond',true)
            ->assertJsonPath('data.canManageTeams',false)->assertJsonPath('data.canManageResponses',false)->assertJsonPath('data.canReviewMemberChanges',false);
        $this->withToken($this->tokens[$this->admin->id])->getJson($this->base().'/role-guide')->assertOk()->assertJsonPath('data.canReviewMemberChanges',true);
        $this->db->table('clubunio.team_staff')->where('tenant_id',$this->tenant->id)->update(['active'=>false]);
        $this->withToken($this->tokens[$this->trainer->id])->getJson($this->base().'/role-guide')->assertOk()->assertJsonPath('data.canManageTeams',false);
        $this->db->table('clubunio.team_players')->where('tenant_id',$this->tenant->id)->update(['active'=>false]);
        $this->withToken($this->tokens[$this->parent->id])->getJson($this->base().'/role-guide')->assertOk()->assertJsonPath('data.canRespond',false);
        config(['clubunio-mobile.native_role_guide_enabled'=>false]); $this->getJson($this->base().'/role-guide')->assertStatus(503); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_banner_is_operable_through_the_real_tenant_bound_backend_form(): void {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('clubunio'));
        $this->actingAs($this->admin,'clubunio'); \Filament\Facades\Filament::setTenant($this->tenant);
        $page=\Livewire\Livewire::test(\App\ClubUnio\Resources\MobileAppAnnouncementResource\Pages\ManageRecords::class)->assertSuccessful();
        $page->callTableAction('create',data:$this->banner('Actual backend form banner','members',['active'=>false]))->assertHasNoActionErrors();
        $row=\App\ClubUnio\Models\MobileAppAnnouncement::where('tenant_id',$this->tenant->id)->firstOrFail();
        $this->assertSame('Actual backend form banner',$row->title); $this->assertFalse($row->active);
        $page->callTableAction('edit',$row,data:$this->banner('Published through backend','public',['revision'=>1]))->assertHasNoActionErrors();
        $this->assertTrue($row->refresh()->active); $this->assertSame(2,$row->revision);
        $this->actingAs($this->trainer,'clubunio');
        $this->assertFalse(\App\ClubUnio\Resources\MobileAppAnnouncementResource::canViewAny());
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_global_disabled_runtime_allows_only_authenticated_own_logout_not_other_routes_or_foreign_sessions(): void {
        config(['clubunio-mobile.enabled'=>false]);
        $this->getJson($this->origin.'/api/mobile/v1/public/bootstrap')->assertOk()->assertJsonPath('data.maintenanceMode',true);
        $this->getJson($this->origin.'/api/mobile/v1/context')->assertStatus(503);
        $this->postJson($this->origin.'/api/mobile/v1/preferences',['language'=>'de'])->assertStatus(503);
        $foreign=explode('.',$this->tokens[$this->parent->id])[0];
        $this->deleteJson($this->origin.'/api/mobile/v1/sessions/'.$foreign)->assertNotFound();
        $this->deleteJson($this->origin.'/api/mobile/v1/session')->assertOk();
        $this->withHeader('Authorization','')->deleteJson($this->origin.'/api/mobile/v1/session')->assertUnauthorized();
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_central_registration_availability_uses_actual_allowed_club_settings_and_never_discloses_club_names(): void {
        config(['clubunio-mobile.public_identity'=>['enabled'=>true,'registration_enabled'=>true,'membership_enabled'=>false,'mail_enabled'=>true,
            'mailer'=>'clubunio_identity','from'=>'synthetic@example.test','origins'=>[$this->origin],'tenant_slugs'=>[$this->tenant->slug]],
            'mail.mailers.clubunio_identity'=>['transport'=>'smtp','host'=>'smtp.fixture.invalid','port'=>465,'timeout'=>5]]);
        $url=$this->origin.'/api/mobile/v1/public/bootstrap';
        $this->setting('app','RegistrationActive','0');
        $this->getJson($url)->assertOk()->assertJsonPath('data.registrationActive',false)->assertDontSee($this->tenant->name);
        $this->setting('app','RegistrationActive','1');
        $this->getJson($url)->assertOk()->assertJsonPath('data.registrationActive',true)->assertJsonPath('data.tenant_id',null);
        $this->setting('app','MaintenanceMode','1');
        $this->getJson($url)->assertOk()->assertJsonPath('data.registrationActive',false)->assertJsonPath('data.loginAllowed',true);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
}
