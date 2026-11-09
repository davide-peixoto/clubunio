<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail};
use Tests\TestCase;

/** Actual scoped endpoints, bounded query-count assertions; rolled-back synthetic rows only. */
class MobileMyTeamsParityTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private array $actors=[], $tokens=[], $members=[];
    private int $team, $otherTeam, $child, $country;
    private string $base;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit rolled-back PostgreSQL required');
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.bootstrap.enabled'=>false,
            'clubunio-mobile.native_members_enabled'=>true,'clubunio-mobile.native_registrations_enabled'=>true,'clubunio-mobile.native_my_teams_enabled'=>true,
            'clubunio-mobile.native_documents_enabled'=>false,'clubunio-mobile.native_member_id_card_enabled'=>false,
            'cache.default'=>'array','hashing.bcrypt.rounds'=>4]);
        Http::preventStrayRequests(); Mail::fake();
        $this->db=DB::connection('clubunio');$this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction();$this->db->statement("SET LOCAL application_name TO 'clubunio-my-teams-parity-rollback'");
        $this->tenant=Tenant::create(['slug'=>'perf-teams-'.bin2hex(random_bytes(6)),'name'=>'Synthetic teams']);
        foreach (['admin'=>'admin','trainer'=>'trainer','parent'=>'member','outside'=>'member'] as $key=>$role) {
            $actor=new Account;$actor->forceFill(['username'=>'perf-teams-'.bin2hex(random_bytes(8)),
                'display_name'=>'Synthetic '.$key,'password'=>bcrypt('fixture'),'active'=>true,'api_only'=>false])->save();
            $this->actors[$key]=$actor;
            $this->insert('tenant_accounts',['account_id'=>$actor->id,'role'=>$role,'portal_enabled'=>true]);
            $member=$this->insert('members',['first_name'=>'Synthetic','last_name'=>$key,'birth_date'=>'1980-01-01']);
            $this->members[$key]=$member;
            $this->insert('account_member_links',['account_id'=>$actor->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic-test']);
            $this->tokens[$key]=$this->postJson('/api/mobile/v1/session',['username'=>$actor->username,'password'=>'fixture','device_name'=>'Synthetic performance'])->assertOk()->json('data.token');
        }
        $this->country=$this->insert('countries',['code'=>'CH','iso3'=>'CHE','name'=>'Synthetic country']);
        $this->child=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Child','birth_date'=>now()->subYears(12)->toDateString(),
            'email'=>'child@example.invalid','mother_name'=>'PRIVATE-MOTHER','country_id'=>$this->country,'nationality_id'=>$this->country,
            'ahv_encrypted'=>encrypt('PRIVATE-AHV')]);
        $this->insert('account_member_links',['account_id'=>$this->actors['parent']->id,'member_id'=>$this->child,'kind'=>'parent','state'=>'approved','source'=>'synthetic-test']);
        $season=$this->insert('seasons',['name'=>'Synthetic current','starts_on'=>now()->subMonth()->toDateString(),'ends_on'=>now()->addMonth()->toDateString()]);
        $this->insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic season','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$season)]);
        $this->team=$this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Synthetic team']),'season_id'=>$season,'label'=>'Synthetic team']);
        $this->otherTeam=$this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Other team']),'season_id'=>$season,'label'=>'Other team']);
        $this->insert('team_players',['team_season_id'=>$this->team,'member_id'=>$this->child]);
        $this->insert('team_staff',['team_season_id'=>$this->team,'member_id'=>$this->members['trainer'],'staff_function_id'=>$this->insert('staff_functions',['code'=>'TRAINER','name'=>'Trainer'])]);
        $this->insert('training_slots',['team_season_id'=>$this->team,'starts_at'=>'18:00','ends_at'=>'19:30','synthetic_only'=>true]);
        $this->base='/api/mobile/v1/clubs/'.$this->tenant->id;
        $this->withToken($this->tokens['trainer']);
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) {$this->db->disableQueryLog();$this->db->flushQueryLog();$this->db->rollBack();}
        parent::tearDown();
    }
    private function insert(string $table,array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($values+['tenant_id'=>$this->tenant->id]);
    }
    public function test_trainer_directory_is_personal_but_team_roster_is_still_visible_and_pending_children_are_not_granted(): void
    {
        $pending=$this->insert('members',['first_name'=>'PRIVATE','last_name'=>'Pending child','birth_date'=>now()->subYears(8)->toDateString()]);
        $link=$this->insert('account_member_links',['account_id'=>$this->actors['trainer']->id,'member_id'=>$pending,'kind'=>'parent','state'=>'pending','source'=>'synthetic-test']);
        $this->getJson($this->base.'/members')->assertOk()->assertJsonCount(1,'data.Members')
            ->assertJsonPath('data.Members.0.MitgliederID',$this->members['trainer'])->assertJsonPath('data.PendingParentLinks',1);
        $this->getJson($this->base.'/members/'.$pending)->assertNotFound();
        $this->getJson($this->base.'/my-teams/'.$this->team)->assertOk()->assertJsonCount(1,'data.Players');
        $this->db->table('clubora.account_member_links')->where('id',$link)->update(['state'=>'approved']);
        $this->getJson($this->base.'/members')->assertOk()->assertJsonCount(2,'data.Members')->assertJsonPath('data.PendingParentLinks',0);
        $this->getJson($this->base.'/members/'.$pending)->assertOk()->assertJsonPath('data.Member.ChangeMode','request');
        $this->db->table('clubora.account_member_links')->where('id',$link)->update(['state'=>'revoked']);
        $this->getJson($this->base.'/members')->assertOk()->assertJsonCount(1,'data.Members');
        $this->getJson($this->base.'/members/'.$pending)->assertNotFound();
        $this->withToken($this->tokens['admin'])->getJson($this->base.'/members')->assertOk()->assertJsonCount(6,'data.Members');
    }
    public function test_trial_details_are_current_team_bound_minimal_for_trainer_and_never_private_reader_rights(): void
    {
        $reg=$this->insert('registrations',['first_name'=>'Trial','last_name'=>'Applicant','team_season_id'=>$this->team,
            'email'=>'PRIVATE@example.invalid','applicant_encrypted'=>Crypt::encryptString(json_encode(['mother_name'=>'PRIVATE-MOTHER','ahv'=>'PRIVATE-AHV']))]);
        $other=$this->insert('registrations',['first_name'=>'Other','last_name'=>'Applicant','team_season_id'=>$this->otherTeam]);
        $acceptedStatus=$this->insert('registration_statuses',['code'=>'APPROVED','name'=>'Approved','workflow_key'=>'accepted']);
        $accepted=$this->insert('registrations',['first_name'=>'Accepted','last_name'=>'Applicant','team_season_id'=>$this->team,
            'status_id'=>$acceptedStatus,'member_id'=>$this->child,'accepted_at'=>now()]);
        $url=$this->base.'/my-teams/'.$this->team.'/registrations';
        $response=$this->getJson($url)->assertOk()->assertJsonCount(1,'data.Registrations')->assertJsonPath('data.Registrations.0.AnmeldungID',$reg);
        $this->getJson($this->base.'/my-teams/'.$this->team)->assertOk()->assertJsonCount(1,'data.Registrations')
            ->assertJsonPath('data.Registrations.0.AnmeldungID',$reg)->assertJsonCount(1,'data.Players');
        $this->assertStringNotContainsString('PRIVATE',$response->getContent());
        $response=$this->getJson($url.'/'.$reg)->assertOk()->assertJsonPath('data.Registration.CanReadPrivateDetails',false)
            ->assertJsonPath('data.Registration.CanReview',false)->assertJsonMissingPath('data.Registration.Email')
            ->assertJsonMissingPath('data.Registration.PassfotoUpload');
        $this->assertStringNotContainsString('PRIVATE',$response->getContent());
        $this->getJson($url.'/'.$other)->assertNotFound();
        $this->getJson($this->base.'/registrations/'.$reg)->assertForbidden();
        $this->withToken($this->tokens['parent'])->getJson($url.'/'.$reg)->assertNotFound();
        $this->withToken($this->tokens['outside'])->getJson($url.'/'.$reg)->assertNotFound();
        $this->withToken($this->tokens['admin'])->getJson($url.'/'.$reg)->assertOk()
            ->assertJsonPath('data.Registration.CanReadPrivateDetails',true)->assertJsonPath('data.Registration.Email','PRIVATE@example.invalid');
        $this->getJson($url)->assertOk()->assertJsonCount(1,'data.Registrations')->assertJsonPath('data.Registrations.0.AnmeldungID',$reg);
        $this->getJson($this->base.'/registrations')->assertOk()->assertJsonFragment(['AnmeldungID'=>$accepted]);
        $this->getJson($this->base.'/registrations/'.$accepted)->assertOk()->assertJsonPath('data.Registration.AnmeldungID',$accepted);
        $foreign=Tenant::create(['slug'=>'foreign-trial-'.bin2hex(random_bytes(6)),'name'=>'Foreign synthetic']);
        $this->withToken($this->tokens['trainer'])->getJson('/api/mobile/v1/clubs/'.$foreign->id.'/my-teams/'.$this->team.'/registrations/'.$reg)->assertForbidden();
        $this->withToken($this->tokens['trainer']);
        $this->db->table('clubora.team_staff')->where('tenant_id',$this->tenant->id)->update(['active'=>false]);
        $this->getJson($url.'/'.$reg)->assertNotFound();
    }
}
