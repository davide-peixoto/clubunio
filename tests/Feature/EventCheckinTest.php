<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, ClubEvent, Member, Tenant};
use App\ClubUnio\Services\{EventCheckinService, MemberAccessService};
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class CheckinMemberAccessFixture extends MemberAccessService
{
    public function canEnter(Account $actor, Tenant $tenant): bool {
        return $actor->active && !$actor->api_only && DB::connection('clubunio')->table('clubora.tenant_accounts')
            ->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('role','!=','profile')->exists();
    }
    public function represented(Account $actor, Tenant $tenant, bool $ownOnly = false): Builder {
        return Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')
            ->whereIn('id', DB::connection('clubunio')->table('clubora.account_member_links')->where('tenant_id', $tenant->id)
                ->where('account_id', $actor->id)->where('state','approved')->where('kind', 'self')->select('member_id'));
    }
}

class EventCheckinTest extends TestCase
{
    private EventCheckinService $service;
    private Tenant $tenant;
    private Account $admin;
    private Account $member;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(random_bytes(32)), 'app.url'=>'https://synthetic.invalid',
            'database.default'=>'unconfigured-legacy', 'database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
            'clubunio-event-checkin.enabled'=>true,'clubunio-event-checkin.public_origin'=>'https://synthetic.invalid',
            'clubunio-event-checkin.tenant_origins'=>[]]);
        DB::purge('clubunio'); $db=DB::connection('clubunio');$db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,name text,slug text,created_at text,updated_at text);
CREATE TABLE clubora.accounts(id integer primary key,username text,active integer default 1,api_only integer default 0,created_at text,updated_at text);
CREATE TABLE clubora.tenant_accounts(tenant_id integer,account_id integer,role text,portal_enabled integer default 1);
CREATE TABLE clubora.member_statuses(id integer primary key,tenant_id integer,name text,active integer default 1,ends_membership integer default 0);
CREATE TABLE clubora.members(id integer primary key,tenant_id integer,first_name text,last_name text,status_id integer,archived_at text,created_at text,updated_at text);
CREATE TABLE clubora.account_member_links(tenant_id integer,account_id integer,member_id integer,kind text,state text);
CREATE TABLE clubora.club_events(id integer primary key,tenant_id integer,name text,location text,season_id integer,team_id integer,
 active integer default 1,revision integer default 0,checkin_enabled integer default 0,checkin_opens_at text,checkin_closes_at text,created_at text,updated_at text);
CREATE TABLE clubora.event_checkin_codes(id text primary key,tenant_id integer,event_id integer,token_hash text unique,token_encrypted text,
 revision integer default 1,created_at text default CURRENT_TIMESTAMP,updated_at text default CURRENT_TIMESTAMP,unique(tenant_id,event_id));
CREATE TABLE clubora.event_checkins(id integer primary key autoincrement,tenant_id integer,event_id integer,member_id integer,checked_in_at text,checked_in_by integer,
 created_at text default CURRENT_TIMESTAMP,updated_at text default CURRENT_TIMESTAMP,unique(tenant_id,event_id,member_id));
CREATE TABLE clubora.object_changes(id integer primary key autoincrement,tenant_id integer,actor_id integer,object_type text,object_id integer,after_value text);
CREATE TABLE clubora.event_guests(id integer primary key,tenant_id integer,member_id integer,club_event_id integer,active integer);
CREATE TABLE clubora.team_seasons(id integer primary key,tenant_id integer,team_id integer,season_id integer,active integer);
CREATE TABLE clubora.team_players(id integer primary key,tenant_id integer,team_season_id integer,member_id integer,active integer);
CREATE TABLE clubora.team_staff(id integer primary key,tenant_id integer,team_season_id integer,member_id integer,active integer);
SQL);
        $db->table('clubora.tenants')->insert([['id'=>1,'slug'=>'synthetic','name'=>'Synthetic Club'],['id'=>2,'slug'=>'other','name'=>'Other Club']]);
        $db->table('clubora.accounts')->insert([['id'=>1,'username'=>'admin'],['id'=>2,'username'=>'self'],['id'=>3,'username'=>'parent']]);
        $db->table('clubora.tenant_accounts')->insert([['tenant_id'=>1,'account_id'=>1,'role'=>'admin'],['tenant_id'=>1,'account_id'=>2,'role'=>'member'],['tenant_id'=>1,'account_id'=>3,'role'=>'member']]);
        $db->table('clubora.member_statuses')->insert(['id'=>1,'tenant_id'=>1,'name'=>'Synthetic']);
        $db->table('clubora.members')->insert([['id'=>44,'tenant_id'=>1,'first_name'=>'Synthetic','last_name'=>'Self','status_id'=>1],['id'=>45,'tenant_id'=>1,'first_name'=>'Other','last_name'=>'Member','status_id'=>1]]);
        $db->table('clubora.account_member_links')->insert([['tenant_id'=>1,'account_id'=>2,'member_id'=>44,'kind'=>'self','state'=>'approved'],['tenant_id'=>1,'account_id'=>3,'member_id'=>44,'kind'=>'parent','state'=>'approved']]);
        $db->table('clubora.club_events')->insert(['id'=>10,'tenant_id'=>1,'name'=>'General Assembly','location'=>'Club House','season_id'=>3]);
        $this->tenant=Tenant::findOrFail(1);$this->admin=Account::findOrFail(1);$this->member=Account::findOrFail(2);
        $this->app->instance(MemberAccessService::class,new CheckinMemberAccessFixture);
        $this->service=new EventCheckinService;
        CarbonImmutable::setTestNow('2026-11-22T12:00:00Z');
    }
    protected function tearDown(): void { CarbonImmutable::setTestNow();DB::purge('clubunio');parent::tearDown(); }
    private function open(): array {
        $q=$this->service->configure($this->admin,$this->tenant,10,['Enabled'=>true,'Revision'=>0,'OpensAt'=>'2026-11-22T11:00:00Z','ClosesAt'=>'2026-11-22T13:00:00Z']);
        return [$q,basename(parse_url($q['Url'],PHP_URL_PATH))];
    }
    private function input(array $qr,int $member=44): array {
        return ['MemberID'=>$member,'Confirmation'=>true,'TokenRevision'=>$qr['TokenRevision'],'EventRevision'=>$qr['Revision']];
    }
    private function assertDeniedStatus(callable $operation,int $status): void {
        try { $operation();$this->fail('Expected denial'); }
        catch (HttpExceptionInterface $e) { $this->assertSame($status,$e->getStatusCode()); }
    }
    public function test_board_qr_is_stable_and_reads_do_not_record_attendance(): void {
        $this->assertNull($this->service->qr($this->admin,$this->tenant,10)['Url']);
        $this->assertSame(0,DB::connection('clubunio')->table('clubora.event_checkin_codes')->count());
        [$qr,$token]=$this->open();
        $this->assertMatchesRegularExpression('~^https://synthetic\.invalid/event-check-in/[a-f0-9]{64}$~D',$qr['Url']);
        $this->assertSame($qr['Url'],$this->service->qr($this->admin,$this->tenant,10)['Url']);
        $shown=$this->service->show($this->member,$this->tenant,$token);
        $this->assertTrue($shown['Event']['IsOpen']);$this->assertFalse($shown['Members'][0]['Present']);
        $this->assertSame(0,DB::connection('clubunio')->table('clubora.event_checkins')->count());
        $stored=DB::connection('clubunio')->table('clubora.event_checkin_codes')->first();
        $this->assertSame(hash('sha256',$token),$stored->token_hash);$this->assertNotSame($token,$stored->token_encrypted);
    }
    public function test_confirmation_is_explicit_idempotent_and_never_changes_rsvp(): void {
        [$qr,$token]=$this->open();$db=DB::connection('clubunio');
        $db->table('clubora.event_guests')->insert(['id'=>1,'tenant_id'=>1,'club_event_id'=>10,'member_id'=>44,'active'=>0]);
        $first=$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr));
        $second=$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr));
        $this->assertFalse($first['AlreadyPresent']);$this->assertTrue($second['AlreadyPresent']);
        $this->assertSame($first['CheckedInAt'],$second['CheckedInAt']);$this->assertSame(1,$db->table('clubora.event_checkins')->count());
        $this->assertSame(0,$db->table('clubora.event_guests')->value('active'));
        CarbonImmutable::setTestNow('2026-11-22T14:00:00Z');
        $this->assertTrue($this->service->confirm($this->member,$this->tenant,$token,$this->input($qr))['AlreadyPresent']);
    }
    public function test_parent_and_admin_cannot_confirm_for_someone_else(): void {
        [$qr,$token]=$this->open();
        $this->assertDeniedStatus(fn()=>$this->service->confirm(Account::findOrFail(3),$this->tenant,$token,$this->input($qr)),403);
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->admin,$this->tenant,$token,$this->input($qr)),403);
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr,45)),403);
    }
    public function test_window_start_is_inclusive_and_end_is_exclusive(): void {
        [$qr,$token]=$this->open();CarbonImmutable::setTestNow('2026-11-22T10:59:59Z');
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr)),409);
        CarbonImmutable::setTestNow('2026-11-22T13:00:00Z');
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr)),409);
        CarbonImmutable::setTestNow('2026-11-22T11:00:00Z');
        $this->assertTrue($this->service->confirm($this->member,$this->tenant,$token,$this->input($qr))['Confirmed']);
    }
    public function test_rotated_token_and_changed_event_revision_are_rejected(): void {
        [$qr,$token]=$this->open();
        DB::connection('clubunio')->table('clubora.club_events')->where('id',10)->update(['revision'=>2]);
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr)),409);
        $fresh=$this->service->rotate($this->admin,$this->tenant,10,2);
        try {$this->service->show($this->member,$this->tenant,$token);$this->fail('Old bearer accepted');}
        catch (\Illuminate\Database\RecordNotFoundException) {$this->addToAssertionCount(1);}
        $this->assertNotSame($qr['Url'],$fresh['Url']);
    }
    public function test_closed_or_inactive_event_cannot_create_new_attendance(): void {
        [$qr,$token]=$this->open();$this->service->close($this->admin,$this->tenant,10,1);
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr)),409);
        $this->assertSame(0,DB::connection('clubunio')->table('clubora.event_checkins')->count());
    }
    public function test_team_scoped_event_requires_assignment_or_explicit_invitation(): void {
        DB::connection('clubunio')->table('clubora.club_events')->where('id',10)->update(['team_id'=>7]);
        [$qr,$token]=$this->open();$this->assertSame([],$this->service->show($this->member,$this->tenant,$token)['Members']);
        DB::connection('clubunio')->table('clubora.team_seasons')->insert(['id'=>5,'tenant_id'=>1,'team_id'=>7,'season_id'=>3,'active'=>1]);
        DB::connection('clubunio')->table('clubora.team_players')->insert(['id'=>1,'tenant_id'=>1,'team_season_id'=>5,'member_id'=>44,'active'=>1]);
        $this->assertCount(1,$this->service->show($this->member,$this->tenant,$token)['Members']);
        $this->assertTrue($this->service->confirm($this->member,$this->tenant,$token,$this->input($qr))['Confirmed']);
    }
    public function test_wrong_club_cannot_resolve_token_or_steal_attendance(): void {
        [$qr,$token]=$this->open();
        $this->assertDeniedStatus(fn()=>$this->service->show($this->member,Tenant::findOrFail(2),$token),403);
        $this->assertSame(0,DB::connection('clubunio')->table('clubora.event_checkins')->count());
    }
    public function test_member_cannot_generate_a_board_code_and_false_confirmation_is_rejected(): void {
        $this->assertDeniedStatus(fn()=>$this->service->qr($this->member,$this->tenant,10),403);
        [$qr,$token]=$this->open();$input=$this->input($qr);$input['Confirmation']='yes';
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$input),422);
    }
    public function test_pending_or_revoked_self_link_and_disabled_actor_cannot_write(): void {
        [$qr,$token]=$this->open();$db=DB::connection('clubunio');
        foreach(['pending','revoked']as$state){
            $db->table('clubora.account_member_links')->where('account_id',2)->update(['state'=>$state]);
            $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr)),403);
        }
        $db->table('clubora.account_member_links')->where('account_id',2)->update(['state'=>'approved']);
        $db->table('clubora.accounts')->where('id',2)->update(['active'=>0]);
        $this->assertDeniedStatus(fn()=>$this->service->confirm($this->member,$this->tenant,$token,$this->input($qr)),401);
        $this->assertSame(0,$db->table('clubora.event_checkins')->count());
    }
    public function test_explicit_team_invitation_is_supported_without_a_team_assignment(): void {
        DB::connection('clubunio')->table('clubora.club_events')->where('id',10)->update(['team_id'=>7]);
        [$qr,$token]=$this->open();$db=DB::connection('clubunio');
        $db->table('clubora.event_guests')->insert(['id'=>1,'tenant_id'=>1,'member_id'=>44,'club_event_id'=>10,'active'=>0]);
        $this->assertCount(0,$this->service->show($this->member,$this->tenant,$token)['Members']);
        $db->table('clubora.event_guests')->where('id',1)->update(['active'=>1]);
        $this->assertCount(1,$this->service->show($this->member,$this->tenant,$token)['Members']);
        $this->assertTrue($this->service->confirm($this->member,$this->tenant,$token,$this->input($qr))['Confirmed']);
    }
    public function test_disabled_feature_fails_before_touching_not_yet_migrated_tables(): void {
        config(['clubunio-event-checkin.enabled'=>false]);
        DB::connection('clubunio')->statement('DROP TABLE clubora.event_checkin_codes');
        $this->assertDeniedStatus(fn()=>$this->service->qr($this->admin,$this->tenant,10),503);
        $this->assertDeniedStatus(fn()=>$this->service->show($this->member,$this->tenant,str_repeat('0',64)),503);
    }
    public function test_old_board_revision_cannot_overwrite_window_and_custom_origin_is_explicit(): void {
        config(['clubunio-event-checkin.tenant_origins'=>[1=>'https://custom.synthetic.invalid']]);
        [$qr,$token]=$this->open();$this->assertStringStartsWith('https://custom.synthetic.invalid/event-check-in/',$qr['Url']);
        $this->assertDeniedStatus(fn()=>$this->service->close($this->admin,$this->tenant,10,0),409);
        $this->assertTrue($this->service->show($this->member,$this->tenant,$token)['Event']['IsOpen']);
        config(['clubunio-event-checkin.tenant_origins'=>[1=>'https://evil.invalid/path?token=x']]);
        $this->assertDeniedStatus(fn()=>$this->service->origin($this->tenant),503);
    }
}
