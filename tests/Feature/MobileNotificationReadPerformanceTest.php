<?php
namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileNotificationController,MobileNotificationService,MobilePushOutbox,MobileReadContext};
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

final class MobileNotificationReadPerformanceTest extends TestCase
{
    private $db; private Account $actor; private Tenant $tenant; private Request $request;
    protected function setUp(): void {
        parent::setUp();
        config(['database.default'=>'clubunio','database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge('clubunio'); $this->db=DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
            public function compileWheres($query) { return str_replace('source.id::text','CAST(source.id AS text)',parent::compileWheres($query)); }
        });
        $this->db->unprepared(<<<'SQL'
CREATE TABLE clubunio.accounts (id integer PRIMARY KEY,username text,password text,display_name text,active boolean,api_only boolean);
CREATE TABLE clubunio.tenants (id integer PRIMARY KEY,name text);
CREATE TABLE clubunio.tenant_accounts (tenant_id integer,account_id integer,role text,portal_enabled boolean,access_profile_id integer);
CREATE TABLE clubunio.mobile_sessions (id text PRIMARY KEY,account_id integer,token_hash text,revoked_at text,expires_at text);
CREATE TABLE clubunio.mobile_inbox (id integer PRIMARY KEY AUTOINCREMENT,tenant_id integer,account_id integer,kind text,object_id integer,event_key text,read_at text,created_at text);
CREATE TABLE clubunio.notifications (id integer PRIMARY KEY AUTOINCREMENT,tenant_id integer,account_id integer,object_type text,object_id integer,read_at text,created_at text,title text,body text);
CREATE TABLE clubunio.chat_conversations (id integer PRIMARY KEY,tenant_id integer,kind text);
CREATE TABLE clubunio.chat_memberships (conversation_id integer,tenant_id integer,account_id integer,muted boolean,left_at text);
INSERT INTO clubunio.accounts VALUES(1,'fixture',null,'Synthetic',1,0),(2,'foreign',null,'Foreign',1,0);
INSERT INTO clubunio.tenants VALUES(1,'Synthetic'),(2,'Foreign');
INSERT INTO clubunio.tenant_accounts VALUES(1,1,'admin',1,null),(1,2,'admin',1,null);
INSERT INTO clubunio.chat_conversations VALUES(1,1,'announcements');
SQL);
        $this->actor=Account::findOrFail(1); $this->tenant=Tenant::findOrFail(1);
        $secret=str_repeat('a',64); $session='00000000-0000-4000-8000-000000000001';
        $this->db->table('clubunio.mobile_sessions')->insert(['id'=>$session,'account_id'=>1,'token_hash'=>hash('sha256',$secret),'expires_at'=>now()->addHour()->toIso8601String()]);
        $this->request=Request::create('/notifications/read-all','POST');
        $this->request->headers->set('Authorization','Bearer '.$session.'.'.$secret);
        $this->request->attributes->set('mobile.actor',$this->actor); $this->request->attributes->set('mobile.tenant',$this->tenant);
        $this->request->attributes->set('mobile.session',(object)['id'=>$session,'account_id'=>1]);
        $this->app->instance('request',$this->request); Http::preventStrayRequests();
    }
    private function notice(array $extra=[]): int {
        return $this->db->table('clubunio.mobile_inbox')->insertGetId($extra+['tenant_id'=>1,'account_id'=>1,'kind'=>'announcement','object_id'=>1,'event_key'=>'synthetic:'.bin2hex(random_bytes(6)),'created_at'=>now()->toIso8601String()]);
    }
    public function test_twenty_three_notices_share_fresh_target_facts_and_use_batched_writes(): void {
        $ids=[]; for($i=0;$i<23;$i++) $ids[]=$this->notice();
        $late=$this->notice(); $this->request->merge(['through_id'=>max($ids),'observed_ids'=>$ids]);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $ack=(new MobileNotificationController)->readAll($this->request)->getData(true)['data'];
        $queries=$this->db->getQueryLog(); $this->db->disableQueryLog();
        self::assertSame(1,$ack['unreadCount']); self::assertEqualsCanonicalizing($ids,$ack['marked_ids']);
        self::assertEqualsCanonicalizing($ids,$ack['read_ids']); self::assertNull($this->db->table('clubunio.mobile_inbox')->where('id',$late)->value('read_at'));
        self::assertLessThan(30,count($queries));
        self::assertCount(3,array_filter($queries,fn($q)=>str_starts_with(strtolower($q['query']),'update')));
        self::assertFalse($this->request->attributes->has(MobileReadContext::class));
        $this->request->merge(['through_id'=>max($ids),'observed_ids'=>[$ids[0],$late]]);
        $retry=(new MobileNotificationController)->readAll($this->request)->getData(true)['data'];
        self::assertSame([],$retry['marked_ids']); self::assertSame([$ids[0]],$retry['read_ids']); self::assertSame(1,$retry['unreadCount']);
        Http::assertNothingSent();
    }
    public function test_count_and_noop_read_do_not_authorize_read_history_and_invalid_owned_targets_are_not_marked(): void {
        for($i=0;$i<120;$i++) $this->notice(['kind'=>'not_allowed','read_at'=>now()->toIso8601String()]);
        $valid=$this->notice(); $invalid=$this->notice(['kind'=>'not_allowed']);
        $foreign=$this->notice(['account_id'=>2]); $otherTenant=$this->notice(['tenant_id'=>2]);
        $service=app(MobileNotificationService::class);
        $spy=new class {
            public array $checks=[];
            public function targetAllowed($actor,$tenant,$kind,$object,$delivery=true): bool { $this->checks[]=$kind.':'.$object; return $kind==='announcement'; }
        };
        $this->app->instance(MobilePushOutbox::class,$spy);
        self::assertSame(1,$service->count($this->actor,$this->tenant)['unreadCount']); self::assertCount(2,$spy->checks);
        $spy->checks=[];
        $ack=$service->read($this->actor,$this->tenant,null,$otherTenant,[$valid,$invalid,$foreign,$otherTenant]);
        self::assertSame([$valid],$ack['read_ids']); self::assertCount(2,$spy->checks); self::assertSame(0,$ack['unreadCount']);
        foreach([$invalid,$foreign,$otherTenant] as $id) self::assertNull($this->db->table('clubunio.mobile_inbox')->where('id',$id)->value('read_at'));
        $this->db->table('clubunio.mobile_inbox')->where('id',$invalid)->update(['read_at'=>now()->toIso8601String()]);
        $spy->checks=[]; self::assertSame(0,$service->count($this->actor,$this->tenant)['unreadCount']); self::assertSame([],$spy->checks);
        $service->read($this->actor,$this->tenant,null,$otherTenant); self::assertSame([],$spy->checks);
    }
    public function test_distinct_targets_reuse_only_locked_actor_facts_and_source_updates_match_kind_object_and_owner(): void {
        $ids=[];
        for($i=2;$i<=24;$i++) {
            $this->db->table('clubunio.chat_conversations')->insert(['id'=>$i,'tenant_id'=>1,'kind'=>'announcements']);
            $ids[]=$this->notice(['object_id'=>$i]);
        }
        $this->request->merge(['through_id'=>max($ids),'observed_ids'=>$ids]);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $ack=(new MobileNotificationController)->readAll($this->request)->getData(true)['data'];
        $queries=$this->db->getQueryLog(); $this->db->disableQueryLog();
        self::assertCount(23,$ack['marked_ids']); self::assertSame(0,$ack['unreadCount']);
        self::assertLessThan(85,count($queries),'Distinct targets retain object checks, shared actor facts must not multiply');
        self::assertLessThan(5,count(array_filter($queries,fn($q)=>str_contains($q['query'],'from "clubunio"."tenant_accounts"'))));
        $spy=new class { public function targetAllowed($actor,$tenant,$kind,$object,$delivery=true): bool {return $kind==='bus';} };
        $this->app->instance(MobilePushOutbox::class,$spy);
        $matching=$this->db->table('clubunio.notifications')->insertGetId(['tenant_id'=>1,'account_id'=>1,'object_type'=>'bus_reservation','object_id'=>7]);
        $wrong=$this->db->table('clubunio.notifications')->insertGetId(['tenant_id'=>1,'account_id'=>1,'object_type'=>'bus_reservation','object_id'=>8]);
        $owned=$this->notice(['kind'=>'bus','object_id'=>7,'event_key'=>'notification:'.$matching]);
        $mismatch=$this->notice(['kind'=>'bus','object_id'=>99,'event_key'=>'notification:'.$wrong]);
        app(MobileNotificationService::class)->read($this->actor,$this->tenant,null,$mismatch,[$owned,$mismatch]);
        self::assertNotNull($this->db->table('clubunio.notifications')->where('id',$matching)->value('read_at'));
        self::assertNull($this->db->table('clubunio.notifications')->where('id',$wrong)->value('read_at'));
    }
    public function test_locked_projection_is_explicit_request_actor_tenant_and_transaction_bound_and_cleans_before_writes(): void {
        $reads=app(MobileReadContext::class); $count=0; $value=function()use(&$count){return ++$count;};
        $reads->duringLockedProjection($this->request,$this->actor,$this->tenant,function()use($reads,$value){
            self::assertSame(1,$reads->remember($this->actor,'probe',$value)); self::assertSame(2,$reads->remember($this->actor,'probe',$value));
        });
        $this->db->transaction(function()use($reads,$value){
            $reads->duringLockedProjection($this->request,$this->actor,$this->tenant,function()use($reads,$value){
                self::assertTrue($reads->lockedFor($this->actor,$this->tenant));
                self::assertSame(3,$reads->remember($this->actor,'probe',$value)); self::assertSame(3,$reads->remember($this->actor,'probe',$value));
                $copy=clone $this->request; $this->app->instance('request',$copy);
                self::assertSame(4,$reads->remember($this->actor,'probe',$value)); self::assertSame(5,$reads->remember($this->actor,'probe',$value));
                $this->app->instance('request',$this->request);
                self::assertFalse($reads->lockedFor($this->actor,Tenant::findOrFail(2)));
            });
            self::assertFalse($reads->lockedFor($this->actor,$this->tenant));
            self::assertSame(6,$reads->remember($this->actor,'probe',$value));
            $this->db->table('clubunio.tenant_accounts')->where('account_id',1)->delete();
            self::assertNull(app(\App\ClubUnio\Services\MemberAccessService::class)->membership($this->actor,$this->tenant));
        });
        try {$this->db->transaction(fn()=>$reads->duringLockedProjection($this->request,$this->actor,$this->tenant,fn()=>throw new \LogicException('synthetic')));}
        catch(\LogicException $e) { self::assertSame('synthetic',$e->getMessage()); }
        self::assertFalse($this->request->attributes->has(MobileReadContext::class));
        $this->db->table('clubunio.mobile_sessions')->update(['revoked_at'=>now()->toIso8601String()]);
        try {
            $this->db->transaction(fn()=>$reads->duringLockedProjection($this->request,$this->actor,$this->tenant,fn()=>self::fail('Revoked session entered projection')));
            self::fail('Revoked session accepted');
        } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { self::assertSame(401,$e->getStatusCode()); }
        self::assertFalse($this->request->attributes->has(MobileReadContext::class));
    }
}
