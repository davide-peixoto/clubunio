<?php
namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileMemberDocuments, MobileNotificationService, MobileSponsorData, MobileTrainingController, MobileTrainingPlanService};
use App\ClubUnio\Models\{Account, SponsorRunEvent, Tenant};
use App\ClubUnio\Services\SponsorRunService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

final class MobileOtherModulesPerformanceTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor, $other;
    private int $registration, $team, $season;
    protected function setUp(): void {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        $this->db = DB::connection('clubunio');
        self::assertSame('pgsql', $this->db->getDriverName()); self::assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); Http::preventStrayRequests(); Mail::fake();
        config(['cache.default'=>'array','hashing.bcrypt.rounds'=>4]);
        $this->tenant = Tenant::create(['slug'=>'other-perf-'.bin2hex(random_bytes(6)),'name'=>'Synthetic']);
        $this->actor = $this->account(); $this->other = $this->account();
        $this->season = $this->insert('seasons',['name'=>'Synthetic current']);
        $this->insert('tenant_settings',['area'=>'general','key'=>'SaisonID','label'=>'Synthetic','value_type'=>'integer','encrypted_value'=>Crypt::encryptString((string)$this->season)]);
        $this->team = $this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Synthetic']),'season_id'=>$this->season,'label'=>'Synthetic current']);
        $this->registration = $this->insert('registrations',['first_name'=>'Synthetic','last_name'=>'Applicant']);
    }
    protected function tearDown(): void {
        if (isset($this->db)) { $this->db->disableQueryLog(); while ($this->db->transactionLevel()) $this->db->rollBack(); }
        parent::tearDown();
    }
    private function insert(string $table,array $values): int { return $this->db->table('clubora.'.$table)->insertGetId($values+['tenant_id'=>$this->tenant->id]); }
    private function account(string $role='admin'): Account {
        $a=new Account; $a->forceFill(['username'=>'other-perf-'.bin2hex(random_bytes(6)),'display_name'=>'Synthetic','password'=>bcrypt('fixture'),'active'=>true,'api_only'=>false])->save();
        $this->insert('tenant_accounts',['account_id'=>$a->id,'role'=>$role,'portal_enabled'=>true]); return $a;
    }
    private function notice(?Account $actor=null,?int $object=null): int {
        return $this->insert('notifications',['account_id'=>($actor??$this->actor)->id,'object_type'=>'registration','object_id'=>$object??$this->registration,'title'=>'Synthetic actual title','body'=>'Synthetic private body']);
    }
    private function queries(callable $read): array {
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        $result=$read(); $log=$this->db->getQueryLog(); $this->db->disableQueryLog(); return [$result,$log];
    }
    public function test_repeated_notifications_index_only_delta_and_do_not_scale_queries_per_notice(): void {
        $service=app(MobileNotificationService::class); $this->notice();
        $first=$service->page($this->actor,$this->tenant,0,20,false); self::assertSame(1,$first['totalRecordCount']);
        [$one,$oneQueries]=$this->queries(fn()=>$service->page($this->actor,$this->tenant,0,20,false));
        for($i=0;$i<19;$i++) $this->notice();
        $service->page($this->actor,$this->tenant,0,20,false);
        [$many,$manyQueries]=$this->queries(fn()=>$service->page($this->actor,$this->tenant,0,20,false));
        self::assertCount(20,$many['notifications']); self::assertSame(20,$many['unreadCount']);
        self::assertCount(count($oneQueries),$manyQueries);
        self::assertCount(0,array_filter($manyQueries,fn($q)=>str_starts_with(strtolower($q['query']),'insert')));
        foreach($many['notifications'] as $row) { self::assertSame('Synthetic actual title',$row['title']); self::assertSame('Synthetic private body',$row['body']); }
        $texts=array_filter($manyQueries,fn($q)=>str_contains($q['query'],'"title"')&&str_contains($q['query'],'"body"'));
        self::assertCount(1,$texts); Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_source_read_sync_and_current_visibility_account_scope_are_preserved(): void {
        $service=app(MobileNotificationService::class); $notice=$this->notice(); $this->notice(); $this->notice($this->other);
        $page=$service->page($this->actor,$this->tenant,0,20,false); self::assertSame(2,$page['unreadCount']);
        $this->db->table('clubora.notifications')->where('tenant_id',$this->tenant->id)->where('id',$notice)->update(['read_at'=>now()->toIso8601String()]);
        self::assertSame(1,$service->count($this->actor,$this->tenant)['unreadCount']);
        self::assertNotNull($this->db->table('clubora.mobile_inbox')->where('tenant_id',$this->tenant->id)->where('account_id',$this->actor->id)->where('event_key','notification:'.$notice)->value('read_at'));
        self::assertSame(1,$service->page($this->other,$this->tenant,0,20,false)['unreadCount']);
        $this->db->table('clubora.registrations')->where('tenant_id',$this->tenant->id)->where('id',$this->registration)->update(['active'=>false]);
        self::assertSame(0,$service->page($this->actor,$this->tenant,0,20,false)['totalRecordCount']);
        self::assertSame(0,$service->count($this->other,$this->tenant)['unreadCount']);
        $this->db->table('clubora.accounts')->where('id',$this->actor->id)->update(['active'=>false]);
        try { $service->page($this->actor,$this->tenant,0,20,false); self::fail('Revoked account succeeded'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { self::assertSame(403,$e->getStatusCode()); }
    }
    private function plan(bool $shared=true): int {
        return $this->insert('training_plans',['owner_id'=>$this->actor->id,'team_season_id'=>$this->team,'title'=>'Synthetic training','shared'=>$shared,
            'input_json'=>json_encode(['teamName'=>'Synthetic','durationMinutes'=>60,'playerCount'=>14]),'plan_json'=>'{}']);
    }
    private function training(): array {
        $r=Request::create('/synthetic','GET',['limit'=>50]); $r->attributes->set('mobile.actor',$this->actor); $r->attributes->set('mobile.tenant',$this->tenant);
        return app(MobileTrainingController::class)->index($r)->getData(true)['data']['trainingPlans'];
    }
    public function test_saved_training_list_has_constant_projection_queries_and_keeps_share_fallback(): void {
        $first=$this->plan(); $this->training();
        [$one,$oneQueries]=$this->queries(fn()=>$this->training());
        for($i=0;$i<18;$i++) $this->plan(); $private=$this->plan(false);
        $target=$this->insert('team_seasons',['team_id'=>$this->insert('teams',['name'=>'Synthetic target']),'season_id'=>$this->season,'label'=>'Synthetic target']);
        $this->db->table('clubora.mobile_training_plan_shares')->insert(['tenant_id'=>$this->tenant->id,'plan_id'=>$first,'team_season_id'=>$target,'shared_by'=>$this->actor->id]);
        [$rows,$manyQueries]=$this->queries(fn()=>$this->training()); self::assertCount(20,$rows); self::assertCount(count($oneQueries),$manyQueries);
        $byId=array_column($rows,null,'id'); self::assertSame($target,$byId[$first]['sharedTeamSaisonId']); self::assertNull($byId[$private]['sharedTeamSaisonId']);
        foreach($rows as $row) { self::assertTrue($row['hasPdf']); if($row['id']!==$private&&$row['id']!==$first) self::assertSame($this->team,$row['sharedTeamSaisonId']); }
        foreach($manyQueries as $query) { self::assertStringNotContainsString('plan_json',$query['query']); self::assertStringNotContainsString('original_bytes',$query['query']); }
        self::assertTrue(app(MobileTrainingPlanService::class)->can($this->actor,$this->tenant,'download'));
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_document_list_metadata_is_batched_without_reading_private_file_bytes(): void {
        $member=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Document member']);
        $type=$this->insert('document_types',['code'=>'SYNTHETIC','name'=>'Synthetic type']);
        $document=function()use($member,$type){ return $this->insert('member_documents',['member_id'=>$member,'document_type_id'=>$type,'filename'=>'synthetic.pdf',
            'original_filename'=>'Synthetic document','storage_path'=>'never-read/synthetic/','sha256'=>str_repeat('a',64),'portal_visible'=>true]); };
        $service=app(MobileMemberDocuments::class);
        $page=fn()=>$service->index(Request::create('/synthetic','GET',['mine'=>0,'recperpage'=>50]),$this->actor,$this->tenant);
        $id=$document(); [$one,$oneQueries]=$this->queries($page);
        for($i=0;$i<19;$i++) $document(); [$many,$manyQueries]=$this->queries($page);
        self::assertSame(20,$many['totalRecordCount']); self::assertCount(20,$many['Dokumente']); self::assertCount(count($oneQueries),$manyQueries);
        foreach($many['Dokumente'] as $row) self::assertSame($service->view($this->actor,$this->tenant,$row['QuittungID'],'file'),$row);
        self::assertFalse($many['Dokumente'][0]['CanSign']); self::assertSame('Synthetic',$many['Dokumente'][0]['Vorname']);
        self::assertStringNotContainsString('never-read',json_encode($many));
        $this->db->table('clubora.member_documents')->where('tenant_id',$this->tenant->id)->update(['portal_visible'=>false]);
        $personal=$this->account('member');
        $this->insert('account_member_links',['account_id'=>$personal->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic']);
        self::assertSame(0,$service->index(Request::create('/synthetic','GET',['mine'=>0]),$personal,$this->tenant)['totalRecordCount']);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    private function sponsorEvents(): array {
        $rows=[];
        foreach(['draft','open','closed','cancelled'] as $status) {
            $this->insert('sponsor_run_statuses',['code'=>$status,'name'=>$status]);
            $id=$this->insert('sponsor_run_events',['season_id'=>$this->season,'title'=>'Synthetic run','place'=>'Synthetic field','event_date'=>now('Europe/Zurich')->toDateString(),
                'registration_deadline'=>now('Europe/Zurich')->toDateString(),'status_code'=>$status,'created_by'=>$this->actor->id]);
            $this->insert('sponsor_run_event_teams',['event_id'=>$id,'team_season_id'=>$this->team]);
            $rows[]=SponsorRunEvent::where('tenant_id',$this->tenant->id)->findOrFail($id);
        }
        return $rows;
    }
    private function link(Account $actor,int $member): void { $this->insert('account_member_links',['account_id'=>$actor->id,'member_id'=>$member,'kind'=>'self','state'=>'approved','source'=>'synthetic']); }
    public function test_sponsor_event_batch_matches_all_status_and_live_helper_rights_with_constant_queries(): void {
        $rows=collect($this->sponsorEvents()); $service=app(MobileSponsorData::class);
        $helper=$this->account('member'); $this->link($helper,$this->insert('members',['first_name'=>'Synthetic helper']));
        $helperId=$this->insert('sponsor_run_helpers',['event_id'=>$rows[1]->id,'account_id'=>$helper->id,'can_checkin'=>true,'can_record_laps'=>false,
            'valid_from'=>now()->subHour()->toIso8601String(),'valid_until'=>now()->addHour()->toIso8601String()]);
        foreach([$this->actor,$helper] as $actor) {
            $expected=$rows->map(fn($row)=>$service->event($actor,$this->tenant,$row))->all();
            self::assertSame($expected,$service->events($actor,$this->tenant,$rows));
        }
        [$one,$oneQueries]=$this->queries(fn()=>$service->events($this->actor,$this->tenant,$rows->take(1)));
        [$many,$manyQueries]=$this->queries(fn()=>$service->events($this->actor,$this->tenant,$rows));
        self::assertCount(count($oneQueries),$manyQueries);
        $this->db->table('clubora.sponsor_run_helpers')->where('tenant_id',$this->tenant->id)->where('id',$helperId)->update(['valid_until'=>now()->subMinute()->toIso8601String()]);
        self::assertFalse($service->events($helper,$this->tenant,$rows)[1]['CanCheckIn']);
    }
    public function test_sponsor_participant_batch_matches_parent_trainer_helper_and_office_without_financial_leak(): void {
        $event=$this->sponsorEvents()[1]; $members=[];
        $this->insert('tenant_settings',['area'=>'sponsor_run','key'=>'PUBLIC_WEB_ENABLED','label'=>'Synthetic','value_type'=>'boolean','enabled'=>true,'is_secret'=>false,'encrypted_value'=>Crypt::encryptString('true')]);
        for($i=0;$i<20;$i++) {
            $member=$members[]=$this->insert('members',['first_name'=>'Synthetic','last_name'=>'Participant '.$i]);
            $this->insert('team_players',['team_season_id'=>$this->team,'member_id'=>$member]);
            $this->insert('sponsor_run_participants',['event_id'=>$event->id,'member_id'=>$member,'team_season_id'=>$this->team,'required'=>true]);
        }
        $parent=$this->account('member'); $this->link($parent,$members[0]);
        $trainer=$this->account('trainer'); $coach=$this->insert('members',['first_name'=>'Synthetic coach']); $this->link($trainer,$coach);
        $this->insert('team_staff',['team_season_id'=>$this->team,'member_id'=>$coach]);
        $helper=$this->account('member'); $this->link($helper,$this->insert('members',['first_name'=>'Synthetic helper']));
        $this->insert('sponsor_run_helpers',['event_id'=>$event->id,'account_id'=>$helper->id,'can_checkin'=>true,'can_record_laps'=>true,
            'valid_from'=>now()->subHour()->toIso8601String(),'valid_until'=>now()->addHour()->toIso8601String()]);
        $s=app(SponsorRunService::class); $data=app(MobileSponsorData::class);
        $rows=$s->participants($this->actor,$this->tenant,$event->id,['scope'=>'manage'])->get();
        foreach([$this->actor,$parent,$trainer,$helper] as $actor) {
            $expected=$rows->map(fn($p)=>$data->participant($actor,$this->tenant,$p,null))->all();
            self::assertSame($expected,$data->participants($actor,$this->tenant,$event->id,$rows,[]));
        }
        self::assertCount(1,array_filter($data->participants($parent,$this->tenant,$event->id,$rows,[]),fn($row)=>$row['CanShare']));
        [$one,$oneQueries]=$this->queries(fn()=>$data->participants($trainer,$this->tenant,$event->id,$rows->take(1),[]));
        [$many,$manyQueries]=$this->queries(fn()=>$data->participants($trainer,$this->tenant,$event->id,$rows,[]));
        self::assertCount(count($oneQueries),$manyQueries);
        foreach($data->participants($helper,$this->tenant,$event->id,$rows,[]) as $row) { self::assertFalse($row['CanViewPledges']); self::assertNull($row['SponsorCount']); }
        $this->db->table('clubora.account_member_links')->where('tenant_id',$this->tenant->id)->where('account_id',$parent->id)->delete();
        try { $data->participants($parent,$this->tenant,$event->id,$rows,[]); self::fail('Revoked parent succeeded'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { self::assertSame(403,$e->getStatusCode()); }
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
}
