<?php

namespace Tests\Feature;

use App\ClubUnio\Http\{PlatformOidcController, KeycloakMobilePairingController};
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{VereinOidcDomain, KeycloakCodeExchange, KeycloakAccountLinks, MemberAccessService, PlatformAdministrationService};
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class VereinOidcMemberCallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
            'session.driver'=>'array','clubunio-parent-access.enabled'=>false,
            'clubunio-verein-oidc.enabled'=>true,'clubunio-verein-oidc.only'=>false,
            'clubunio-verein-oidc.origin'=>'https://club.test','clubunio-verein-oidc.client'=>'synthetic',
            'clubunio-verein-oidc.secret'=>'synthetic','clubunio-verein-oidc.subjects'=>['subject'=>1],
            'clubunio-verein-oidc.invites_enabled'=>true,'clubunio-verein-oidc.mobile_enabled'=>true]);
        DB::purge('clubunio'); $db=DB::connection('clubunio'); $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared("CREATE TABLE clubora.accounts(id integer primary key,active integer,api_only integer);
            INSERT INTO clubora.accounts VALUES(1,1,0);
            CREATE TABLE clubora.tenants(id integer primary key,slug text);
            INSERT INTO clubora.tenants VALUES(3,'own-club');
            CREATE TABLE clubora.tenant_accounts(tenant_id integer,account_id integer,role text);
            INSERT INTO clubora.tenant_accounts VALUES(3,1,'member');");
        // SQLite cannot select schema.table.*, unlike PostgreSQL.
        $db->setQueryGrammar(new class($db) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
            public function wrap($value, $prefixAlias = false) {
                if (is_string($value) && $value === 'clubora.tenants.*') return '"tenants".*';
                return parent::wrap($value, $prefixAlias);
            }
        });
        $tenant=Tenant::findOrFail(3);
        $this->app->instance(VereinOidcDomain::class,new class($tenant) {
            public function __construct(private $tenant) {}
            public function configuration($request) { return ['tenant'=>$this->tenant]; }
        });
        $this->app->instance(KeycloakCodeExchange::class,new class {
            public function verify(...$args) { return (object)['sub'=>'subject','iss'=>'https://identity.test','exp'=>time()+600]; }
        });
        $this->app->instance(KeycloakAccountLinks::class,new class {
            public function accountId(...$args) { return 1; }
        });
        $this->app->instance(KeycloakMobilePairingController::class,new class {
            public function complete(...$args) { return 'synthetic-return-secret'; }
        });
    }
    protected function tearDown(): void { DB::purge('clubunio'); parent::tearDown(); }
    private function finishLogin(bool $permitted)
    {
        $access=$this->mock(MemberAccessService::class);
        $access->shouldReceive('canEnter')->withArgs(fn($actor,$tenant)=>(int)$actor->id===1 && (int)$tenant->id===3)->andReturn($permitted);
        $request=Request::create('https://club.test/vereinslogin/oidc/callback?state=synthetic-state&code=synthetic-code');
        $route=(new Route('GET','vereinslogin/oidc/callback',fn()=>null))->name('clubunio.verein.oidc.callback');
        $request->setRouteResolver(fn()=>$route);
        $session=$this->app['session']->driver(); $request->setLaravelSession($session);
        $session->put('clubunio.oidc.pending',['state'=>'synthetic-state','expires'=>time()+60,'verifier'=>'synthetic',
            'nonce'=>'synthetic','callback'=>'https://club.test/vereinslogin/oidc/callback','client'=>'synthetic',
            'pair'=>['pair'=>'synthetic-pair','native'=>true]]);
        return app(PlatformOidcController::class)->callback($request,app(PlatformAdministrationService::class));
    }
    public function test_authorized_member_can_finish_native_login_on_club_domain_without_backoffice_access(): void
    {
        $response=$this->finishLogin(true);
        $this->assertSame(302,$response->getStatusCode());
        $this->assertStringStartsWith('clubunio-auth://signin?',$response->headers->get('Location'));
    }
    public function test_target_club_denial_still_blocks_callback_before_native_completion(): void
    {
        try { $this->finishLogin(false); $this->fail('Expected target club denial'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(403,$error->getStatusCode()); }
    }
}
