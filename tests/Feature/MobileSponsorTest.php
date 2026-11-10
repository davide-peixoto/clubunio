<?php
namespace Tests\Feature;
use App\ClubUnio\Mobile\{MobileMiddleware,MobileSponsorRoutes};
use App\ClubUnio\Models\{Account,Tenant,SponsorRunParticipant};
use App\ClubUnio\Services\{SponsorRunService,SponsorRunPublicService};
use Illuminate\Support\Facades\{DB,Crypt,Http,Mail,Route};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileSponsorTest extends TestCase {
    private $db;
    private Tenant $tenant;
    private Account $admin,$parent,$other,$helper;
    private int $child,$otherChild,$season,$team,$event,$participant,$foreignParticipant;
    private string $base;
    protected function setUp(): void {
        parent::setUp();
        if(getenv('CLUBUNIO_TEST_DB')!=='1') $this->markTestSkipped('Explicit rolled-back PostgreSQL fixtures required');
        $this->db=DB::connection('clubunio'); $this->assertSame('db_acdf8d_dclubora',$this->db->getDatabaseName());
        $this->db->beginTransaction(); Http::preventStrayRequests(); Mail::fake();
        config(['clubunio-mobile.enabled'=>true,'clubunio-mobile.native_sponsor_enabled'=>true,'cache.default'=>'array','app.url'=>'https://synthetic.example']);
        Route::prefix('api/mobile/v1/clubs/{tenant}')->middleware(MobileMiddleware::class)->group(fn()=>MobileSponsorRoutes::register());
        $this->tenant=Tenant::create(['slug'=>'sponsor-mobile-'.bin2hex(random_bytes(6)),'name'=>'Synthetic sponsor']);
        $this->admin=$this->account('admin'); $this->parent=$this->account('member'); $this->other=$this->account('member'); $this->helper=$this->account('member');
        $this->child=$this->member('Own Child',now()->subYears(10)->toDateString()); $this->otherChild=$this->member('Foreign Child',now()->subYears(11)->toDateString());
        $this->link($this->parent,$this->child,'parent'); $this->link($this->other,$this->otherChild,'parent');
        $this->link($this->helper,$this->member('Helper','1990-01-01'),'self');
        $this->season=$this->insert('seasons',['name'=>'Synthetic season']);
        $this->insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$this->season)]);
        $this->team=$this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Synthetic team']),'season_id'=>$this->season,'label'=>'Synthetic team']);
        foreach([$this->child,$this->otherChild] as $member) $this->insert('team_players',['team_season_id'=>$this->team,'member_id'=>$member]);
        foreach(['draft','open','closed','cancelled'] as $i=>$status) $this->insert('sponsor_run_statuses',['code'=>$status,'name'=>$status,'sort_order'=>$i]);
        // All sponsor business rows and command constraints remain in this connection's private temporary tables.
        $tables=['sponsor_run_events','sponsor_run_event_teams','sponsor_run_participants','sponsor_run_helpers','sponsor_run_pledges','sponsor_run_audits','sponsor_run_links','sponsor_run_public_pending','sponsor_run_public_outbox','sponsor_run_invoices'];
        foreach($tables as $table) $this->db->statement('CREATE TEMP TABLE '.$table.' (LIKE clubunio.'.$table.' INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE sponsor_mobile_accounts (LIKE clubunio.tenant_accounts INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('INSERT INTO pg_temp.sponsor_mobile_accounts SELECT * FROM clubunio.tenant_accounts WHERE tenant_id=?',[$this->tenant->id]);
        $ddl=$this->db->pretend(fn()=>(require database_path('clubunio/2026_11_03_000200_mobile_sponsor_commands.php'))->up());
        $sql=str_replace(['CREATE TABLE clubunio.mobile_sponsor_commands','clubunio.mobile_sponsor_commands','REFERENCES clubunio.tenant_accounts','REFERENCES clubunio.sponsor_run_events','REFERENCES clubunio.sponsor_run_participants'],
            ['CREATE TEMP TABLE mobile_sponsor_commands','pg_temp.mobile_sponsor_commands','REFERENCES pg_temp.sponsor_mobile_accounts','REFERENCES pg_temp.sponsor_run_events','REFERENCES pg_temp.sponsor_run_participants'],$ddl[0]['query']);
        $sql=str_replace(");\nREVOKE ALL", ") ON COMMIT DROP;\nREVOKE ALL",$sql); $this->db->unprepared($sql);
        $tables[]='mobile_sponsor_commands';
        $this->db->setQueryGrammar(new class($this->db,$tables) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            private array $names;
            public function __construct($connection,array $names){ parent::__construct($connection); $this->names=$names; }
            private function map($value){ foreach($this->names as $name) $value=str_replace('clubunio.'.$name,'pg_temp.'.$name,$value); return $value; }
            public function wrapTable($table,$prefix=null){ return parent::wrapTable($this->map($table),$prefix); }
            public function wrap($value,$prefixAlias=false){ return parent::wrap(is_string($value)?$this->map($value):$value,$prefixAlias); }
        });
        $e=app(SponsorRunService::class)->saveEvent($this->admin,$this->tenant,null,['season_id'=>$this->season,'title'=>'Synthetic run','place'=>'Synthetic field',
            'event_date'=>now('Europe/Zurich')->toDateString(),'registration_deadline'=>now('Europe/Zurich')->toDateString(),'status_code'=>'open','revision'=>0,'team_ids'=>[$this->team]]);
        $this->event=$e->id; $this->participant=SponsorRunParticipant::where('tenant_id',$this->tenant->id)->where('event_id',$e->id)->where('member_id',$this->child)->sole()->id;
        $this->foreignParticipant=SponsorRunParticipant::where('tenant_id',$this->tenant->id)->where('event_id',$e->id)->where('member_id',$this->otherChild)->sole()->id;
        $this->base='/api/mobile/v1/clubs/'.$this->tenant->id.'/sponsor-run';
    }
    protected function tearDown(): void { if(isset($this->db)) while($this->db->transactionLevel()) $this->db->rollBack(); parent::tearDown(); }
    private function insert(string $table,array $v): int { return $this->db->table('clubunio.'.$table)->insertGetId($v+['tenant_id'=>$this->tenant->id]); }
    private function account(string $role): Account {
        $a=new Account; $a->forceFill(['username'=>'sponsor-'.bin2hex(random_bytes(7)),'display_name'=>'Synthetic '.$role,'password'=>bcrypt('fixture'),'active'=>true,'api_only'=>false])->save();
        $this->insert('tenant_accounts',['account_id'=>$a->id,'role'=>$role,'portal_enabled'=>true]); return $a;
    }
    private function member(string $name,string $birth): int { return $this->insert('members',['first_name'=>$name,'last_name'=>'Synthetic','birth_date'=>$birth]); }
    private function link(Account $a,int $member,string $kind): void { $this->insert('account_member_links',['account_id'=>$a->id,'member_id'=>$member,'kind'=>$kind,'state'=>'approved','source'=>'test']); }
    private function token(Account $a): string { return $this->postJson('/api/mobile/v1/session',['username'=>$a->username,'password'=>'fixture','device_name'=>'Synthetic sponsor'])->assertOk()->json('data.token'); }
    private function pledge(array $change=[]): array { return $change+['RequestKey'=>(string)Str::uuid(),'SponsorName'=>'Synthetic donor','PaymentMode'=>'INVOICE','AmountType'=>'PER_LAP','AmountCents'=>125,'CapCents'=>500,
        'RecipientType'=>'PERSON','Company'=>'','FirstName'=>'Private','LastName'=>'Recipient','Street'=>'Synthetic road','HouseNumber'=>'1','PostalCode'=>'0000','City'=>'Synthetic','Country'=>'CH','Email'=>'synthetic@example.invalid','CollectorConfirmed'=>false]; }
    private function helper(bool $laps=false): void { app(SponsorRunService::class)->saveHelper($this->admin,$this->tenant,$this->event,['account_id'=>$this->helper->id,'revision'=>0,'can_checkin'=>true,'can_record_laps'=>$laps,'valid_from'=>now()->subHour()->toIso8601String(),'valid_until'=>now()->addHour()->toIso8601String()]); }
    public function test_parent_sees_only_own_children_and_append_only_chf_pledges_are_idempotent_and_private(): void {
        $this->withToken($this->token($this->parent))->getJson($this->base.'/events')->assertOk()->assertJsonCount(1,'data.Events');
        $this->getJson($this->base.'/events/'.$this->event.'/participants?view=mine')->assertOk()->assertJsonCount(1,'data.Participants')->assertJsonPath('data.Participants.0.ParticipantID',$this->participant);
        $input=$this->pledge(); $url=$this->base.'/participants/'.$this->participant.'/pledges';
        $id=$this->postJson($url,$input)->assertOk()->json('data.PledgeID'); $this->postJson($url,$input)->assertOk()->assertJsonPath('data.PledgeID',$id);
        $this->postJson($url,array_replace($input,['AmountCents'=>999]))->assertConflict();
        $this->postJson($url,$this->pledge(['AmountCents'=>1.25]))->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.Summary.PerLapCents',125)->assertJsonPath('data.Summary.TotalCents',null)->assertDontSee('synthetic@example.invalid')->assertDontSee('Synthetic road');
        $this->getJson($this->base.'/participants/'.$this->foreignParticipant.'/pledges')->assertNotFound();
        $this->postJson($this->base.'/participants/'.$this->foreignParticipant.'/pledges',$this->pledge())->assertNotFound();
        $this->getJson($this->base.'/events/'.$this->event.'/participants?view=manage')->assertForbidden();
        $this->assertSame(1,$this->db->table('clubunio.sponsor_run_pledges')->count()); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_helper_rights_expiry_revisions_and_irreversible_lap_approval_are_real(): void {
        $this->helper(); $url=$this->base.'/participants/'.$this->participant;
        $this->withToken($this->token($this->helper))->getJson($this->base.'/events/'.$this->event.'/participants?view=helper')->assertOk()->assertJsonCount(2,'data.Participants')->assertJsonPath('data.Participants.0.CanViewPledges',false)->assertJsonPath('data.Participants.0.SponsorCount',null);
        $this->getJson($url.'/pledges')->assertForbidden();
        $this->patchJson($url.'/operations',['Revision'=>1,'Action'=>'checkin','CheckedIn'=>true])->assertOk()->assertJsonPath('data.Revision',2);
        $this->patchJson($url.'/operations',['Revision'=>1,'Action'=>'checkin','CheckedIn'=>false])->assertConflict();
        $this->patchJson($url.'/operations',['Revision'=>2,'Action'=>'laps','Laps'=>4])->assertForbidden();
        $this->db->table('clubunio.sponsor_run_helpers')->where('account_id',$this->helper->id)->update(['valid_until'=>now()->subMinute()->toIso8601String()]);
        $this->assertTrue($this->db->table('clubunio.sponsor_run_helpers')->where('account_id',$this->helper->id)->whereRaw('valid_until < CURRENT_TIMESTAMP')->exists());
        $this->patchJson($url.'/operations',['Revision'=>2,'Action'=>'checkin','CheckedIn'=>false])->assertNotFound();
        $this->withToken($this->token($this->admin))->patchJson($url.'/operations',['Revision'=>2,'Action'=>'laps','Laps'=>4])->assertOk()->assertJsonPath('data.Revision',3);
        $this->patchJson($url.'/result',['Revision'=>3,'Laps'=>4])->assertUnprocessable();
        $this->patchJson($url.'/result',['Revision'=>3,'Laps'=>5,'ConfirmFinal'=>true])->assertConflict();
        $this->patchJson($url.'/result',['Revision'=>3,'Laps'=>4,'ConfirmFinal'=>true])->assertOk()->assertJsonPath('data.Revision',4);
        $this->patchJson($url.'/operations',['Revision'=>4,'Action'=>'laps','Laps'=>6])->assertConflict(); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_event_commands_versions_helpers_and_closed_registration_do_not_widen_rights(): void {
        $input=['RequestKey'=>(string)Str::uuid(),'SaisonID'=>$this->season,'Title'=>'Second run','Place'=>'Synthetic place','EventDate'=>now('Europe/Zurich')->toDateString(),'RegistrationDeadline'=>now('Europe/Zurich')->toDateString(),'Status'=>'OPEN','TeamSaisonIDs'=>[$this->team]];
        $this->withToken($this->token($this->admin)); $id=$this->postJson($this->base.'/events',$input)->assertOk()->json('data.Event.EventID');
        $this->postJson($this->base.'/events',$input)->assertOk()->assertJsonPath('data.Event.EventID',$id);
        $this->postJson($this->base.'/events',array_replace($input,['Title'=>'Different run']))->assertConflict();
        $this->patchJson($this->base.'/events/'.$id,array_replace($input,['Status'=>'CLOSED','Revision'=>1,'Reason'=>'Close registration']))->assertOk()->assertJsonPath('data.Event.Revision',2);
        $this->patchJson($this->base.'/events/'.$id,array_replace($input,['Revision'=>1,'Reason'=>'Stale event']))->assertConflict();
        $this->getJson($this->base.'/events/'.$id.'/helper-candidates?search=Synthetic')->assertOk()->assertJsonCount(1,'data.Candidates')->assertJsonPath('data.Candidates.0.AccountID',$this->helper->id);
        $helper=['AccountID'=>$this->helper->id,'Revision'=>0,'CanCheckIn'=>true,'CanRecordLaps'=>false,'ValidFrom'=>now('Europe/Zurich')->toDateString(),'ValidUntil'=>now('Europe/Zurich')->toDateString()];
        $this->postJson($this->base.'/events/'.$id.'/helpers',$helper)->assertOk(); $this->postJson($this->base.'/events/'.$id.'/helpers',$helper)->assertConflict();
        $this->withToken($this->token($this->parent))->postJson($this->base.'/events',$input)->assertForbidden();
        $this->postJson($this->base.'/events/'.$id.'/helpers',$helper)->assertForbidden();
        $p=SponsorRunParticipant::where('event_id',$id)->where('member_id',$this->child)->sole();
        $this->postJson($this->base.'/participants/'.$p->id.'/pledges',$this->pledge())->assertConflict();
        $this->assertSame(1,$this->db->table('clubunio.mobile_sponsor_commands')->count());
    }
    public function test_link_consent_revision_rotation_replay_and_revocation_do_not_send_mail(): void {
        app(SponsorRunPublicService::class)->configurePublic($this->admin,$this->tenant,true); $url='https://synthetic.example'.$this->base.'/participants/'.$this->participant.'/share-link';
        $this->withToken($this->token($this->parent))->getJson($url)->assertOk()->assertJsonPath('data.Revision',0);
        $input=['Action'=>'create','RequestKey'=>(string)Str::uuid(),'Revision'=>0,'DisplayName'=>'Synthetic child','Consent'=>true];
        $this->postJson($url,array_replace($input,['Consent'=>false]))->assertUnprocessable();
        $link=$this->postJson($url,$input)->assertOk()->assertJsonPath('data.Revision',1)
            ->assertJsonPath('data.TenantID',(int)$this->tenant->id)->assertJsonPath('data.TenantName',$this->tenant->name)->json('data.ShareUrl');
        $this->assertMatchesRegularExpression('~^https://synthetic\.example/sponsor-run#[a-f0-9]{64}$~D',$link);
        config(['clubunio-domains.hosts'=>array_merge(config('clubunio-domains.hosts',[]),['custom.example'=>$this->tenant->slug])]);
        $custom=str_replace('https://synthetic.example','https://custom.example',$url);
        $this->getJson($custom)->assertOk()->assertJsonPath('data.ShareUrl',str_replace('https://synthetic.example','https://custom.example',$link));
        $this->getJson(str_replace('https://synthetic.example','https://foreign.example',$url))->assertNotFound();
        $this->postJson($url,$input)->assertOk()->assertJsonPath('data.ShareUrl',$link);
        $rotate=['Action'=>'rotate','RequestKey'=>(string)Str::uuid(),'Revision'=>1,'DisplayName'=>'Synthetic child','Consent'=>true];
        $next=$this->postJson($url,$rotate)->assertOk()->assertJsonPath('data.Revision',2)->json('data.ShareUrl'); $this->assertNotSame($link,$next);
        $this->postJson($url,$rotate)->assertOk()->assertJsonPath('data.ShareUrl',$next); $this->postJson($url,$input)->assertConflict();
        $revoke=['Action'=>'revoke','RequestKey'=>(string)Str::uuid(),'Revision'=>2];
        $this->postJson($url,$revoke)->assertOk()->assertJsonPath('data.ShareUrl',null)->assertJsonPath('data.Revision',3); $this->postJson($url,$revoke)->assertOk();
        $this->withToken($this->token($this->other))->postJson($url,$rotate)->assertNotFound();
        Http::assertNothingSent(); Mail::assertNothingSent(); $this->assertSame(0,$this->db->table('clubunio.sponsor_run_public_outbox')->count());
    }
    public function test_parent_age_boundary_feature_gate_and_revoked_session_close_access(): void {
        $token=$this->token($this->parent); $this->withToken($token)->getJson($this->base.'/events')->assertOk();
        $this->db->table('clubunio.members')->where('id',$this->child)->update(['birth_date'=>now()->subYears(18)->toDateString()]);
        $this->getJson($this->base.'/events')->assertForbidden();
        $this->withToken($this->token($this->admin)); config(['clubunio-mobile.native_sponsor_enabled'=>false]);
        $this->getJson($this->base.'/events')->assertStatus(503); config(['clubunio-mobile.native_sponsor_enabled'=>true]);
        $this->db->table('clubunio.mobile_sessions')->where('account_id',$this->admin->id)->update(['revoked_at'=>now()]); $this->getJson($this->base.'/events')->assertUnauthorized();
    }
    public function test_command_foreign_keys_bind_real_actor_event_and_participant_results(): void {
        $e=app(SponsorRunService::class)->saveEvent($this->admin,$this->tenant,null,['season_id'=>$this->season,'title'=>'Another fixture','place'=>'Synthetic',
            'event_date'=>now('Europe/Zurich')->toDateString(),'registration_deadline'=>now('Europe/Zurich')->toDateString(),'status_code'=>'open','revision'=>0,'team_ids'=>[$this->team]]);
        $p=SponsorRunParticipant::where('tenant_id',$this->tenant->id)->where('event_id',$e->id)->where('member_id',$this->child)->sole();
        try {
            $this->db->transaction(fn()=>$this->db->table('clubunio.mobile_sponsor_commands')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->admin->id,
                'command_key'=>(string)Str::uuid(),'operation'=>'create_link','fingerprint'=>str_repeat('a',64),'event_id'=>$this->event,'participant_id'=>$p->id,'result_revision'=>1]));
            $this->fail('A command must not bind a participant from a different event.');
        } catch(\Illuminate\Database\QueryException $error) { $this->assertSame('23503',$error->errorInfo[0]); }
        $this->assertSame(0,$this->db->table('clubunio.mobile_sponsor_commands')->count());
    }
    public function test_billing_exposes_only_local_authorized_status_without_recipient_or_provider_writes(): void {
        config(['clubunio-mobile.native_sponsor_billing_enabled'=>false]);
        $this->withToken($this->token($this->parent));
        $pledge=$this->postJson($this->base.'/participants/'.$this->participant.'/pledges',$this->pledge())->assertOk()->json('data.PledgeID');
        $this->insert('sponsor_run_invoices',['pledge_id'=>$pledge,'event_id'=>$this->event,'reference'=>(string)Str::uuid(),'state'=>'issued','connection_hash'=>str_repeat('a',64),
            'configuration_hash'=>str_repeat('b',64),'snapshot_encrypted'=>Crypt::encryptString('private recipient fixture'),'total_cents'=>500,'created_by'=>$this->admin->id]);
        $url=$this->base.'/events/'.$this->event.'/billing';
        $this->getJson($url)->assertForbidden();
        $this->withToken($this->token($this->admin))->getJson($url)->assertOk()->assertJsonPath('data.Settings.ReadOnly',true)
            ->assertJsonPath('data.Candidates.0.TotalCents',500)->assertJsonPath('data.Candidates.0.State','ISSUED')->assertDontSee('private recipient fixture')->assertDontSee('snapshot_encrypted');
        $this->postJson($this->base.'/billing/invoices/1/send',[])->assertStatus(503); Http::assertNothingSent(); Mail::assertNothingSent();
    }
}
