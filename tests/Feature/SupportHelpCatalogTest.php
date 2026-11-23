<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportHelpCatalogTest extends TestCase
{
    protected function setUp():void{
        parent::setUp();config(['app.url'=>'https://dev.clubunio.ch','database.connections.clubunio'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);DB::purge('clubunio');$db=DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");$db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants(id integer primary key,slug text,name text,created_at text,updated_at text);
CREATE TABLE clubora.accounts(id integer primary key,username text,display_name text,password text,active integer,api_only integer,preferred_locale text,created_at text,updated_at text);
CREATE TABLE clubora.tenant_accounts(tenant_id integer,account_id integer,role text,portal_enabled integer);
CREATE TABLE clubora.members(id integer primary key,tenant_id integer,first_name text,last_name text,birth_date text,created_at text,updated_at text);
CREATE TABLE clubora.account_member_links(tenant_id integer,account_id integer,member_id integer,kind text,state text,source text);
CREATE TABLE clubora.support_articles(id integer primary key,tenant_id integer,audience text,published integer,locale text,title text,body text,video_url text);
CREATE TABLE clubora.support_tickets(id text primary key,tenant_id integer,requester_id integer,audience text,subject text,status text default 'open',created_at text default CURRENT_TIMESTAMP);
CREATE TABLE clubora.support_posts(id integer primary key,ticket_id text,body text,internal integer,created_at text);
CREATE TABLE clubora.support_triage(ticket_id text primary key,topic text,draft text,provider_generated integer);
SQL);
        // SQLite cannot execute PostgreSQL interval-based member representation. The PG
        // checker uses the production policy; here only the already-proven entry fact is substituted.
        $this->app->instance(\App\ClubUnio\Services\MemberAccessService::class,new class extends \App\ClubUnio\Services\MemberAccessService{
            public function canEnter(\App\ClubUnio\Models\Account $actor,\App\ClubUnio\Models\Tenant $tenant):bool{
                return $actor->fresh()?->active&&DB::connection('clubunio')->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->where('portal_enabled',true)->exists();
            }
        });
    }
    protected function tearDown():void{DB::purge('clubunio');parent::tearDown();}
    public function test_curated_help_search_scope_locale_context_and_provider_privacy():void{
        if(!defined('CLUBUNIO_SUPPORT_HELP_TEST_LOADER'))define('CLUBUNIO_SUPPORT_HELP_TEST_LOADER',true);
        require_once base_path('deploy/check-support-help.php');
        \clubunioSupportHelpChecks($this->app,DB::connection('clubunio'),fn(bool $ok,string $case)=>$this->assertTrue($ok,$case));
    }
    public function test_every_curated_module_has_an_allowlisted_question_route():void{
        $guide=app(\App\ClubUnio\Services\SupportGuide::class);
        foreach(['login'=>'Face ID','expense'=>'Expense receipt','profile'=>'App language','tenants'=>'Switch club tenant',
            'chat'=>'Chat pictures','translation'=>'Chat translation','planning'=>'Team planning','teams'=>'My Teams',
            'members'=>'Members','membership'=>'New membership','wallet'=>'Member card Wallet','events'=>'Events',
            'reservations'=>'Bus reservations','documents'=>'Documents','keyboxes'=>'Igloohome keybox','notifications'=>'Inbox notifications',
            'push'=>'Push notifications','diagnostics'=>'Support diagnostics'] as $topic=>$question)$this->assertSame($topic,$guide->topic($question),$question);
    }
}
