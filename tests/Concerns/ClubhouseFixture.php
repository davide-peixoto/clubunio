<?php

namespace Tests\Concerns;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait ClubhouseFixture
{
    protected Account $admin;

    protected Account $trainer;

    protected Account $reader;

    protected Tenant $club;

    protected Tenant $otherClub;

    protected function clubhouseFixture(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $statements = [
            'CREATE TABLE clubora.tenants(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,default_locale TEXT)',
            'CREATE TABLE clubora.accounts(id INTEGER PRIMARY KEY,username TEXT,display_name TEXT,active INTEGER,api_only INTEGER,password TEXT,revision INTEGER,preferred_locale TEXT)',
            'CREATE TABLE clubora.tenant_accounts(id INTEGER PRIMARY KEY,tenant_id INTEGER,account_id INTEGER,role TEXT,portal_enabled INTEGER,access_profile_id INTEGER,revision INTEGER)',
            'CREATE TABLE clubora.clubhouse_settings(tenant_id INTEGER PRIMARY KEY,public_enabled INTEGER DEFAULT 0,timezone TEXT DEFAULT "Europe/Zurich",revision INTEGER DEFAULT 1)',
            'CREATE TABLE clubora.clubhouse_grants(tenant_id INTEGER,account_id INTEGER,hours_write INTEGER,menus_write INTEGER,menus_publish INTEGER,active INTEGER,revision INTEGER,PRIMARY KEY(tenant_id,account_id))',
            'CREATE TABLE clubora.clubhouse_opening_rules(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,title TEXT,starts_on TEXT,ends_on TEXT,weekdays TEXT,intervals TEXT,active INTEGER,revision INTEGER,created_at TEXT,updated_at TEXT)',
            'CREATE TABLE clubora.clubhouse_opening_exceptions(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,rule_id INTEGER,date TEXT,closed INTEGER,intervals TEXT,note TEXT,revision INTEGER)',
            'CREATE TABLE clubora.clubhouse_menu_statuses(tenant_id INTEGER,code TEXT,label_key TEXT,color TEXT,sort_order INTEGER,PRIMARY KEY(tenant_id,code))',
            'CREATE TABLE clubora.clubhouse_menus(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,status TEXT,title_de TEXT,title_en TEXT,body_de TEXT,body_en TEXT,valid_from TEXT,valid_until TEXT,channels TEXT,document TEXT,published_snapshot TEXT,published_at TEXT,revision INTEGER,created_at TEXT,updated_at TEXT)',
            'CREATE TABLE clubora.clubhouse_events(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,actor_id INTEGER,entity TEXT,object_id INTEGER,operation TEXT,data TEXT,created_at TEXT)',
        ];
        foreach ($statements as $sql) {
            $db->statement($sql);
        }
        $db->table('clubora.tenants')->insert([
            ['id' => 71, 'name' => 'Synthetic Club', 'slug' => 'synthetic-club', 'default_locale' => 'de'],
            ['id' => 72, 'name' => 'Other Club', 'slug' => 'other-club', 'default_locale' => 'en'],
        ]);
        foreach (['admin', 'trainer', 'member'] as $offset => $role) {
            $id = $offset + 1;
            $db->table('clubora.accounts')->insert(['id' => $id, 'username' => 'synthetic-'.$id.'@example.invalid',
                'display_name' => 'Synthetic '.$role, 'active' => true, 'api_only' => false, 'password' => 'not-a-real-password', 'revision' => 1]);
            $db->table('clubora.tenant_accounts')->insert(['id' => $id, 'tenant_id' => 71, 'account_id' => $id, 'role' => $role, 'portal_enabled' => true, 'revision' => 1]);
        }
        $db->table('clubora.clubhouse_settings')->insert(['tenant_id' => 71, 'public_enabled' => false, 'timezone' => 'Europe/Zurich', 'revision' => 1]);
        $this->admin = Account::findOrFail(1);
        $this->trainer = Account::findOrFail(2);
        $this->reader = Account::findOrFail(3);
        $this->club = Tenant::findOrFail(71);
        $this->otherClub = Tenant::findOrFail(72);
        $reads = $this->mock(BackofficeReadContext::class);
        $reads->shouldReceive('tenantsFor')->andReturnUsing(fn ($actor) => $actor->id === 1 ? collect([$this->club]) : null);
        $reads->shouldReceive('permissionCache')->andReturn([]);
        $reads->shouldReceive('withoutSnapshot')->andReturnUsing(function ($callback) {
            if (! request()->hasSession()) {
                request()->setLaravelSession(app('session')->driver());
            }

            return $callback();
        });
        $reads->shouldReceive('duringRender')->andReturnUsing(fn ($callback) => $callback());
        $this->mock(MemberAccessService::class)->shouldReceive('canEnter')->andReturnUsing(fn ($a, $t) => (int) $t->id === 71 && $a->active);
        $this->mock(TenantDomainService::class)->shouldReceive('resolve')->andReturn(null);
        Date::setTestNow('2026-11-24 11:00:00 Europe/Zurich');
        Http::preventStrayRequests();
    }

    protected function opening(array $replace = []): array
    {
        return array_replace(['title' => 'Synthetic evening', 'starts_on' => '2026-11-23', 'ends_on' => '2026-12-31',
            'weekdays' => [2, 4], 'intervals' => [['start' => '18:00', 'end' => '22:00', 'overnight' => false]], 'active' => true], $replace);
    }

    protected function menu(array $replace = []): array
    {
        return array_replace(['title_de' => 'Testmenü', 'title_en' => 'Test menu', 'body_de' => "Suppe\nCHF 8.50", 'body_en' => null,
            'valid_from' => '2026-11-24', 'valid_until' => '2026-11-30', 'channels' => ['web', 'app']], $replace);
    }
}
