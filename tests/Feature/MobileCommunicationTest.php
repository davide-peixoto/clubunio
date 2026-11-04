<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\ChatService;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileCommunicationTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $player;
    private Account $parent;
    private Account $trainer;
    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'clubunio', 'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-mobile.enabled' => true, 'clubunio-mobile.native_planning_enabled' => true, 'clubunio-mobile.ai.enabled' => false, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        DB::purge('clubunio');
        $this->app->instance(\Illuminate\Cache\RateLimiter::class, new \Illuminate\Cache\RateLimiter(new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore)));
        \Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(\Illuminate\Cache\RateLimiter::class);
        $this->db = DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        // Dialect adaptation only: execute the existing MemberAccessService, not a fake policy.
        $this->db->setQueryGrammar(new class($this->db) extends SQLiteGrammar {
            public function wrap($value, $prefixAlias = false) {
                $wrapped = parent::wrap($value, $prefixAlias);
                return preg_replace('/"clubora"\."([^"]+)"\.\*/', '"$1".*', $wrapped);
            }
            public function compileWheres($query) {
                return str_replace("birth_date + interval '18 years' > CAST(? AS date)", "date(birth_date, '+18 years') > date(?)", parent::compileWheres($query));
            }
        });
        Http::preventStrayRequests();
        $this->db->unprepared(<<<'SQL'
CREATE TABLE clubora.tenants (id integer PRIMARY KEY AUTOINCREMENT, slug text UNIQUE, name text, created_at text, updated_at text);
CREATE TABLE clubora.mobile_module_options (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, key text, name text, active boolean DEFAULT 1, sort_order integer DEFAULT 0);
CREATE TABLE clubora.tenant_domains (id integer PRIMARY KEY, tenant_id integer, host text, active boolean, ownership_verified boolean, dns_verified boolean, tls_verified boolean);
CREATE TABLE clubora.accounts (id integer PRIMARY KEY AUTOINCREMENT, username text UNIQUE, password text, display_name text, active boolean DEFAULT 1, api_only boolean DEFAULT 0, created_at text, updated_at text);
CREATE TABLE clubora.tenant_accounts (tenant_id integer, account_id integer, role text, portal_enabled boolean DEFAULT 1, access_profile_id integer, PRIMARY KEY(tenant_id,account_id));
CREATE TABLE clubora.members (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, first_name text, last_name text, birth_date text, exit_date text, archived_at text, status_id integer, UNIQUE(tenant_id,id));
CREATE TABLE clubora.member_statuses (id integer PRIMARY KEY, tenant_id integer, ends_membership boolean);
CREATE TABLE clubora.account_member_links (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, account_id integer, member_id integer, state text, kind text);
CREATE TABLE clubora.seasons (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, legacy_id integer, name text, active boolean DEFAULT 1, starts_on text, ends_on text);
CREATE TABLE clubora.tenant_settings (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, area text, key text, encrypted_value text, enabled boolean DEFAULT 0);
CREATE TABLE clubora.teams (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, name text, active boolean DEFAULT 1);
CREATE TABLE clubora.team_seasons (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_id integer, season_id integer, label text, active boolean DEFAULT 1, UNIQUE(tenant_id,id));
CREATE TABLE clubora.team_players (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, active boolean DEFAULT 1);
CREATE TABLE clubora.team_staff (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, active boolean DEFAULT 1);
CREATE TABLE clubora.technical_leads (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, active boolean DEFAULT 1);
CREATE TABLE clubora.team_events (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, deleted_at text, UNIQUE(tenant_id,id));
CREATE TABLE clubora.invoices (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, member_id integer, active boolean DEFAULT 1, status_id integer, workflow_status_id integer, bexio_invoice_id integer, legacy_id integer, UNIQUE(tenant_id,id));
CREATE TABLE clubora.invoice_workflow_statuses (id integer PRIMARY KEY, tenant_id integer, code text, name text);
CREATE TABLE clubora.invoice_statuses (id integer PRIMARY KEY, tenant_id integer, code text, name text);
CREATE TABLE clubora.bexio_checks (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, object_type text, object_id integer, state text, remote_status text, remote_id text, applied_at text);
CREATE TABLE clubora.bexio_balances (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, object_type text, object_id integer, remote_status text, remote_id text);
SQL);
        foreach (['2026_11_02_000082_mobile_communication.php', '2026_11_02_000083_mobile_chat_controls.php', '2026_11_04_001100_chat_experience.php'] as $migration) {
        $source = file_get_contents(database_path('clubunio/'.$migration));
        preg_match("/<<<'SQL'\n(.*?)\nSQL/s", str_replace("\r\n", "\n", $source), $match);
        $sql = preg_replace('/REVOKE ALL.*?FROM PUBLIC;/s', '', $match[1]);
        $sql = str_replace('id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY', 'id integer PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = str_replace(['timestamptz', 'jsonb', 'uuid', 'DEFAULT now()'], ['text', 'text', 'text', 'DEFAULT CURRENT_TIMESTAMP'], $sql);
        $sql = preg_replace('/REFERENCES clubora\./', 'REFERENCES ', $sql);
        $sql = preg_replace('/CREATE INDEX (\w+) ON clubora\.(\w+)/', 'CREATE INDEX clubora.$1 ON $2', $sql);
        $this->db->unprepared($sql);
        }
        $this->tenant = Tenant::create(['slug' => 'mobile-test', 'name' => 'Mobile fixture']);
        foreach (\App\ClubUnio\Services\MobileModuleSettings::MODULES as $key => $name) {
            $this->db->table('clubora.mobile_module_options')->insert(['tenant_id' => $this->tenant->id, 'key' => $key, 'name' => $name]);
        }
        $this->admin = $this->account('admin');
        $this->player = $this->account('member');
        $this->parent = $this->account('parent');
        $this->trainer = $this->account('trainer');
        $child = $this->member('Child', '2013-04-06');
        $coach = $this->member('Coach', '1985-02-03');
        foreach ([[$this->player, $child, 'self'], [$this->parent, $child, 'parent'], [$this->trainer, $coach, 'self']] as [$actor, $member, $kind]) {
            $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => $kind, 'state' => 'approved']);
        }
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Current fixture',
            'starts_on' => now('Europe/Zurich')->subMonth()->toDateString(), 'ends_on' => now('Europe/Zurich')->addMonths(2)->toDateString()]);
        $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'general', 'key' => 'SaisonID',
            'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString((string) $season)]);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Junioren']);
        $this->team = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Junioren 26/27']);
        $this->db->table('clubora.team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $child]);
        $this->db->table('clubora.team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $coach]);
    }

    private function account(string $role, ?Tenant $tenant = null): Account
    {
        $a = new Account;
        $a->forceFill(['username' => 'fixture-'.strtolower(Str::random(12)), 'display_name' => 'Fixture '.$role, 'password' => bcrypt('fixture-password'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => ($tenant ?? $this->tenant)->id, 'account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);
        return $a;
    }

    private function member(string $name, string $birth): int
    {
        return $this->db->table('clubora.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => $name, 'last_name' => 'Fixture', 'birth_date' => $birth]);
    }

    private function token(Account $actor): string
    {
        return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture-password', 'device_name' => 'Isolated test'])->assertOk()->json('data.token');
    }

    private function url(string $path): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/'.$path; }

    private function teamChat(Account $actor): int
    {
        $this->withToken($this->token($actor))->getJson($this->url('chats'))->assertOk();
        return (int) $this->db->table('clubora.chat_conversations')->where('tenant_id', $this->tenant->id)->where('kind', 'team')->where('team_season_id', $this->team)->value('id');
    }

    public function test_saison_parameter_maps_legacy_id_before_native_collision_without_date_guessing(): void
    {
        $current = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('season_id');
        $this->db->table('clubora.seasons')->where('id', $current)->update(['legacy_id' => 700, 'starts_on' => '2000-01-01', 'ends_on' => '2001-01-01']);
        $this->db->table('clubora.seasons')->insert(['id' => 700, 'tenant_id' => $this->tenant->id, 'name' => 'Native collision']);
        $settings = $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'SaisonID');
        $settings->update(['encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('700')]);
        $this->withToken($this->token($this->admin))->getJson($this->url('teams'))
            ->assertOk()->assertJsonPath('data.teams.0.id', $this->team);
        $this->db->table('clubora.seasons')->where('id', $current)->update(['active' => false]);
        // An inactive imported season cannot be replaced by an unrelated native ID.
        $this->getJson($this->url('teams'))->assertOk()->assertJsonCount(0, 'data.teams');
    }

    private function financeSchema(): void
    {
        foreach (['season_id integer', "tax_mode text DEFAULT 'exclusive'", 'cash_rounding boolean DEFAULT 0'] as $column) {
            $this->db->statement('ALTER TABLE clubora.invoices ADD COLUMN '.$column);
        }
        $this->db->statement('ALTER TABLE clubora.invoice_statuses ADD COLUMN active boolean DEFAULT 1');
        $this->db->statement('ALTER TABLE clubora.teams ADD COLUMN laundry_allowance numeric DEFAULT 25');
        $this->db->unprepared('CREATE TABLE clubora.invoice_lines (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, invoice_id integer, net_amount numeric, active boolean DEFAULT 1);
CREATE TABLE clubora.expenses (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, member_id integer, season_id integer, team_season_id integer, active boolean DEFAULT 1, revision integer DEFAULT 1, bank_account_id integer, status_id integer, round_id integer, notes text);
CREATE TABLE clubora.expense_statuses (id integer PRIMARY KEY, tenant_id integer, code text, name text, active boolean DEFAULT 1);
CREATE TABLE clubora.expense_rounds (id integer PRIMARY KEY, tenant_id integer, code text, name text, active boolean DEFAULT 1);
CREATE TABLE clubora.expense_types (id integer PRIMARY KEY, tenant_id integer, name text, active boolean DEFAULT 1, receipt_required boolean, amount_source text);
CREATE TABLE clubora.game_types (id integer PRIMARY KEY, tenant_id integer, code text, name text, active boolean DEFAULT 1);
CREATE TABLE clubora.banks (id integer PRIMARY KEY, tenant_id integer, name text, active boolean DEFAULT 1);');
    }

    public function test_financial_lists_totals_lookups_and_personal_permissions_use_configured_season(): void
    {
        $this->financeSchema();
        $season = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('season_id');
        $other = $this->member('Other invoice owner', '1980-01-01');
        $child = $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->value('member_id');
        $old = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Other season']);
        $this->db->table('clubora.invoice_statuses')->insert(['id' => 1, 'tenant_id' => $this->tenant->id, 'name' => 'Offen', 'code' => 'O']);
        $ids = [];
        foreach ([[$child, $season, 100.05], [$child, $old, 200], [$other, $season, 300], [$other, null, 400]] as [$member, $selected, $amount]) {
            $id = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'season_id' => $selected, 'status_id' => 1]);
            $this->db->table('clubora.invoice_lines')->insert(['tenant_id' => $this->tenant->id, 'invoice_id' => $id, 'net_amount' => $amount]);
            $this->db->table('clubora.expenses')->insert(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'season_id' => $selected]);
            $ids[] = $id;
        }
        $this->withToken($this->token($this->parent))->getJson($this->url('invoices?limit=1&season_id='.$old.'&all_seasons=1'))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.id', $ids[0])->assertJsonPath('data.rows.0.total_cents', 10005);
        $this->getJson($this->url('invoices/options'))->assertOk()->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.id', $child)->assertJsonPath('data.seasons.0.id', $season);
        $this->getJson($this->url('invoices?member_id='.$other))->assertOk()->assertJsonPath('data.total', 0);
        $this->withToken($this->token($this->admin))->getJson($this->url('invoices?limit=1'))
            ->assertOk()->assertJsonPath('data.total', 2)->assertJsonCount(1, 'data.rows');
        $this->getJson($this->url('expenses?limit=1&season_id='.$old))->assertOk()->assertJsonPath('data.total', 2)->assertJsonCount(1, 'data.rows');
        $expenses = app(\App\ClubUnio\Services\ExpenseService::class)->visible($this->admin, $this->tenant);
        $this->assertSame(2, $expenses->count());
        $this->assertSame(0, (clone $expenses)->where('season_id', $old)->count());
        $this->getJson($this->url('invoices?limit=101'))->assertUnprocessable();
        $this->getJson('/api/mobile/v1/clubs/999999/invoices')->assertNotFound();
        $this->app->instance('request', \Illuminate\Http\Request::create('/invoices'));
        $this->assertSame(4, app(\App\ClubUnio\Services\ExpenseService::class)->visible($this->admin, $this->tenant)->count());
    }

    public function test_expense_options_only_offer_own_staff_teams_in_parameter_season(): void
    {
        $this->financeSchema();
        $coach = $this->db->table('clubora.account_member_links')->where('account_id', $this->trainer->id)->value('member_id');
        $original = $this->db->table('clubora.team_seasons')->where('id', $this->team)->first();
        $old = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Old']);
        $oldTeam = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $original->team_id, 'season_id' => $old, 'label' => 'Old']);
        $this->db->table('clubora.team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $oldTeam, 'member_id' => $coach]);
        $this->withToken($this->token($this->trainer))->getJson($this->url('expenses/options?season_id='.$old))
            ->assertOk()->assertJsonCount(1, 'data.teams')->assertJsonPath('data.teams.0.id', $this->team)
            ->assertJsonPath('data.seasons.0.id', $original->season_id)->assertJsonPath('data.capabilities.create', true);
        $this->withToken($this->token($this->parent))->getJson($this->url('expenses/options'))
            ->assertOk()->assertJsonCount(0, 'data.teams')->assertJsonPath('data.capabilities.create', false);
    }

    public function test_mobile_season_scope_filters_sql_counts_direct_ids_and_chats_for_all_roles(): void
    {
        $original = $this->db->table('clubora.team_seasons')->where('id', $this->team)->first();
        $own = $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->value('member_id');
        $this->db->unprepared('CREATE TABLE clubora.team_invitations (tenant_id integer, event_id integer, member_id integer);');
        $hidden = [];
        foreach (['old', 'future', 'undated', 'inactive', 'foreign'] as $kind) {
            $foreign = $kind === 'foreign' ? Tenant::create(['slug' => 'season-foreign', 'name' => 'Foreign']) : $this->tenant;
            $from = now('Europe/Zurich')->subMonth();
            $to = now('Europe/Zurich')->addMonth();
            if ($kind === 'old') { $from = $from->subYear(); $to = $to->subYear(); }
            if ($kind === 'future') { $from = $from->addYear(); $to = $to->addYear(); }
            $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $foreign->id, 'name' => $kind,
                'active' => $kind !== 'inactive', 'starts_on' => $kind === 'undated' ? null : $from->toDateString(),
                'ends_on' => $kind === 'undated' ? null : $to->toDateString()]);
            $team = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id,
                'team_id' => $original->team_id, 'season_id' => $season, 'label' => $kind]);
            $this->db->table('clubora.team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $team, 'member_id' => $own]);
            $event = $this->db->table('clubora.team_events')->insertGetId(['tenant_id' => $this->tenant->id, 'team_season_id' => $team]);
            $this->db->table('clubora.team_invitations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $event, 'member_id' => $own]);
            $hidden[] = [$team, $event];
        }
        foreach ([$this->parent, $this->player, $this->trainer, $this->admin] as $actor) {
            $this->withToken($this->token($actor))->getJson($this->url('teams'))
                ->assertOk()->assertJsonCount(1, 'data.teams')->assertJsonPath('data.teams.0.id', $this->team);
            $this->getJson($this->url('planning/teams?recperpage=1'))
                ->assertOk()->assertJsonPath('data.totalRecordCount', 1)->assertJsonCount(1, 'data.Teams');
            $this->getJson($this->url('my-teams'))->assertOk()->assertJsonPath('data.totalRecordCount', 1);
            foreach ($hidden as [$team, $event]) {
                $this->getJson($this->url('my-teams/'.$team))->assertNotFound();
                $this->getJson($this->url('events/'.$event))->assertNotFound();
                $this->patchJson($this->url('planning/events/'.$event), ['Revision' => 1, 'Titel' => 'Forbidden'])->assertNotFound();
                $chat = app(ChatService::class)->ensure($this->tenant, 'team', $team);
                $this->getJson($this->url('chats/'.$chat->id.'/messages'))->assertNotFound();
            }
        }
    }

    public function test_mobile_season_parameter_overrides_calendar_and_fails_closed(): void
    {
        $original = $this->db->table('clubora.team_seasons')->where('id', $this->team)->first();
        $this->db->table('clubora.seasons')->where('id', $original->season_id)->update(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $nextSeason = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Next',
            'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);
        $next = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $original->team_id,
            'season_id' => $nextSeason, 'label' => 'Next']);
        $token = $this->token($this->admin);
        try {
            foreach (['2027-06-30 23:59:59', '2027-07-01 00:00:00'] as $time) {
                $this->travelTo(\Carbon\CarbonImmutable::parse($time, 'Europe/Zurich'));
                // Renew fixture session rather than bypassing real expiration checks.
                $token = $this->token($this->admin);
                $this->withToken($token)->getJson($this->url('teams?season_id='.$original->season_id.'&all_seasons=1'))
                    ->assertOk()->assertJsonCount(1, 'data.teams')->assertJsonPath('data.teams.0.id', $this->team);
            }
            $settings = $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'SaisonID');
            $settings->update(['encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString((string) $nextSeason)]);
            $this->getJson($this->url('teams'))->assertOk()->assertJsonPath('data.teams.0.id', $next);
            foreach (['0', 'bad', '999999', ''] as $invalid) {
                $settings->update(['encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString($invalid)]);
                $this->getJson($this->url('teams'))->assertOk()->assertJsonCount(0, 'data.teams');
            }
        } finally { $this->travelBack(); }
    }

    public function test_mobile_shared_queries_filter_before_count_and_cache_season_only_per_request(): void
    {
        $scope = app(\App\ClubUnio\Mobile\MobileSeasonScope::class);
        $planning = app(\App\ClubUnio\Services\TeamPlanningService::class);
        $current = $this->db->table('clubora.seasons')->first();
        $old = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Old',
            'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subYear()->toDateString()]);
        $oldTeam = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => 1, 'season_id' => $old, 'label' => 'Old']);
        foreach ([$this->team, $oldTeam] as $team) {
            $this->db->table('clubora.team_events')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $team]);
        }
        $this->db->unprepared('CREATE TABLE clubora.team_absences (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, starts_on text, ends_on text);');
        foreach ([[$current->starts_on, $current->ends_on], ['2000-01-01', '2000-02-01'], ['2090-01-01', '2090-02-01'],
            ['2000-01-01', $current->starts_on]] as [$from, $to]) {
            $this->db->table('clubora.team_absences')->insert(['tenant_id' => $this->tenant->id, 'starts_on' => $from, 'ends_on' => $to]);
        }
        $this->app->instance('request', \Illuminate\Http\Request::create('/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/teams'));
        request()->attributes->set('mobile.season_scope', true);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $this->assertSame((int) $current->id, (int) $scope->current($this->tenant)->id);
        $scope->current($this->tenant);
        $this->assertCount(2, $this->db->getQueryLog());
        $this->db->disableQueryLog();
        $this->assertSame(1, $planning->table($this->tenant, 'team_events')->count());
        $this->assertSame(1, $planning->table($this->tenant, 'team_events', true)->count());
        $this->assertSame(2, $planning->table($this->tenant, 'team_absences')->count());
        $this->assertSame(1, $planning->table($this->tenant, 'team_seasons')->count());
        // Web services retain historical access; no process-wide singleton/static cache.
        $this->app->instance('request', \Illuminate\Http\Request::create('/planning'));
        $this->assertSame(2, $planning->table($this->tenant, 'team_events')->count());
        $this->assertSame(4, $planning->table($this->tenant, 'team_absences')->count());
    }

    public function test_existing_login_hashes_personal_tokens_and_rejects_technical_accounts(): void
    {
        $token = $this->token($this->parent);
        $this->assertStringNotContainsString($token, $this->db->table('clubora.mobile_sessions')->get()->toJson());
        $this->withToken($token)->getJson($this->url('teams'))->assertOk()->assertJsonPath('data.teams.0.id', $this->team)->assertJsonCount(1, 'data.represented');
        $this->admin->forceFill(['api_only' => true])->save();
        $this->postJson('/api/mobile/v1/session', ['username' => $this->admin->username, 'password' => 'fixture-password', 'device_name' => 'Test'])->assertUnauthorized();
        $this->postJson('/api/mobile/v1/session', ['username' => $this->parent->username, 'password' => 'wrong', 'device_name' => 'Test'])->assertUnauthorized();
    }

    public function test_logout_expiry_and_account_deactivation_apply_to_existing_tokens(): void
    {
        $token = $this->token($this->player);
        $this->withToken($token)->deleteJson('/api/mobile/v1/session')->assertOk();
        $this->withToken($token)->getJson('/api/mobile/v1/context')->assertUnauthorized();
        $token = $this->token($this->player);
        $this->player->forceFill(['active' => false])->save();
        $this->withToken($token)->getJson('/api/mobile/v1/context')->assertUnauthorized();
    }

    public function test_team_chat_is_unique_and_roles_parents_and_staff_are_scoped(): void
    {
        $ids = array_map(fn ($actor) => $this->teamChat($actor), [$this->player, $this->parent, $this->trainer, $this->admin]);
        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, $this->db->table('clubora.chat_conversations')->where('kind', 'team')->count());
        $staff = $this->db->table('clubora.chat_conversations')->where('kind', 'staff')->value('id');
        $this->withToken($this->token($this->parent))->getJson($this->url('chats/'.$staff.'/messages'))->assertNotFound();
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->withToken($this->token($this->admin))->getJson($this->url('chats'))->assertOk();
    }

    public function test_notification_chat_detail_is_independent_of_list_limit_and_rechecks_access(): void
    {
        $id = $this->teamChat($this->parent);
        $service = app(ChatService::class);
        for ($i = 0; $i < 201; $i++) { $service->ensure($this->tenant, 'announcements', key: 'qa-limit-'.$i); }
        $this->withToken($this->token($this->parent));
        $list = $this->getJson($this->url('chats'))->assertOk()->json('data');
        $this->assertNotContains($id, array_column($list, 'id'));
        $this->getJson($this->url('chats/'.$id))->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.kind', 'team');
        $this->getJson($this->url('chats/2147483647'))->assertNotFound();
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'pending']);
        $this->getJson($this->url('chats/'.$id))->assertForbidden();
    }

    public function test_conversation_list_batches_twenty_team_scopes_without_rewriting_existing_memberships(): void
    {
        $s = app(ChatService::class); $s->conversations($this->admin, $this->tenant);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $one = $s->conversations($this->admin, $this->tenant);
        $oneCount = count($this->db->getQueryLog()); $this->db->disableQueryLog();
        $this->assertCount(3, $one);
        $season = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('season_id');
        for ($n = 1; $n < 20; $n++) {
            $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic '.$n]);
            $this->db->table('clubora.team_seasons')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic '.$n]);
        }
        $s->conversations($this->admin, $this->tenant); // Provision each missing scope once, in bounded batches.
        $chat = $this->db->table('clubora.chat_conversations')->where('kind', 'team')->where('team_season_id', $this->team)->value('id');
        $s->send($this->parent, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'New unread fixture']);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $many = $s->conversations($this->admin, $this->tenant);
        $queries = $this->db->getQueryLog(); $this->db->disableQueryLog();
        $this->assertCount(41, $many); $this->assertSame($oneCount, count($queries));
        $this->assertSame(1, collect($many)->firstWhere('id', $chat)['unread']);
        foreach ($many as $dto) $this->assertTrue($dto['can_moderate']);
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query']);
        // A forged prior staff membership must not make a parent a staff participant.
        $staff = $this->db->table('clubora.chat_conversations')->where('kind', 'staff')->where('team_season_id', $this->team)->value('id');
        $this->db->table('clubora.chat_memberships')->insert(['tenant_id' => $this->tenant->id, 'conversation_id' => $staff, 'account_id' => $this->parent->id]);
        $parentList = $s->conversations($this->parent, $this->tenant);
        $this->assertNotContains($staff, array_column($parentList, 'id'));
        $this->assertNotNull($this->db->table('clubora.chat_memberships')->where('conversation_id', $staff)->where('account_id', $this->parent->id)->value('left_at'));
    }

    public function test_conversation_list_still_rechecks_direct_and_event_revocation(): void
    {
        $s = app(ChatService::class); $this->teamChat($this->parent); $this->teamChat($this->trainer);
        $this->db->unprepared('CREATE TABLE clubora.team_invitations (tenant_id integer, event_id integer, member_id integer)');
        $event = $this->db->table('clubora.team_events')->insertGetId(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team]);
        $eventChat = $s->ensure($this->tenant, 'event', event: $event);
        $s->chat($this->parent, $this->tenant, $eventChat->id);
        $direct = $s->direct($this->parent, $this->tenant, $this->trainer->id);
        $before = $s->conversations($this->parent, $this->tenant);
        $this->assertContains($eventChat->id, array_column($before, 'id'));
        $this->assertContains($direct->id, array_column($before, 'id'));
        $this->db->table('clubora.team_events')->where('id', $event)->update(['deleted_at' => now()->toIso8601String()]);
        $this->db->table('clubora.chat_blocks')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->trainer->id, 'other_account_id' => $this->parent->id, 'created_at' => now()->toIso8601String()]);
        $after = $s->conversations($this->parent, $this->tenant);
        $this->assertNotContains($eventChat->id, array_column($after, 'id'));
        $this->assertNotContains($direct->id, array_column($after, 'id'));
        $this->assertContains('team', array_column($after, 'kind'));
    }

    public function test_message_projection_query_count_is_constant_and_attachment_payloads_are_not_selected(): void
    {
        $chat = $this->teamChat($this->parent); $s = app(ChatService::class);
        $poll = $s->send($this->admin, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'Synthetic poll',
            'kind' => 'poll', 'poll' => ['options' => ['A','B'], 'multiple' => true, 'closes_at' => now()->addDay()->toIso8601String()]]);
        $all = collect([$poll]);
        for ($n = 1; $n < 40; $n++) {
            $attributes = (array) $poll; unset($attributes['id']);
            $attributes['client_id'] = (string) Str::uuid();
            $id = $s->table($this->tenant, 'chat_messages')->insertGetId($attributes);
            $all->push($s->table($this->tenant, 'chat_messages')->where('id', $id)->first());
        }
        foreach ($all as $row) {
            $s->table($this->tenant, 'chat_attachments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
                'conversation_id' => $chat, 'account_id' => $this->admin->id, 'client_id' => (string) Str::uuid(), 'message_id' => $row->id,
                'filename_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('Synthetic.pdf'),
                'bytes_encrypted' => str_repeat('private-cipher-marker', 2048), 'sha256' => str_repeat('a',64), 'byte_count' => 100,
                'mime_type' => 'application/pdf', 'state' => 'clean', 'created_at' => now()->toIso8601String()]);
        }
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $one = $s->messageDtos($this->parent, $this->tenant, [$poll]);
        $oneCount = count($this->db->getQueryLog()); $this->db->flushQueryLog();
        $many = $s->messageDtos($this->parent, $this->tenant, $all);
        $queries = $this->db->getQueryLog(); $this->db->disableQueryLog();
        $this->assertSame($oneCount, count($queries)); $this->assertCount(40, $many);
        $this->assertSame($one[(int) $poll->id], $many[(int) $poll->id]);
        $this->assertSame('Synthetic.pdf', $many[(int) $poll->id]['attachment']['name']);
        $this->assertFalse($many[(int) $poll->id]['can_delete']);
        $this->assertSame(['A','B'], array_column($many[(int) $poll->id]['poll']['options'], 'text'));
        $attachmentQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'chat_attachments'));
        $this->assertCount(1, $attachmentQueries);
        foreach ($attachmentQueries as $query) {
            $this->assertStringNotContainsString('bytes_encrypted', $query['query']);
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
        }
        $this->assertTrue($s->messageDto($this->admin, $this->tenant, $poll)['can_delete']);
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        try { $s->messages($this->parent, $this->tenant, $chat); $this->fail('Revoked actor read messages'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $this->assertSame(404, $e->getStatusCode()); }
    }

    public function test_messages_are_encrypted_idempotent_and_replay_conflicts_fail(): void
    {
        $chat = $this->teamChat($this->parent);
        $token = $this->token($this->parent);
        $data = ['client_id' => (string) Str::uuid(), 'text' => 'Private fixture message'];
        $first = $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $data)->assertCreated();
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $data)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $data + ['extra' => 1])->assertCreated();
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), array_replace($data, ['text' => 'Changed']))->assertConflict();
        $this->assertSame(1, $this->db->table('clubora.chat_messages')->count());
        $this->assertStringNotContainsString($data['text'], $this->db->table('clubora.chat_messages')->get()->toJson());
        $this->withToken($token)->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('data.0.text', $data['text']);
    }

    public function test_poll_votes_are_per_account_changeable_and_deadline_enforced(): void
    {
        $chat = $this->teamChat($this->parent);
        $this->teamChat($this->trainer);
        $poll = $this->withToken($this->token($this->trainer))->postJson($this->url('chats/'.$chat.'/messages'),
            ['client_id' => (string) Str::uuid(), 'text' => 'Which time?', 'kind' => 'poll', 'poll' => ['options' => ['Morning', 'Afternoon'], 'multiple' => false, 'closes_at' => now()->addDay()->toIso8601String()]])->assertCreated()->json('data.poll');
        $token = $this->token($this->parent);
        $this->withToken($token)->postJson($this->url('polls/'.$poll['id'].'/vote'), ['choices' => [$poll['options'][0]['id']]])->assertOk()->assertJsonPath('data.voters', 1);
        $this->withToken($token)->postJson($this->url('polls/'.$poll['id'].'/vote'), ['choices' => [$poll['options'][1]['id']]])->assertOk()->assertJsonPath('data.voters', 1);
        $this->withToken($token)->postJson($this->url('polls/'.$poll['id'].'/vote'), ['choices' => array_column($poll['options'], 'id')])->assertUnprocessable();
        $this->db->table('clubora.chat_polls')->where('id', $poll['id'])->update(['closes_at' => now()->subMinute()->toIso8601String()]);
        $this->withToken($token)->postJson($this->url('polls/'.$poll['id'].'/vote'), ['choices' => []])->assertConflict();
    }

    public function test_foreign_club_chat_and_revoked_parent_relationship_are_denied(): void
    {
        $chat = $this->teamChat($this->parent);
        $token = $this->token($this->parent);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = app(ChatService::class)->ensure($other, 'announcements');
        $this->withToken($token)->getJson($this->url('chats/'.$foreign->id.'/messages'))->assertNotFound();
        $this->withToken($token)->getJson('/api/mobile/v1/clubs/'.$other->id.'/chats')->assertForbidden();
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->withToken($token)->getJson($this->url('chats/'.$chat.'/messages'))->assertForbidden();
    }

    public function test_announcement_channel_is_read_only_for_members_and_admin_is_not_private_reader(): void
    {
        $this->teamChat($this->parent);
        $channel = $this->db->table('clubora.chat_conversations')->where('kind', 'announcements')->value('id');
        $data = ['client_id' => (string) Str::uuid(), 'recipient_fingerprint' => app(\App\ClubUnio\Mobile\ChatControls::class)->preview($this->admin, $this->tenant)['fingerprint'], 'text' => 'Club announcement'];
        $this->withToken($this->token($this->parent))->postJson($this->url('chats/'.$channel.'/messages'), $data)->assertForbidden();
        $this->withToken($this->token($this->admin))->postJson($this->url('chats/'.$channel.'/messages'), $data)->assertCreated();
        $direct = $this->withToken($this->token($this->parent))->postJson($this->url('direct'), ['account_id' => $this->trainer->id])->assertOk()->json('data.id');
        $this->withToken($this->token($this->admin))->getJson($this->url('chats/'.$direct.'/messages'))->assertNotFound();
    }

    public function test_unknown_domains_and_mismatched_club_domains_fail_closed(): void
    {
        $token = $this->token($this->parent);
        $this->withToken($token)->getJson('https://unknown.example/api/mobile/v1/context')->assertNotFound();
        config(['clubunio-domains.hosts' => ['other.example' => 'other']]);
        Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $this->withToken($token)->getJson('https://other.example'.$this->url('chats'))->assertNotFound();
    }

    public function test_announcements_reach_each_existing_account_once_including_first_chat_open(): void
    {
        $token = $this->token($this->admin);
        $this->teamChat($this->admin);
        $chat = (int) $this->db->table('clubora.chat_conversations')->where('kind', 'announcements')->value('id');
        $data = ['client_id' => (string) Str::uuid(), 'recipient_fingerprint' => app(\App\ClubUnio\Mobile\ChatControls::class)->preview($this->admin, $this->tenant)['fingerprint'], 'text' => 'Important club notice'];
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $data)->assertCreated();
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $data)->assertCreated();
        $this->assertSame(4, $this->db->table('clubora.mobile_inbox')->count());
        $this->withToken($this->token($this->parent))->getJson($this->url('inbox'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('data.0.text', $data['text']);
    }

    public function test_new_team_season_renaming_and_provisioning_are_unique(): void
    {
        $s = app(ChatService::class);
        $s->provisionTeam($this->tenant, $this->team);
        $s->provisionTeam($this->tenant, $this->team);
        $this->assertSame(1, $this->db->table('clubora.chat_conversations')->where('kind', 'team')->count());
        $this->db->table('clubora.team_seasons')->where('id', $this->team)->update(['label' => 'Renamed']);
        $s->provisionTeam($this->tenant, $this->team);
        $original = $this->db->table('clubora.team_seasons')->where('id', $this->team)->first();
        $other = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $original->team_id, 'season_id' => $original->season_id, 'label' => 'Separate season relation']);
        $s->provisionTeam($this->tenant, $other);
        $this->assertSame(2, $this->db->table('clubora.chat_conversations')->where('kind', 'team')->count());
        $this->assertSame(0, $this->db->table('clubora.chat_messages')->count());
    }

    public function test_invoice_notification_requires_confirmed_bexio_open_or_sent_and_is_once_per_parent(): void
    {
        $this->mock(\App\ClubUnio\Services\BexioReconciliationService::class)->shouldReceive('sandbox')->andReturn(false);
        $this->db->table('clubora.invoice_workflow_statuses')->insert(['id' => 1, 'tenant_id' => $this->tenant->id, 'code' => 'released', 'name' => 'Released']);
        $member = $this->db->table('clubora.account_member_links')->where('account_id', $this->player->id)->value('member_id');
        $invoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $member, 'workflow_status_id' => 1, 'bexio_invoice_id' => 123]);
        $check = $this->db->table('clubora.bexio_checks')->insertGetId(['tenant_id' => $this->tenant->id, 'object_type' => 'invoice', 'object_id' => $invoice,
            'state' => 'applied', 'remote_status' => '7', 'remote_id' => '123', 'applied_at' => now()->toIso8601String()]);
        $balance = $this->db->table('clubora.bexio_balances')->insertGetId(['tenant_id' => $this->tenant->id, 'object_type' => 'invoice', 'object_id' => $invoice, 'remote_status' => '7', 'remote_id' => '123']);
        $service = app(\App\ClubUnio\Mobile\InvoiceNotifications::class);
        $service->confirmed($this->tenant, $check);
        $this->assertSame(0, $this->db->table('clubora.mobile_inbox')->count());
        $this->db->table('clubora.bexio_checks')->where('id', $check)->update(['remote_status' => '8']);
        $service->confirmed($this->tenant, $check); // local/provider-balance disagreement
        $this->assertSame(0, $this->db->table('clubora.mobile_inbox')->count());
        $this->db->table('clubora.bexio_balances')->where('id', $balance)->update(['remote_status' => '8']);
        $service->confirmed($this->tenant, $check);
        $service->confirmed($this->tenant, $check);
        $this->assertSame(2, $this->db->table('clubora.mobile_inbox')->count());
        $this->assertSame(1, $this->db->table('clubora.mobile_invoice_events')->count());
        $ids = $this->db->table('clubora.mobile_inbox')->pluck('account_id')->sort()->values()->all();
        $expected = [$this->parent->id, $this->player->id]; sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_translation_requires_own_tenant_opt_in_target_and_each_request_consent(): void
    {
        config(['clubunio-mobile.ai.enabled' => true]);
        Http::fake();
        $token = $this->token($this->parent);
        $this->withToken($token)->getJson($this->url('chat-preferences'))->assertOk()
            ->assertJsonPath('data.translation_enabled', false)->assertJsonPath('data.revision', 0);
        $input = ['request_key' => (string) Str::uuid(), 'operation' => 'translate', 'text' => 'Hallo', 'language' => 'fr', 'consent' => true];
        $this->postJson($this->url('ai'), $input)->assertForbidden();
        $preference = ['revision' => 0, 'translation_enabled' => true, 'target_language' => 'fr'];
        $this->postJson($this->url('chat-preferences'), $preference)->assertOk()->assertJsonPath('data.revision', 1);
        $this->postJson($this->url('chat-preferences'), $preference)->assertConflict();
        $this->postJson($this->url('ai'), array_replace($input, ['language' => 'de']))->assertConflict();
        $this->postJson($this->url('ai'), array_replace($input, ['consent' => false]))->assertUnprocessable();
        $this->withToken($this->token($this->player))->getJson($this->url('chat-preferences'))->assertOk()->assertJsonPath('data.translation_enabled', false);
        $this->withToken($token)->postJson($this->url('chat-preferences'), array_replace($preference, ['revision' => 1, 'translation_enabled' => false]))->assertOk();
        $this->postJson($this->url('ai'), $input)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_reactions_are_private_scoped_idempotent_and_sent_through_change_feed(): void
    {
        $chat = $this->teamChat($this->parent);
        $this->teamChat($this->player);
        $parentToken = $this->token($this->parent);
        $message = $this->withToken($parentToken)->postJson($this->url('chats/'.$chat.'/messages'), ['client_id' => (string) Str::uuid(), 'text' => 'Training'])->assertCreated()->json('data');
        $url = $this->url('messages/'.$message['id'].'/reaction');
        $this->postJson($url, ['revision' => 1, 'emoji' => '👍'])->assertOk()->assertJsonPath('data.reactions.0.count', 1)->assertJsonPath('data.reactions.0.mine', true)->assertJsonPath('data.revision', 1);
        $cursor = $this->db->table('clubora.chat_changes')->max('id');
        $this->postJson($url, ['revision' => 1, 'emoji' => '👍'])->assertOk();
        $this->assertSame($cursor, $this->db->table('clubora.chat_changes')->max('id'));
        $this->withToken($this->token($this->player))->postJson($url, ['revision' => 1, 'emoji' => '👍'])->assertOk()->assertJsonPath('data.reactions.0.count', 2);
        $this->withToken($parentToken)->getJson($this->url('chats/'.$chat.'/changes?after='.$cursor))->assertOk()->assertJsonPath('data.0.message.reactions.0.count', 2);
        $this->postJson($url, ['revision' => 1, 'emoji' => null])->assertOk()->assertJsonPath('data.reactions.0.mine', false);
        $this->postJson($url, ['revision' => 2, 'emoji' => '👍'])->assertConflict();
        $this->postJson($url, ['revision' => 1, 'emoji' => 'arbitrary'])->assertUnprocessable();
        $outsider = $this->account('member');
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $outsider->id,
            'member_id' => $this->member('Outside reaction', '2000-01-01'), 'kind' => 'self', 'state' => 'approved']);
        $this->withToken($this->token($outsider))->postJson($url, ['revision' => 1, 'emoji' => '👍'])->assertNotFound();
        $this->withToken($parentToken)->postJson($this->url('messages/'.$message['id'].'/delete'), ['revision' => 1])->assertOk()->assertJsonPath('data.reactions', []);
        $this->postJson($url, ['revision' => 2, 'emoji' => '👍'])->assertConflict();
    }

    public function test_translation_rechecks_opt_in_after_waiting_for_tenant_lock(): void
    {
        config(['clubunio-mobile.ai.enabled' => true]);
        Http::fake();
        $token = $this->token($this->parent);
        $this->withToken($token)->postJson($this->url('chat-preferences'), ['revision' => 0, 'translation_enabled' => true, 'target_language' => 'fr'])->assertOk();
        $readPreference = false; $revoked = false;
        $this->db->listen(function ($query) use (&$readPreference, &$revoked): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'chat_preferences')) $readPreference = true;
            // SQLite omits FOR UPDATE. This hook simulates the competing
            // preference write finishing between first source read and lock.
            if ($readPreference && !$revoked && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"clubora"."tenants"')) {
                $revoked = true;
                $this->db->table('clubora.chat_preferences')->where('account_id', $this->parent->id)->update(['translation_enabled' => false, 'revision' => 2]);
            }
        });
        $this->postJson($this->url('ai'), ['request_key' => (string) Str::uuid(), 'operation' => 'translate', 'text' => 'Hallo', 'language' => 'fr', 'consent' => true])->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertSame(0, $this->db->table('clubora.mobile_ai_work')->count());
        Http::assertNothingSent();
    }

    public function test_cached_translation_rechecks_message_after_cache_selection(): void
    {
        config(['clubunio-mobile.ai.enabled' => true, 'clubunio-mobile.ai.openai.key' => 'synthetic-test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Bonjour']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10]])]);
        $chat = $this->teamChat($this->parent);
        $token = $this->token($this->parent);
        $this->withToken($token)->postJson($this->url('chat-preferences'), ['revision' => 0, 'translation_enabled' => true, 'target_language' => 'fr'])->assertOk();
        $row = $this->postJson($this->url('chats/'.$chat.'/messages'), ['client_id' => (string) Str::uuid(), 'text' => 'Hallo'])->assertCreated()->json('data');
        $input = ['request_key' => (string) Str::uuid(), 'operation' => 'translate', 'language' => 'fr', 'message_id' => $row['id'], 'consent' => true];
        $this->postJson($this->url('ai'), $input)->assertOk();
        $edited = false;
        $this->db->listen(function ($query) use (&$edited, $row): void {
            if (!$edited && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'mobile_ai_work') && str_contains($query->sql, 'fingerprint')) {
                $edited = true;
                app(\App\ClubUnio\Mobile\ChatControls::class)->edit($this->parent, $this->tenant, $row['id'], 1, 'Changed after cache read');
            }
        });
        $this->postJson($this->url('ai'), array_replace($input, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertTrue($edited);
        Http::assertSentCount(1);
    }

    public function test_ai_cache_budget_and_provider_switch_do_not_change_chat_messages(): void
    {
        config(['clubunio-mobile.ai.enabled' => true, 'clubunio-mobile.ai.openai.key' => 'synthetic-test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Olá equipa']]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30]])]);
        $chat = $this->teamChat($this->parent);
        $token = $this->token($this->parent);
        $this->withToken($token)->postJson($this->url('chat-preferences'), ['revision' => 0, 'translation_enabled' => true, 'target_language' => 'pt-PT'])->assertOk();
        $row = $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), ['client_id' => (string) Str::uuid(), 'text' => 'Hallo Mannschaft'])->assertCreated()->json('data');
        $input = ['request_key' => (string) Str::uuid(), 'operation' => 'translate', 'language' => 'pt-PT', 'message_id' => $row['id'], 'consent' => true];
        $this->withToken($token)->postJson($this->url('ai'), $input)->assertOk()->assertJsonPath('data.text', 'Olá equipa')->assertJsonPath('data.cached', false);
        config(['clubunio-mobile.ai.translation_provider' => 'azure_translator']);
        $this->withToken($token)->postJson($this->url('ai'), array_replace($input, ['request_key' => (string) Str::uuid()]))->assertOk()->assertJsonPath('data.cached', true);
        Http::assertSentCount(1);
        $this->assertSame(1, $this->db->table('clubora.chat_messages')->count());
        $this->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('data.0.text', 'Hallo Mannschaft');
        config(['clubunio-mobile.ai.monthly_limit_microusd' => 1]);
        $this->withToken($token)->postJson($this->url('ai'), ['request_key' => (string) Str::uuid(), 'operation' => 'correct', 'language' => 'de', 'text' => 'Draft', 'consent' => true])->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_failed_paid_request_is_not_retried_and_revoked_content_is_not_translated(): void
    {
        config(['clubunio-mobile.ai.enabled' => true, 'clubunio-mobile.ai.openai.key' => 'synthetic-test-key']);
        Http::fake(['api.openai.com/*' => Http::response([], 503)]);
        $token = $this->token($this->parent);
        $input = ['request_key' => (string) Str::uuid(), 'operation' => 'correct', 'language' => 'de', 'text' => 'Synthetic private draft', 'consent' => true];
        $this->withToken($token)->postJson($this->url('ai'), $input)->assertStatus(502);
        $this->withToken($token)->postJson($this->url('ai'), $input)->assertConflict();
        Http::assertSentCount(1);
        $this->assertStringNotContainsString($input['text'], $this->db->table('clubora.mobile_ai_work')->get()->toJson());
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->withToken($token)->postJson($this->url('ai'), array_replace($input, ['request_key' => (string) Str::uuid()]))->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_message_cursor_catches_up_across_more_than_one_page_without_gaps(): void
    {
        $chat = $this->teamChat($this->parent);
        $service = app(ChatService::class);
        for ($i = 0; $i < 105; $i++) {
            $service->send($this->admin, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'Message '.$i]);
        }
        $all = []; $cursor = 0;
        // after=1 is deliberately beyond the first message; initial load is latest-first.
        $first = $service->messages($this->parent, $this->tenant, $chat);
        $this->assertCount(50, $first['data']);
        $this->assertTrue($first['meta']['has_more']);
        $firstId = $service->table($this->tenant, 'chat_messages')->min('id');
        $cursor = $firstId;
        do {
            $page = $service->messages($this->parent, $this->tenant, $chat, $cursor);
            $all = array_merge($all, array_column($page['data'], 'id'));
            $cursor = $page['meta']['last_id'];
        } while ($page['meta']['has_more']);
        $this->assertCount(104, $all);
        $this->assertSame(range($firstId + 1, $firstId + 104), $all);
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->withToken($this->token($this->admin))->getJson($this->url('chats/'.$chat.'/messages'))->assertOk();
        try { $service->messages($this->parent, $this->tenant, $chat); $this->fail('Revoked parent read messages'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $this->assertSame(404, $e->getStatusCode()); }
    }

    public function test_edits_deletes_and_change_feed_enforce_revision_author_and_private_access(): void
    {
        $chat = $this->teamChat($this->parent); $this->teamChat($this->trainer);
        $token = $this->token($this->parent);
        $m = $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), ['client_id' => (string) Str::uuid(), 'text' => 'Original'])->assertCreated()->json('data');
        $cursor = $this->withToken($token)->getJson($this->url('chats/'.$chat.'/messages'))->json('meta.change_cursor');
        $this->withToken($this->token($this->trainer))->postJson($this->url('messages/'.$m['id'].'/edit'), ['revision' => 1, 'text' => 'Changed by trainer'])->assertForbidden();
        $this->withToken($token)->postJson($this->url('messages/'.$m['id'].'/edit'), ['revision' => 1, 'text' => 'Corrected'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($token)->postJson($this->url('messages/'.$m['id'].'/edit'), ['revision' => 1, 'text' => 'Stale'])->assertConflict();
        $this->withToken($token)->getJson($this->url('chats/'.$chat.'/changes?after='.$cursor))->assertOk()->assertJsonPath('data.0.message.text', 'Corrected');
        $this->withToken($this->token($this->trainer))->postJson($this->url('messages/'.$m['id'].'/delete'), ['revision' => 2])->assertOk()->assertJsonPath('data.deleted', true)->assertJsonPath('data.text', '');
        $this->assertStringNotContainsString('Original', $this->db->table('clubora.chat_changes')->get()->toJson());
        $this->withToken($token)->getJson($this->url('chats/'.$chat.'/changes?after='.$cursor))->assertJsonPath('data.0.message.deleted', true);
        $direct = app(ChatService::class)->direct($this->parent, $this->tenant, $this->trainer->id);
        $private = app(ChatService::class)->send($this->parent, $this->tenant, $direct->id, ['client_id' => (string) Str::uuid(), 'text' => 'Private']);
        $this->withToken($this->token($this->admin))->postJson($this->url('messages/'.$private->id.'/delete'), ['revision' => 1])->assertNotFound();
        $this->withToken($token)->postJson($this->url('messages/'.$private->id.'/report'), ['reason' => 'No private moderation grant'])->assertForbidden();
    }

    public function test_deleted_poll_cannot_be_voted_and_old_message_changes_are_not_lost(): void
    {
        $chat = $this->teamChat($this->parent); $this->teamChat($this->trainer);
        $s = app(ChatService::class);
        $m = $s->send($this->trainer, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'Choose', 'kind' => 'poll', 'poll' => ['options' => ['A', 'B'], 'multiple' => false, 'closes_at' => now()->addDay()->toIso8601String()]]);
        $cursor = $s->messages($this->parent, $this->tenant, $chat)['meta']['change_cursor'];
        for ($i=0; $i<55; $i++) { $s->send($this->trainer, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'Later '.$i]); }
        app(\App\ClubUnio\Mobile\ChatControls::class)->edit($this->trainer, $this->tenant, $m->id, 1, null);
        $token = $this->token($this->parent); $found = false; $count = 0;
        do {
            $page = $this->withToken($token)->getJson($this->url('chats/'.$chat.'/changes?after='.$cursor))->assertOk()->json();
            foreach ($page['data'] as $change) { $count++; if ($change['message']['id'] === $m->id) { $found = $change['message']['deleted']; } }
            $cursor = $page['meta']['cursor'];
        } while ($page['meta']['has_more']);
        $this->assertTrue($found); $this->assertSame(56, $count);
        $this->withToken($token)->postJson($this->url('polls/'.$m->object_id.'/vote'), ['choices' => []])->assertNotFound();
        $this->assertSame('', $s->table($this->tenant, 'chat_polls')->value('question'));
    }

    public function test_direct_block_is_bidirectional_but_does_not_remove_team_chat(): void
    {
        $chat = $this->teamChat($this->parent); $this->teamChat($this->trainer);
        $parent = $this->token($this->parent); $trainer = $this->token($this->trainer);
        $direct = $this->withToken($parent)->postJson($this->url('direct'), ['account_id' => $this->trainer->id])->assertOk()->json('data.id');
        $this->withToken($parent)->postJson($this->url('blocks'), ['account_id' => $this->trainer->id, 'blocked' => true])->assertOk();
        $this->withToken($trainer)->getJson($this->url('chats/'.$direct.'/messages'))->assertNotFound();
        $this->withToken($trainer)->postJson($this->url('direct'), ['account_id' => $this->parent->id])->assertNotFound();
        $this->withToken($parent)->getJson($this->url('chats/'.$chat.'/messages'))->assertOk();
        $this->withToken($parent)->getJson($this->url('blocks'))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($parent)->postJson($this->url('blocks'), ['account_id' => $this->trainer->id, 'blocked' => false])->assertOk();
        $this->withToken($trainer)->postJson($this->url('direct'), ['account_id' => $this->parent->id])->assertOk();
    }

    public function test_reports_are_deduplicated_encrypted_and_only_current_group_moderator_can_review(): void
    {
        $chat = $this->teamChat($this->parent); $this->teamChat($this->trainer);
        $m = app(ChatService::class)->send($this->trainer, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'Reported message']);
        $parent = $this->token($this->parent);
        foreach ([1,2] as $repeat) { $this->withToken($parent)->postJson($this->url('messages/'.$m->id.'/report'), ['reason' => 'Synthetic confidential reason'])->assertOk(); }
        $this->assertSame(1, $this->db->table('clubora.chat_reports')->count());
        $this->assertStringNotContainsString('Synthetic confidential reason', $this->db->table('clubora.chat_reports')->get()->toJson());
        $this->withToken($parent)->getJson($this->url('chats/'.$chat.'/reports'))->assertForbidden();
        $token = $this->token($this->trainer);
        $id = $this->withToken($token)->getJson($this->url('chats/'.$chat.'/reports'))->assertOk()->assertJsonPath('data.0.reason', 'Synthetic confidential reason')->json('data.0.id');
        $this->withToken($token)->postJson($this->url('reports/'.$id.'/resolve'))->assertOk();
        $this->withToken($token)->getJson($this->url('chats/'.$chat.'/reports'))->assertJsonCount(0, 'data');
    }

    public function test_announcement_preview_requires_admin_and_rechecks_recipient_changes(): void
    {
        $this->teamChat($this->admin); $token = $this->token($this->admin);
        $channel = $this->db->table('clubora.chat_conversations')->where('kind', 'announcements')->value('id');
        $preview = $this->withToken($token)->getJson($this->url('announcements/preview'))->assertOk()->assertJsonPath('data.count', 4)->json('data');
        $this->withToken($this->token($this->parent))->getJson($this->url('announcements/preview'))->assertForbidden();
        $input = ['client_id' => (string) Str::uuid(), 'text' => 'Reviewed', 'recipient_fingerprint' => $preview['fingerprint']];
        $this->trainer->forceFill(['active' => false])->save();
        $this->withToken($token)->postJson($this->url('chats/'.$channel.'/messages'), $input)->assertConflict();
        $this->assertSame(0, $this->db->table('clubora.mobile_inbox')->count());
        $input['recipient_fingerprint'] = $this->withToken($token)->getJson($this->url('announcements/preview'))->json('data.fingerprint');
        $this->withToken($token)->postJson($this->url('chats/'.$channel.'/messages'), $input)->assertCreated();
        $this->assertSame(3, $this->db->table('clubora.mobile_inbox')->count());
        $id = $this->db->table('clubora.mobile_inbox')->where('account_id', $this->admin->id)->value('id');
        $this->withToken($token)->postJson($this->url('inbox/'.$id.'/read'))->assertOk();
        $this->withToken($token)->getJson($this->url('inbox'))->assertJsonPath('data.0.read', true);
    }

    public function test_attachment_quarantine_clean_publication_download_and_delete_are_private(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $this->mock(\App\ClubUnio\Mobile\MalwareScanner::class)->shouldReceive('scan')->once()->andReturn(['clean' => true, 'version' => 'synthetic-scanner']);
        $chat = $this->teamChat($this->parent); $this->teamChat($this->trainer);
        $token = $this->token($this->parent); $client = (string) Str::uuid();
        $pdf = "%PDF-1.4\nSynthetic test only\n%%EOF";
        $upload = fn () => ['client_id' => $client, 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('invoice.pdf', $pdf)];
        $id = $this->withToken($token)->post($this->url('chats/'.$chat.'/attachments'), $upload(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.state', 'quarantine')->json('data.id');
        $this->withToken($token)->post($this->url('chats/'.$chat.'/attachments'), $upload(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->withToken($token)->get($this->url('attachments/'.$id), ['Accept' => 'application/json'])->assertNotFound();
        $input = ['client_id' => (string) Str::uuid(), 'text' => 'Private attachment', 'attachment_id' => $id];
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $input)->assertConflict();
        $this->withToken($token)->postJson($this->url('attachments/'.$id.'/scan'))->assertOk()->assertJsonPath('data.state', 'clean');
        $m = $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $input)->assertCreated()->assertJsonPath('data.attachment.id', $id)->json('data');
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), $input)->assertCreated()->assertJsonPath('data.id', $m['id']);
        $this->assertStringNotContainsString('Synthetic test only', $this->db->table('clubora.chat_attachments')->get()->toJson());
        $this->withToken($token)->get($this->url('attachments/'.$id))->assertOk()->assertContent($pdf)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->withToken($token)->get($this->url('attachments/'.$id), ['Accept' => 'application/json'])->assertForbidden();
        $this->withToken($this->token($this->trainer))->postJson($this->url('messages/'.$m['id'].'/delete'), ['revision' => 1])->assertOk();
        $this->assertNull($this->db->table('clubora.chat_attachments')->where('id', $id)->value('bytes_encrypted'));
    }

    public function test_infected_upload_is_purged_and_unknown_scanner_verdict_never_releases_file(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $this->mock(\App\ClubUnio\Mobile\MalwareScanner::class)->shouldReceive('scan')->once()->andReturn(['clean' => false, 'version' => 'synthetic-scanner']);
        $chat = $this->teamChat($this->parent); $token = $this->token($this->parent);
        $id = $this->withToken($token)->post($this->url('chats/'.$chat.'/attachments'), ['client_id' => (string) Str::uuid(), 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.4\nSynthetic rejected\n%%EOF")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($this->url('attachments/'.$id.'/scan'))->assertOk()->assertJsonPath('data.state', 'rejected');
        $this->assertNull($this->db->table('clubora.chat_attachments')->where('id', $id)->value('bytes_encrypted'));
        $this->withToken($token)->post($this->url('chats/'.$chat.'/attachments'), ['client_id' => (string) Str::uuid(), 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('fake.pdf', '<html>Not a pdf</html>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertFalse(\App\ClubUnio\Mobile\ClamAvScanner::verdict("stream: Eicar-Test-Signature FOUND\0", 'synthetic')['clean']);
        try { \App\ClubUnio\Mobile\ClamAvScanner::verdict("stream: ERROR\0", 'synthetic'); $this->fail('Unknown verdict was clean'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $this->assertSame(503, $e->getStatusCode()); }
    }

    public function test_language_preference_is_persistent_account_scoped_and_validated(): void
    {
        $parent = $this->token($this->parent);
        $this->withToken($parent)->postJson('/api/mobile/v1/preferences', ['language' => 'pt-PT'])->assertOk();
        $this->withToken($parent)->getJson('/api/mobile/v1/context')->assertOk()->assertJsonPath('data.language', 'pt-PT');
        $this->withToken($parent)->postJson('/api/mobile/v1/preferences', ['language' => 'invalid'])->assertUnprocessable();
        $this->withToken($this->token($this->trainer))->getJson('/api/mobile/v1/context')->assertJsonPath('data.language', 'de');
    }

    public function test_edit_during_translation_drops_paid_result_and_invalidates_cached_content(): void
    {
        config(['clubunio-mobile.ai.enabled' => true, 'clubunio-mobile.ai.openai.key' => 'synthetic-test-key']);
        $chat = $this->teamChat($this->parent);
        app(\App\ClubUnio\Mobile\ChatPreferences::class)->save($this->parent, $this->tenant, ['revision' => 0, 'translation_enabled' => true, 'target_language' => 'en']);
        $m = app(ChatService::class)->send($this->parent, $this->tenant, $chat, ['client_id' => (string) Str::uuid(), 'text' => 'Before edit']);
        Http::fake(function () use ($m) {
            app(\App\ClubUnio\Mobile\ChatControls::class)->edit($this->parent, $this->tenant, $m->id, 1, 'After edit');
            return Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Stale translation']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10]]);
        });
        $this->withToken($this->token($this->parent))->postJson($this->url('ai'), ['request_key' => (string) Str::uuid(), 'operation' => 'translate', 'language' => 'en', 'message_id' => $m->id, 'consent' => true])->assertConflict();
        $this->assertNull($this->db->table('clubora.mobile_ai_work')->value('result_encrypted'));
        $this->assertSame('attention', $this->db->table('clubora.mobile_ai_work')->value('state'));
        Http::assertSentCount(1);
    }

    public function test_attachment_foreign_owner_late_join_cancel_and_disabled_flag_fail_closed(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $this->mock(\App\ClubUnio\Mobile\MalwareScanner::class)->shouldReceive('scan')->once()->andReturn(['clean' => true, 'version' => 'synthetic']);
        $chat = $this->teamChat($this->parent); $token = $this->token($this->parent);
        $id = $this->withToken($token)->post($this->url('chats/'.$chat.'/attachments'), ['client_id' => (string) Str::uuid(), 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('safe.pdf', "%PDF-1.4\nSynthetic\n%%EOF")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $trainer = $this->token($this->trainer);
        $this->withToken($trainer)->postJson($this->url('attachments/'.$id.'/scan'))->assertNotFound();
        $this->withToken($token)->postJson($this->url('attachments/'.$id.'/scan'))->assertOk();
        $this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'), ['client_id' => (string) Str::uuid(), 'text' => 'File', 'attachment_id' => $id])->assertCreated();
        // First team entry occurs after publication; old attachment has no implicit history grant.
        $this->teamChat($this->trainer);
        $this->withToken($trainer)->get($this->url('attachments/'.$id), ['Accept' => 'application/json'])->assertNotFound();
        $this->withToken($token)->deleteJson($this->url('attachments/'.$id))->assertConflict();
        config(['clubunio-mobile.uploads_enabled' => false]);
        $this->withToken($token)->get($this->url('attachments/'.$id), ['Accept' => 'application/json'])->assertStatus(503);
    }

    public function test_unknown_scanner_failure_keeps_upload_in_quarantine_and_cancel_purges_bytes(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $this->mock(\App\ClubUnio\Mobile\MalwareScanner::class)->shouldReceive('scan')->once()->andThrow(new \Symfony\Component\HttpKernel\Exception\HttpException(503));
        $chat = $this->teamChat($this->parent); $token = $this->token($this->parent);
        $id = $this->withToken($token)->post($this->url('chats/'.$chat.'/attachments'), ['client_id' => (string) Str::uuid(), 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('safe.pdf', "%PDF-1.4\nSynthetic\n%%EOF")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson($this->url('attachments/'.$id.'/scan'))->assertStatus(503);
        $this->assertSame('quarantine', $this->db->table('clubora.chat_attachments')->value('state'));
        $this->withToken($token)->deleteJson($this->url('attachments/'.$id))->assertOk();
        $this->assertNull($this->db->table('clubora.chat_attachments')->value('bytes_encrypted'));
        $this->withToken($token)->postJson($this->url('attachments/'.$id.'/scan'))->assertJsonPath('data.state', 'removed');
    }
    public function test_personal_profile_is_own_and_password_change_revokes_all_sessions(): void
    {
        $first = $this->token($this->parent);
        $second = $this->token($this->parent);
        $other = $this->token($this->player);
        $this->withToken($first)->getJson('/api/mobile/v1/profile')->assertOk()
            ->assertJsonPath('data.username', $this->parent->username)->assertJsonMissingPath('data.password');
        $this->withToken($first)->postJson('/api/mobile/v1/password', ['current_password' => 'wrong',
            'password' => 'new-fixture-password', 'password_confirmation' => 'new-fixture-password'])->assertUnprocessable();
        $this->withToken($first)->postJson('/api/mobile/v1/password', ['current_password' => 'fixture-password',
            'password' => 'new-fixture-password', 'password_confirmation' => 'new-fixture-password'])->assertOk()
            ->assertJsonPath('data.reauthentication_required', true);
        foreach ([$first, $second] as $token) {
            $this->withToken($token)->getJson('/api/mobile/v1/profile')->assertUnauthorized();
        }
        $this->withToken($other)->getJson('/api/mobile/v1/profile')->assertOk();
        $this->postJson('/api/mobile/v1/session', ['username' => $this->parent->username,
            'password' => 'fixture-password', 'device_name' => 'Test'])->assertUnauthorized();
        $this->postJson('/api/mobile/v1/session', ['username' => $this->parent->username,
            'password' => 'new-fixture-password', 'device_name' => 'Test'])->assertOk();
    }

    public function test_personal_modules_follow_role_and_tenant_boundary(): void
    {
        $this->withToken($this->token($this->admin))->getJson($this->url('modules'))->assertOk()
            ->assertJsonFragment(['key' => 'Spesen', 'available' => false])
            ->assertJsonFragment(['key' => 'Profil', 'available' => true]);
        $token = $this->token($this->parent);
        $this->withToken($token)->getJson($this->url('modules'))->assertOk()
            ->assertJsonMissing(['key' => 'Spesen'])->assertJsonFragment(['key' => 'Teamplanung', 'available' => true]);
        $foreign = Tenant::create(['slug' => 'other-mobile-fixture', 'name' => 'Foreign']);
        $this->withToken($token)->getJson('/api/mobile/v1/clubs/'.$foreign->id.'/modules')->assertForbidden();
    }

    public function test_unreleased_native_planning_is_closed_without_disabling_existing_personal_routes(): void
    {
        config(['clubunio-mobile.native_planning_enabled' => false]);
        $this->withToken($this->token($this->parent))->getJson($this->url('planning/teams'))->assertStatus(503);
        $this->getJson('/api/mobile/v1/profile')->assertOk();
        $this->getJson($this->url('teams'))->assertOk();
    }

    public function test_planning_page_projection_batches_twenty_events_and_preserves_private_invitation_snapshots(): void
    {
        foreach (['title text','kind text','starts_at text','ends_at text','deadline_at text','mode text','status text','revision integer DEFAULT 1',
            'location text','description text','capacity integer','series_key text'] as $column) $this->db->statement('ALTER TABLE clubora.team_events ADD COLUMN '.$column);
        $this->db->statement('ALTER TABLE clubora.members ADD COLUMN updated_at text');
        $this->db->unprepared('CREATE TABLE clubora.team_invitations (tenant_id integer,event_id integer,member_id integer);
CREATE TABLE clubora.team_participations (id integer PRIMARY KEY AUTOINCREMENT,tenant_id integer,event_id integer,member_id integer,status text,attendance text,comment_encrypted text,revision integer,updated_at text);
CREATE TABLE clubora.team_guests (id integer PRIMARY KEY AUTOINCREMENT,tenant_id integer,event_id integer,first_name text,last_name text,status text,attendance text,comment_encrypted text,revision integer,deleted_at text);
CREATE TABLE clubora.object_changes (id integer PRIMARY KEY AUTOINCREMENT,tenant_id integer,object_type text,object_id integer);');
        $invited = $this->member('Invited outsider', '2010-01-01');
        $own = $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->value('member_id');
        $ids = [];
        for ($n = 0; $n < 20; $n++) {
            $id = $this->db->table('clubora.team_events')->insertGetId(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team,
                'title' => 'Synthetic event '.$n,'kind' => 'TRAINING','mode' => 'ANMELDEN','status' => 'AKTIV',
                'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String(),
                'deadline_at' => now()->addDay()->toIso8601String()]); $ids[] = $id;
            $this->db->table('clubora.team_invitations')->insert(['tenant_id' => $this->tenant->id,'event_id' => $id,'member_id' => $invited]);
            foreach ([$own,$invited] as $member) $this->db->table('clubora.team_participations')->insert(['tenant_id' => $this->tenant->id,
                'event_id' => $id,'member_id' => $member,'status' => 'DABEI','attendance' => 'OFFEN','revision' => 1,
                'comment_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('private-'.$member)]);
            $this->db->table('clubora.team_guests')->insert(['tenant_id' => $this->tenant->id,'event_id' => $id,'first_name' => 'Synthetic',
                'last_name' => 'Guest','status' => 'OFFEN','attendance' => 'OFFEN','revision' => 1]);
        }
        $request = fn ($limit) => \Illuminate\Http\Request::create('/', 'GET', ['recperpage' => $limit]);
        $data = fn () => app(\App\ClubUnio\Mobile\MobilePlanningData::class);
        $data()->events($request(1), $this->admin, $this->tenant); // Schema discovery is not per-row projection work.
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $one = $data()->events($request(1), $this->admin, $this->tenant);
        $oneCount = count($this->db->getQueryLog()); $this->db->flushQueryLog();
        $many = $data()->events($request(20), $this->admin, $this->tenant);
        $manyCount = count($this->db->getQueryLog()); $this->db->flushQueryLog();
        $individualData = $data();
        foreach ($ids as $id) $individualData->event($this->admin, $this->tenant, $id, false);
        $individualCount = count($this->db->getQueryLog()); $this->db->disableQueryLog();
        $this->assertCount(20, $many['TeamTermine']); $this->assertSame($oneCount, $manyCount);
        $this->assertGreaterThan($manyCount * 3, $individualCount, 'The previous per-event projection must use over three times as many SQL queries.');
        $this->assertSame($one['TeamTermine'][0]['TeamTerminID'], $many['TeamTermine'][0]['TeamTerminID']);
        $detail = $data()->event($this->admin, $this->tenant, $ids[0], false);
        $list = $many['TeamTermine'][0];
        foreach (['Teilnahmen','MeineTeilnahmen','NichtAngemeldet'] as $key) {
            foreach ($list[$key] ?? [] as $index => $person) {
                if (!empty($person['InvitationSnapshot'])) {
                    app(\App\ClubUnio\Mobile\MobilePlanningInvitationsData::class)->verify($this->admin, $this->tenant,
                        app(\App\ClubUnio\Services\TeamPlanningService::class)->event($this->admin, $this->tenant, $ids[0]),
                        $person['MitgliederID'], $person['InvitationSnapshot'], 1);
                }
                unset($list[$key][$index]['InvitationSnapshot'], $detail[$key][$index]['InvitationSnapshot']);
            }
        }
        $this->assertSame($detail, $list);
        $parent = $data()->events($request(20), $this->parent, $this->tenant);
        $this->assertStringContainsString('private-'.$own, json_encode($parent));
        $this->assertStringNotContainsString('private-'.$invited, json_encode($parent));
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        try { $data()->events($request(20), $this->parent, $this->tenant); $this->fail('Revoked actor read planning page'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $this->assertSame(403, $e->getStatusCode()); }
    }

    public function test_planning_team_options_batch_roster_links_for_one_and_twenty_teams(): void
    {
        $request = \Illuminate\Http\Request::create('/', 'GET', ['recperpage' => 100]);
        $read = fn () => app(\App\ClubUnio\Mobile\MobilePlanningData::class)->teams($request, $this->admin, $this->tenant);
        $read(); $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $one = $read(); $oneCount = count($this->db->getQueryLog()); $this->db->disableQueryLog();
        $season = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('season_id');
        for ($n = 1; $n < 20; $n++) {
            $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->tenant->id,'name' => 'Synthetic team '.$n]);
            $this->db->table('clubora.team_seasons')->insert(['tenant_id' => $this->tenant->id,'team_id' => $team,'season_id' => $season,'label' => 'Synthetic '.$n]);
        }
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $many = $read(); $manyCount = count($this->db->getQueryLog()); $this->db->disableQueryLog();
        $this->assertCount(1, $one['Teams']); $this->assertCount(20, $many['Teams']);
        $this->assertSame($oneCount, $manyCount); $this->assertSame($one['Teams'][0], $many['Teams'][0]);
    }

    public function test_native_planning_dtos_preserve_visibility_and_private_comments(): void
    {
        foreach (['title text', 'kind text', 'starts_at text', 'ends_at text', 'deadline_at text', 'mode text',
            'status text', 'revision integer DEFAULT 1', 'location text', 'description text', 'capacity integer', 'series_key text'] as $column) {
            $this->db->statement('ALTER TABLE clubora.team_events ADD COLUMN '.$column);
        }
        $this->db->unprepared('CREATE TABLE clubora.team_invitations (tenant_id integer, event_id integer, member_id integer);
CREATE TABLE clubora.team_participations (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, event_id integer, member_id integer, status text, attendance text, comment_encrypted text, revision integer, updated_at text);
CREATE TABLE clubora.team_guests (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, event_id integer, first_name text, last_name text, status text, attendance text, comment_encrypted text, revision integer, deleted_at text);');
        $own = $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->value('member_id');
        $other = $this->member('Other player', '2012-01-01');
        $this->db->table('clubora.team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $other]);
        $id = $this->db->table('clubora.team_events')->insertGetId(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team,
            'title' => 'Native DTO fixture', 'kind' => 'TRAINING', 'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addHour()->toIso8601String(), 'deadline_at' => now()->addDay()->toIso8601String(),
            'mode' => 'ABMELDEN', 'status' => 'AKTIV']);
        foreach ([[$own, 'own-private-comment'], [$other, 'other-private-comment']] as [$member, $comment]) {
            $this->db->table('clubora.team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $id,
                'member_id' => $member, 'status' => 'DABEI', 'attendance' => 'OFFEN', 'revision' => 1,
                'comment_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($comment)]);
        }
        $this->withToken($this->token($this->parent))->getJson($this->url('planning/teams'))
            ->assertOk()->assertJsonPath('data.Teams.0.TeamSaisonID', $this->team)->assertJsonPath('data.Teams.0.CanManage', false);
        $response = $this->getJson($this->url('planning/events/'.$id))->assertOk()
            ->assertJsonPath('data.TeamTermin.TeamTerminID', $id)->assertJsonPath('data.TeamTermin.CanManage', false);
        $this->assertStringContainsString('own-private-comment', $response->getContent());
        $this->assertStringNotContainsString('other-private-comment', $response->getContent());
        $this->getJson($this->url('planning/events?recperpage=1'))->assertOk()->assertJsonPath('data.totalRecordCount', 1);
        $this->getJson($this->url('planning/events?recperpage=101'))->assertUnprocessable();
        $otherTeam = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => 999, 'season_id' => 1, 'label' => 'Other team']);
        $otherEvent = $this->db->table('clubora.team_events')->insertGetId(['tenant_id' => $this->tenant->id, 'team_season_id' => $otherTeam,
            'title' => 'Forbidden native DTO', 'kind' => 'TRAINING', 'starts_at' => now()->addDays(3)->toIso8601String(),
            'ends_at' => now()->addDays(3)->addHour()->toIso8601String(), 'mode' => 'ANMELDEN', 'status' => 'AKTIV']);
        $this->getJson($this->url('planning/events/'.$otherEvent))->assertNotFound();
        $this->getJson($this->url('planning/events?TeamSaisonID='.$otherTeam))->assertOk()->assertJsonPath('data.totalRecordCount', 0);

        $this->withToken($this->token($this->admin))->getJson($this->url('planning/events/'.$id))
            ->assertOk()->assertJsonPath('data.TeamTermin.CanManage', true);
    }

    public function test_personal_absence_http_preserves_partial_day_and_restores_real_participation(): void
    {
        foreach (['starts_at text', 'ends_at text', 'deadline_at text', 'mode text', 'status text'] as $column) {
            $this->db->statement('ALTER TABLE clubora.team_events ADD COLUMN '.$column);
        }
        $this->db->unprepared('CREATE TABLE clubora.team_invitations (tenant_id integer, event_id integer, member_id integer);
CREATE TABLE clubora.team_absences (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, member_id integer, starts_on text, ends_on text, starts_at text, ends_at text, kind text, note_encrypted text, revision integer, deleted_at text, updated_at text);
CREATE TABLE clubora.team_participations (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, event_id integer, member_id integer, source text, status text, attendance text, comment_encrypted text, original_status text, original_comment_encrypted text, revision integer, updated_at text);
CREATE TABLE clubora.planning_options (tenant_id integer, category text, code text, active boolean);
CREATE TABLE clubora.object_changes (tenant_id integer, actor_id integer, object_type text, object_id integer, before_value text, after_value text);');
        $this->db->table('clubora.planning_options')->insert(['tenant_id' => $this->tenant->id, 'category' => 'absence', 'code' => 'ARBEIT', 'active' => true]);
        $member = $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->value('member_id');
        $start = \Carbon\CarbonImmutable::now('Europe/Zurich')->addDays(4)->setTime(18, 0);
        $events = [];
        foreach ([[$start, $start->addHour(), false], [$start->setTime(10, 0), $start->setTime(11, 0), false], [$start, $start->addHour(), true]] as [$from, $to, $locked]) {
            $events[] = $this->db->table('clubora.team_events')->insertGetId(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team,
                'starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(), 'mode' => 'ANMELDEN', 'status' => 'AKTIV',
                'deadline_at' => ($locked ? $start->subDays(5) : $start->subDay())->toIso8601String()]);
        }
        $this->db->table('clubora.team_participations')->insert(['tenant_id' => $this->tenant->id, 'event_id' => $events[0], 'member_id' => $member,
            'source' => 'manual', 'status' => 'DABEI', 'revision' => 1, 'comment_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('PRIVATE-PRIOR')]);
        $parent = $this->token($this->parent);
        $input = ['MitgliederID' => $member, 'DatumVon' => $start->subMinutes(15)->toIso8601String(), 'DatumBis' => $start->addMinutes(30)->toIso8601String(),
            'Absenzart' => 'ARBEIT', 'Bemerkung' => 'PRIVATE-ABSENCE'];
        $url = $this->url('planning/absences');
        $r = $this->withToken($parent)->postJson($url, $input)->assertOk()->assertJsonPath('data.sync.autoApplied', 1)
            ->assertJsonPath('data.sync.autoRestored', 0)->assertJsonPath('data.sync.skippedLocked', 1);
        $id = $r->json('data.Absenz.AbsenzID');
        $this->getJson($url)->assertOk()->assertJsonPath('data.TeamAbsenzen.0.Revision', 1)->assertJsonPath('data.TeamAbsenzen.0.CanDelete', true);
        $this->assertSame(1, $this->db->table('clubora.team_participations')->count());
        $this->patchJson($url.'/'.$id, $input + ['Revision' => 2])->assertUnprocessable();
        $this->withToken($this->token($this->trainer))->getJson($url)->assertOk()
            ->assertJsonPath('data.TeamAbsenzen.0.CanEdit', true)->assertJsonPath('data.TeamAbsenzen.0.CanDelete', false);
        $this->deleteJson($url.'/'.$id, ['Revision' => 1])->assertForbidden();
        $outsideAccount = $this->account('member');
        $outsideMember = $this->member('Outside', '2012-01-01');
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $outsideAccount->id,
            'member_id' => $outsideMember, 'kind' => 'parent', 'state' => 'approved']);
        $outsider = $this->token($outsideAccount);
        $this->withToken($outsider)->getJson($url.'?MitgliederID='.$member)->assertOk()->assertJsonPath('data.TeamAbsenzen', []);
        $this->deleteJson($url.'/'.$id, ['Revision' => 1])->assertNotFound();
        $this->withToken($parent)->deleteJson($url.'/'.$id, ['Revision' => 1])->assertOk()->assertJsonPath('data.sync.autoRestored', 1);
        $part = $this->db->table('clubora.team_participations')->where('event_id', $events[0])->first();
        $this->assertSame('DABEI', $part->status);
        $this->assertSame('PRIVATE-PRIOR', \Illuminate\Support\Facades\Crypt::decryptString($part->comment_encrypted));
        $this->assertStringNotContainsString('PRIVATE', $this->db->table('clubora.object_changes')->get()->toJson());
    }

}
