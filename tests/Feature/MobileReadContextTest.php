<?php
namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileReadContext,MobileAccess};
use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{MemberAccessService,ModulePermissionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MobileReadContextTest extends TestCase
{
    private $db; private Account $actor; private Tenant $tenant;
    protected function setUp(): void {
        parent::setUp();
        config(['database.default'=>'clubunio','database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge('clubunio');$this->db=DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $this->db->unprepared('CREATE TABLE clubunio.accounts (id integer PRIMARY KEY,username text,display_name text,password text,active boolean,api_only boolean);
CREATE TABLE clubunio.tenants (id integer PRIMARY KEY,slug text,name text);
CREATE TABLE clubunio.tenant_accounts (tenant_id integer,account_id integer,role text,portal_enabled boolean,access_profile_id integer);
CREATE TABLE clubunio.account_roles (code text,active boolean);
CREATE TABLE clubunio.access_profiles (id integer,tenant_id integer,active boolean,permissions text);');
        $this->db->table('clubunio.accounts')->insert(['id'=>1,'username'=>'synthetic','active'=>true,'api_only'=>false]);
        $this->db->table('clubunio.tenants')->insert(['id'=>1,'slug'=>'synthetic','name'=>'Synthetic']);
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id'=>1,'account_id'=>1,'role'=>'admin','portal_enabled'=>true]);
        $this->actor=Account::findOrFail(1);$this->tenant=Tenant::findOrFail(1);
    }
    private function request(string $method='GET'): Request {
        $r=Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/1/modules',$method);
        $r->attributes->set('mobile.actor',$this->actor);
        $r->attributes->set('mobile.session',(object)['id'=>'synthetic-session','account_id'=>1]);
        $this->app->instance('request',$r);return $r;
    }
    public function test_repeated_reads_share_only_fresh_actor_membership_and_permission_facts(): void {
        $r=$this->request();$reads=new MobileReadContext;$members=new MemberAccessService;$permissions=new ModulePermissionService;
        $this->db->enableQueryLog();
        $reads->during($r,$this->actor,function()use($members,$permissions){
            for($i=0;$i<20;$i++) {
                app(MobileAccess::class)->enter($this->actor,$this->tenant);
                self::assertSame('admin',$members->membership($this->actor,$this->tenant)->role);
                self::assertTrue($permissions->allows($this->actor,$this->tenant,'expenses','view'));
                self::assertFalse($permissions->isProfile($this->actor,$this->tenant));
            }
        });
        self::assertCount(4,$this->db->getQueryLog());
        self::assertFalse($r->attributes->has(MobileReadContext::class));
        $this->db->table('clubunio.tenant_accounts')->where('account_id',1)->delete();
        $next=$this->request();
        $reads->during($next,$this->actor,function()use($members,$permissions){
            self::assertNull($members->membership($this->actor,$this->tenant));
            self::assertFalse($permissions->allows($this->actor,$this->tenant,'expenses','view'));
        });
    }
    public function test_writes_never_use_a_read_snapshot_even_when_method_changes_inside_scope(): void {
        foreach(['POST','PATCH','PUT','DELETE'] as $method) {
            $this->db->table('clubunio.tenant_accounts')->where('account_id',1)->update(['role'=>'admin']);
            $r=$this->request($method);$reads=new MobileReadContext;
            $reads->during($r,$this->actor,function()use($r){
                self::assertFalse($r->attributes->has(MobileReadContext::class));
                self::assertTrue(app(ModulePermissionService::class)->allows($this->actor,$this->tenant,'expenses','view'));
                $this->db->table('clubunio.tenant_accounts')->where('account_id',1)->update(['role'=>'member']);
                self::assertFalse(app(ModulePermissionService::class)->allows($this->actor,$this->tenant,'expenses','view'));
            });
        }
        $r=$this->request();
        (new MobileReadContext)->during($r,$this->actor,function()use($r){
            self::assertSame('member',app(MemberAccessService::class)->membership($this->actor,$this->tenant)->role);
            $r->setMethod('POST');$this->db->table('clubunio.accounts')->where('id',1)->update(['active'=>false]);
            self::assertNull(app(MemberAccessService::class)->membership($this->actor,$this->tenant));
        });
    }
    public function test_profile_permissions_refresh_on_each_request_and_exceptions_clear_scope(): void {
        $this->db->table('clubunio.tenant_accounts')->update(['role'=>'profile','access_profile_id'=>7]);
        $this->db->table('clubunio.account_roles')->insert(['code'=>'profile','active'=>true]);
        $this->db->table('clubunio.access_profiles')->insert(['id'=>7,'tenant_id'=>1,'active'=>true,'permissions'=>json_encode(['expenses.view'])]);
        $reads=new MobileReadContext;$permissions=new ModulePermissionService;$r=$this->request();
        try {$reads->during($r,$this->actor,function()use($permissions){
            self::assertTrue($permissions->allows($this->actor,$this->tenant,'expenses','view'));
            self::assertFalse($permissions->allows($this->actor,$this->tenant,'expenses','create'));
            throw new \LogicException('fixture');
        });self::fail('Exception swallowed');}catch(\LogicException $e){self::assertSame('fixture',$e->getMessage());}
        self::assertFalse($r->attributes->has(MobileReadContext::class));
        $this->db->table('clubunio.access_profiles')->update(['active'=>false]);
        $reads->during($this->request(),$this->actor,fn()=>self::assertFalse($permissions->allows($this->actor,$this->tenant,'expenses','view')));
    }
    public function test_no_snapshot_for_unverified_or_copied_request_actor_or_other_tenant(): void {
        $r=$this->request();$reads=new MobileReadContext;$counter=0;
        $read=function()use(&$counter){return ++$counter;};
        $r->attributes->remove('mobile.session');
        $reads->during($r,$this->actor,function()use($reads,$read){self::assertSame(1,$reads->remember($this->actor,'x',$read));self::assertSame(2,$reads->remember($this->actor,'x',$read));});
        $r=$this->request();
        $reads->during($r,$this->actor,function()use($r,$reads,$read){
            self::assertSame(3,$reads->remember($this->actor,'x',$read));
            $copy=clone $r;$this->app->instance('request',$copy);
            self::assertSame(4,$reads->remember($this->actor,'x',$read));
            self::assertSame(5,$reads->remember($this->actor,'x',$read));
            $this->app->instance('request',$r);
            $other=clone $this->actor;$other->id=2;
            self::assertSame(6,$reads->remember($other,'x',$read));
            self::assertSame(7,$reads->remember($other,'x',$read));
            $tenant=new Tenant;$tenant->id=2;
            self::assertNull(app(MemberAccessService::class)->membership($this->actor,$tenant));
            self::assertSame('admin',app(MemberAccessService::class)->membership($this->actor,$this->tenant)->role);
        });
    }
    public function test_denied_http_request_reports_only_numeric_server_timing(): void {
        config(['app.url'=>'https://dev.clubunio.ch','clubunio-mobile.enabled'=>true]);
        $r=$this->request();$r->attributes->remove('mobile.session');
        $response=(new \App\ClubUnio\Mobile\MobileMiddleware)->handle($r,fn()=>throw new \LogicException('Unauthenticated request reached controller'));
        self::assertSame(401,$response->getStatusCode());
        self::assertMatchesRegularExpression('/^app;dur=[0-9]+[.][0-9]{2}$/',$response->headers->get('Server-Timing'));
        self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        self::assertFalse($r->attributes->has(MobileReadContext::class));
    }

}
