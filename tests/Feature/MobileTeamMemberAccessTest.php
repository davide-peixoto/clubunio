<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\{Crypt,DB,Http,Mail};
use Tests\TestCase;

/** Actual scoped endpoints, bounded query-count assertions; rolled-back synthetic rows only. */
class MobileTeamMemberAccessTest extends TestCase
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
            'clubunio-mobile.native_documents_enabled'=>true,'clubunio-mobile.native_member_id_card_enabled'=>true,
            'cache.default'=>'array','hashing.bcrypt.rounds'=>4]);
        Http::preventStrayRequests(); Mail::fake();
        $this->db=DB::connection('clubunio');$this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction();$this->db->statement("SET LOCAL application_name TO 'clubunio-team-member-access-rollback'");
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
        foreach (['pending','approved','rejected','withdrawn'] as $state) $this->insert('member_change_statuses',['code'=>$state,'name'=>$state,'color'=>'#164B8A']);
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
        return $this->db->table('clubunio.'.$table)->insertGetId($values+['tenant_id'=>$this->tenant->id]);
    }
    public function test_responsible_trainer_gets_detail_options_and_request_only_in_explicit_team(): void
    {
        $url=$this->base.'/members/'.$this->child;
        $this->getJson($url)->assertOk()->assertJsonPath('data.Member.CanEdit',false)->assertJsonMissingPath('data.Member.Mutter');
        $this->getJson($url.'/contact-options')->assertForbidden();
        $detail=$this->getJson($url.'?team_id='.$this->team)->assertOk()
            ->assertJsonPath('data.Member.TeamSaisonID',$this->team)->assertJsonPath('data.Member.ContactOnly',false)
            ->assertJsonPath('data.Member.CanEdit',true)->assertJsonPath('data.Member.CanRequestChanges',true)
            ->assertJsonPath('data.Member.ChangeMode','request')->assertJsonPath('data.Member.Mutter','PRIVATE-MOTHER')
            ->assertJsonPath('data.Member.CanReadInvoices',false)->assertJsonPath('data.Member.CanReadDocuments',false)
            ->assertJsonPath('data.Member.CanReadIdCard',false)->assertJsonMissingPath('data.Member.RechnungAn');
        $this->getJson($url.'/contact-options?team_id='.$this->team)->assertOk()->assertJsonCount(0,'data.BillingRecipients');
        $version=$detail->json('data.Member._version');
        $this->postJson($url.'/contact',['_version'=>$version,'Mobile'=>'+41000000000'])->assertForbidden();
        $this->postJson($url.'/contact?team_id='.$this->team,['_version'=>$version,'Homepage'=>'https://example.invalid'])->assertUnprocessable();
        $this->postJson($url.'/contact?team_id='.$this->team,['_version'=>$version,'RechnungAn'=>null])->assertUnprocessable();
        $this->postJson($url.'/contact?team_id='.$this->team,['_version'=>$version,'team_id'=>$this->team])->assertUnprocessable();
        $this->getJson($url.'?team_id='.$this->otherTeam)->assertNotFound();
        $reply=$this->postJson($url.'/contact?team_id='.$this->team,['_version'=>$version,'Mobile'=>'+41000000000'])->assertOk()
            ->assertJsonPath('data.action','member-contact-change-request');
        $request=\App\ClubUnio\Models\MemberChangeRequest::withoutGlobalScopes()->findOrFail($reply->json('data.request_id'));
        $this->assertSame($this->team,app(\App\ClubUnio\Services\MemberChangeService::class)->snapshot($request)['team_id']);
        $this->assertNull($this->db->table('clubunio.members')->where('id',$this->child)->value('mobile'));
        $this->getJson($url.'/invoices')->assertForbidden();
        $this->getJson($url.'/change-requests')->assertForbidden();
        $this->withToken($this->tokens['admin'])->postJson($url.'/change-requests/'.$request->id.'/decide',['Revision'=>1,'approve'=>true])->assertOk()->assertJsonPath('data.status','GENEHMIGT');
        $this->assertSame('+41000000000',$this->db->table('clubunio.members')->where('id',$this->child)->value('mobile'));
    }
    public function test_parent_team_players_even_own_child_are_name_only_but_staff_contacts_open_and_personal_dossier_stays_full(): void
    {
        $other=$this->insert('members',['first_name'=>'Other','last_name'=>'Player','email'=>'PRIVATE-PLAYER@example.invalid','mother_name'=>'PRIVATE-PARENT']);
        $this->insert('team_players',['team_season_id'=>$this->team,'member_id'=>$other]);
        $this->db->table('clubunio.members')->where('id',$this->members['trainer'])->update(['email'=>'trainer@example.invalid','mobile'=>'+41000000000','mother_name'=>'PRIVATE-STAFF-PARENT']);
        $this->withToken($this->tokens['parent']);
        $team=$this->getJson($this->base.'/my-teams/'.$this->team)->assertOk()->assertJsonCount(2,'data.Players');
        foreach ($team->json('data.Players') as $row) {
            $this->assertFalse($row['CanOpenDossier']);$this->assertFalse($row['CanRequestChanges']);
            foreach (['Email','Mobile','Ort','Mutter','Geburtsdatum'] as $field) $this->assertArrayNotHasKey($field,$row);
        }
        $team->assertJsonPath('data.Staff.0.CanOpenDossier',true)->assertJsonPath('data.Staff.0.ContactOnly',true)
            ->assertJsonPath('data.Staff.0.Email','trainer@example.invalid');
        $staff=$this->base.'/members/'.$this->members['trainer'];
        $this->getJson($staff.'?team_id='.$this->team)->assertOk()->assertJsonPath('data.Member.ContactOnly',true)
            ->assertJsonPath('data.Member.CanEdit',false)->assertJsonPath('data.Member.CanReadInvoices',false)
            ->assertJsonMissingPath('data.Member.Mutter');
        $this->getJson($staff.'/contact-options?team_id='.$this->team)->assertForbidden();
        $this->postJson($staff.'/contact?team_id='.$this->team,['_version'=>str_repeat('a',64),'Mobile'=>'x'])->assertForbidden();
        $this->getJson($this->base.'/members/'.$this->child.'?team_id='.$this->team)->assertNotFound();
        $this->getJson($this->base.'/members/'.$other.'?team_id='.$this->team)->assertNotFound();
        $this->getJson($this->base.'/members/'.$this->child)->assertOk()->assertJsonPath('data.Member.CanEdit',true)->assertJsonPath('data.Member.CanReadInvoices',true);
        // A trainer role for a different team must not override this parent-team projection.
        $this->db->table('clubunio.tenant_accounts')->where('account_id',$this->actors['parent']->id)->update(['role'=>'trainer']);
        $this->insert('team_staff',['team_season_id'=>$this->otherTeam,'member_id'=>$this->members['parent'],'staff_function_id'=>$this->insert('staff_functions',['code'=>'OTHER_TRAINER','name'=>'Trainer'])]);
        $helper=$this->insert('team_staff',['team_season_id'=>$this->team,'member_id'=>$this->members['parent'],'staff_function_id'=>$this->insert('staff_functions',['code'=>'HELPER','name'=>'Helper'])]);
        $this->getJson($this->base.'/my-teams/'.$this->team)->assertOk()->assertJsonPath('data.Players.0.CanOpenDossier',false);
        $this->getJson($this->base.'/members/'.$this->child.'?team_id='.$this->team)->assertNotFound();
        $this->getJson($this->base.'/members/'.$this->child.'/contact-options?team_id='.$this->team)->assertNotFound();
        $this->getJson($this->base.'/members/'.$other)->assertNotFound();
        $this->db->table('clubunio.team_staff')->where('id',$helper)->update(['active'=>false]);
        $this->db->table('clubunio.account_member_links')->where('account_id',$this->actors['parent']->id)->where('kind','parent')->update(['state'=>'pending']);
        $this->getJson($this->base.'/my-teams/'.$this->team)->assertNotFound();
    }
    public function test_assistant_trainer_is_explicit_but_coach_and_missing_function_do_not_grant_dossiers(): void
    {
        $function=$this->db->table('clubunio.team_staff')->where('tenant_id',$this->tenant->id)->where('member_id',$this->members['trainer'])->value('staff_function_id');
        $url=$this->base.'/members/'.$this->child.'?team_id='.$this->team;
        foreach ([['code'=>'legacy_2','name'=>'Assistenztrainer'],['code'=>'ASSISTANT_TRAINER','name'=>'Synthetic assistant'],['code'=>'TRAINER_ASSISTANT','name'=>'Synthetic assistant']] as $values) {
            $this->db->table('clubunio.staff_functions')->where('id',$function)->update($values);
            $this->getJson($url)->assertOk()->assertJsonPath('data.Member.CanRequestChanges',true);
        }
        $this->db->table('clubunio.staff_functions')->where('id',$function)->update(['code'=>'legacy_3','name'=>'Coach']);
        $this->getJson($url)->assertNotFound();
        $this->db->table('clubunio.team_staff')->where('tenant_id',$this->tenant->id)->update(['staff_function_id'=>null]);
        $this->getJson($url)->assertNotFound();
    }
    public function test_trainer_request_cannot_be_approved_after_assignment_revocation_but_office_can_reject(): void
    {
        $url=$this->base.'/members/'.$this->child;
        $detail=$this->getJson($url.'?team_id='.$this->team)->assertOk();
        $reply=$this->postJson($url.'/contact?team_id='.$this->team,['_version'=>$detail->json('data.Member._version'),'Mobile'=>'+41000000000'])->assertOk();
        $change=$reply->json('data.request_id');
        $this->postJson($url.'/change-requests/'.$change.'/decide',['Revision'=>1,'approve'=>true])->assertForbidden();
        $this->db->table('clubunio.team_staff')->where('tenant_id',$this->tenant->id)->update(['active'=>false]);
        $this->getJson($url.'?team_id='.$this->team)->assertNotFound();
        $this->getJson($url.'/contact-options?team_id='.$this->team)->assertNotFound();
        $this->postJson($url.'/contact?team_id='.$this->team,['_version'=>$detail->json('data.Member._version'),'Mobile'=>'x'])->assertNotFound();
        $this->withToken($this->tokens['admin']);
        $this->postJson($url.'/change-requests/'.$change.'/decide',['Revision'=>1,'approve'=>true])->assertUnprocessable();
        $this->postJson($url.'/change-requests/'.$change.'/decide',['Revision'=>1,'approve'=>false,'Begruendung'=>'Trainer assignment revoked'])->assertOk()->assertJsonPath('data.status','ABGELEHNT');
        $this->assertNull($this->db->table('clubunio.members')->where('id',$this->child)->value('mobile'));
    }
}
