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
        // These legacy communication fixtures deliberately exercise all five implemented locales.
        config(['clubunio-languages.enabled'=>['de'=>'Deutsch','en'=>'English','fr'=>'Français','it'=>'Italiano','pt'=>'Português'], 'clubunio-languages.default'=>'de']);
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
                return str_replace(["birth_date + interval '18 years' > CAST(? AS date)",'source.id::text'], ["date(birth_date, '+18 years') > date(?)",'CAST(source.id AS text)'], parent::compileWheres($query));
            }
            protected function compileLock(\Illuminate\Database\Query\Builder $query, $value) {
                return '/* '.(new \Illuminate\Database\Query\Grammars\PostgresGrammar($query->getConnection()))->compileSelect(
                    $query->getConnection()->query()->selectRaw('1')->lock($value)).' */';
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
CREATE TABLE clubora.staff_functions (id integer PRIMARY KEY, tenant_id integer, code text, name text, active boolean DEFAULT 1);
CREATE TABLE clubora.team_staff (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, staff_function_id integer, active boolean DEFAULT 1);
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
        $this->db->statement('ALTER TABLE clubora.chat_messages ADD COLUMN reply_to_message_id integer');
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
        $this->db->table('clubora.staff_functions')->insert(['id' => 1, 'tenant_id' => $this->tenant->id, 'code' => 'TRAINER', 'name' => 'Trainer']);
        $this->db->table('clubora.team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'member_id' => $coach, 'staff_function_id' => 1]);
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

    public function test_session_list_marks_only_current_own_session(): void
    {
        $first = $this->token($this->parent);
        $second = $this->token($this->parent);
        $this->token($this->trainer);
        $rows = $this->withToken($second)->getJson('/api/mobile/v1/sessions')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $current = array_values(array_filter($rows, fn ($row) => $row['current'] === true));
        $this->assertCount(1, $current);
        $this->assertSame(explode('.', $second)[0], $current[0]['id']);
        $other = array_values(array_filter($rows, fn ($row) => !$row['current']));
        $this->assertSame(explode('.', $first)[0], $other[0]['id']);
        $this->withToken($second)->deleteJson('/api/mobile/v1/sessions/'.$other[0]['id'])->assertOk();
        $this->withToken($second)->getJson('/api/mobile/v1/context')->assertOk();
        $this->withToken($first)->getJson('/api/mobile/v1/context')->assertUnauthorized();
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

    public function test_chat_gets_use_shared_tenant_and_detail_chat_lock_and_discard_prelock_role_facts(): void
    {
        $chat=$this->teamChat($this->parent); $token=$this->token($this->parent);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $this->withToken($token)->getJson($this->url('chats/'.$chat))->assertOk();
        $queries=$this->db->getQueryLog(); $this->db->disableQueryLog();
        $this->assertTrue(collect($queries)->contains(fn($q)=>str_contains($q['query'],'from "clubora"."tenants"')&&str_contains($q['query'],'for share')));
        $this->assertTrue(collect($queries)->contains(fn($q)=>str_contains($q['query'],'from "clubora"."chat_conversations"')&&str_contains($q['query'],'for update')));
        $this->assertFalse(collect($queries)->contains(fn($q)=>str_contains($q['query'],'from "clubora"."tenants"')&&str_contains($q['query'],'for update')));
        $left=now()->toIso8601String();
        $membership=$this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id);
        $membership->update(['left_at'=>$left]);
        $changed=false;
        $this->db->listen(function($query)use(&$changed){
            if($changed || !str_contains($query->sql,'from "clubora"."tenants"') || !str_contains($query->sql,'for share')) return;
            $changed=true;
            // Simulates a revocation committed while the request waited for its tenant lock.
            $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
        });
        $this->getJson($this->url('chats'))->assertForbidden(); $this->assertTrue($changed);
        $this->assertSame($left,$membership->value('left_at'),'A revoked requester must not be silently rejoined');
        $this->assertFalse(request()->attributes->has(\App\ClubUnio\Mobile\MobileReadContext::class));
    }

    public function test_list_conditional_upsert_never_overwrites_a_concurrent_active_join_or_read_cursor(): void
    {
        $chat=$this->teamChat($this->parent); $service=app(ChatService::class);
        $membership=$this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id);
        $membership->update(['left_at'=>now()->toIso8601String()]); $joined=false;
        $this->db->listen(function($query)use(&$joined,$membership){
            if($joined || !str_contains($query->sql,'max(id) as latest')) return;
            $joined=true;
            // A separate request completes its active join/read before this list's upsert.
            $membership->update(['left_at'=>null,'joined_after'=>7,'read_message_id'=>9,'muted'=>true]);
        });
        $this->withToken($this->token($this->parent))->getJson($this->url('chats'))->assertOk();
        $this->assertTrue($joined); $fresh=$membership->first();
        $this->assertNull($fresh->left_at); $this->assertSame(7,(int)$fresh->joined_after);
        $this->assertSame(9,(int)$fresh->read_message_id); $this->assertTrue((bool)$fresh->muted);
    }

    public function test_direct_chat_titles_are_batched_after_fresh_contact_authorization(): void
    {
        $s=app(ChatService::class); $ids=[];
        foreach([$this->parent,$this->player] as $other) $ids[$s->direct($this->trainer,$this->tenant,$other->id)->id]=$other;
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $rows=collect($s->conversations($this->trainer,$this->tenant))->keyBy('id'); $queries=$this->db->getQueryLog();
        $this->db->disableQueryLog();
        foreach($ids as $id=>$other) { $this->assertSame($other->display_name,$rows[$id]['title']); $this->assertSame($other->id,$rows[$id]['other_account_id']); }
        $titles=array_filter($queries,fn($q)=>str_contains($q['query'],'other_account')&&str_contains($q['query'],'display_name'));
        $this->assertCount(1,$titles);
        $this->assertCount(0,array_filter($queries,fn($q)=>str_starts_with($q['query'],'select "display_name" from "clubora"."accounts"')));
        $blocked=array_search($this->parent,$ids,true);
        $this->db->table('clubora.chat_blocks')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->parent->id,'other_account_id'=>$this->trainer->id]);
        $this->assertNotContains($blocked,array_column($s->conversations($this->trainer,$this->tenant),'id'));
    }

    public function test_planning_notification_batches_match_single_event_rights_and_scale_for_distinct_history(): void
    {
        $this->db->unprepared('CREATE TABLE clubora.team_invitations(tenant_id integer,event_id integer,member_id integer);
CREATE TABLE clubora.notifications(id integer PRIMARY KEY,tenant_id integer,account_id integer,object_type text,object_id integer,title text,body text,created_at text,read_at text);');
        $source=$this->db->table('clubora.team_seasons')->where('id',$this->team)->first();
        $otherTeam=$this->db->table('clubora.team_seasons')->insertGetId(['tenant_id'=>$this->tenant->id,'team_id'=>$source->team_id,'season_id'=>$source->season_id,'label'=>'Other']);
        $ids=[];
        for($i=0;$i<23;$i++) $ids[]=$this->db->table('clubora.team_events')->insertGetId(['tenant_id'=>$this->tenant->id,'team_season_id'=>$this->team]);
        $invited=$this->db->table('clubora.team_events')->insertGetId(['tenant_id'=>$this->tenant->id,'team_season_id'=>$otherTeam]);
        $hidden=$this->db->table('clubora.team_events')->insertGetId(['tenant_id'=>$this->tenant->id,'team_season_id'=>$otherTeam]);
        $deleted=$this->db->table('clubora.team_events')->insertGetId(['tenant_id'=>$this->tenant->id,'team_season_id'=>$this->team,'deleted_at'=>now()->toIso8601String()]);
        $own=$this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->value('member_id');
        $this->db->table('clubora.team_invitations')->insert(['tenant_id'=>$this->tenant->id,'event_id'=>$invited,'member_id'=>$own]);
        $all=[...$ids,$invited,$hidden,$deleted];
        $notifications=app(\App\ClubUnio\Mobile\MobileNotificationService::class);
        $batch=new \ReflectionMethod($notifications,'planningRights');
        foreach([$this->parent,$this->trainer,$this->admin,$this->player] as $actor) {
            $baseline=[]; foreach($all as $id) $baseline['planning:'.$id]=app(\App\ClubUnio\Mobile\MobilePushOutbox::class)->targetAllowed($actor,$this->tenant,'planning',$id,false);
            $rights=[]; $args=[$actor,$this->tenant,$all,&$rights]; $batch->invokeArgs($notifications,$args);
            $this->assertSame($baseline,$rights);
        }
        $measure=function(array $events)use($batch,$notifications){
            $rights=[]; $args=[$this->parent,$this->tenant,$events,&$rights];
            $this->db->enableQueryLog(); $this->db->flushQueryLog(); $batch->invokeArgs($notifications,$args);
            $count=count($this->db->getQueryLog()); $this->db->disableQueryLog(); return $count;
        };
        $this->assertSame($measure([$ids[0]]),$measure($ids));
        foreach($ids as $id) $this->db->table('clubora.mobile_inbox')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->parent->id,'kind'=>'planning','object_id'=>$id,'event_key'=>'event:'.$id]);
        $page=$notifications->page($this->parent,$this->tenant,0,20,false); $this->assertSame(23,$page['totalRecordCount']);
        $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class);
        $notifications->page($this->parent,$this->tenant,0,20,false);
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

    private function pushDeliveryFixture(): void
    {
        $this->db->unprepared('CREATE TABLE clubora.mobile_push_devices (id text PRIMARY KEY, fixed_tenant_id integer, revision integer DEFAULT 1, revoked_at text);
CREATE TABLE clubora.mobile_push_bindings (id text PRIMARY KEY, device_id text, account_id integer, session_id text, revision integer DEFAULT 1, revoked_at text, created_at text);
CREATE TABLE clubora.mobile_push_outbox (id text PRIMARY KEY, binding_id text, account_id integer, device_id text, tenant_id integer,
kind text, object_id integer, event_key text, device_revision integer, binding_revision integer, created_at text,
UNIQUE(binding_id,tenant_id,event_key));
CREATE TABLE clubora.mobile_chat_publications (id integer PRIMARY KEY AUTOINCREMENT,tenant_id integer,account_id integer,message_id integer,
state text DEFAULT \'pending\',attempts integer DEFAULT 0,next_attempt_at text,queued_count integer DEFAULT 0,last_error_code text,
created_at text,finished_at text,UNIQUE(tenant_id,message_id));');
        config(['clubunio-mobile-push.enabled'=>true]);
    }

    private function pushBinding(Account $actor): array
    {
        $session = explode('.', $this->token($actor))[0];
        $device = (string) Str::uuid(); $binding = (string) Str::uuid();
        $this->db->table('clubora.mobile_push_devices')->insert(['id'=>$device,'revision'=>2]);
        $this->db->table('clubora.mobile_push_bindings')->insert(['id'=>$binding,'device_id'=>$device,'account_id'=>$actor->id,
            'session_id'=>$session,'revision'=>3,'created_at'=>now()->toIso8601String()]);
        return compact('session','device','binding');
    }

    public function test_chat_push_candidates_without_bound_devices_have_constant_queries_and_no_target_checks(): void
    {
        $this->pushDeliveryFixture();
        $outbox = app(\App\ClubUnio\Mobile\MobilePushOutbox::class);
        $message = (object)['id'=>91,'kind'=>'text','conversation_id'=>999999];
        $measure = function () use ($outbox,$message) {
            $this->db->flushQueryLog(); $this->db->enableQueryLog();
            try { $outbox->chatMessage($this->admin,$this->tenant,$message); return $this->db->getQueryLog(); }
            finally { $this->db->disableQueryLog(); }
        };
        $small = $measure();
        for ($i=0;$i<33;$i++) $this->account('admin');
        $many = $measure();
        $this->assertCount(1,$small); $this->assertCount(1,$many);
        foreach ($many as $query) $this->assertStringNotContainsString('chat_conversations',$query['query']);
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        try {
            $this->assertSame(0,$outbox->enqueue($this->parent,$this->tenant,'chat',999999,'synthetic.no.device'));
            $queries = $this->db->getQueryLog();
        } finally { $this->db->disableQueryLog(); }
        $this->assertCount(1,$queries,'No binding must return before any account/member/chat authorization query');
        $this->assertSame(0,$this->db->table('clubora.mobile_push_outbox')->count());
        Http::assertNothingSent();
    }

    public function test_eligible_chat_push_still_checks_mute_leave_roles_binding_session_and_fixed_tenant_freshly(): void
    {
        $this->pushDeliveryFixture();
        $chat = $this->teamChat($this->parent); $proof = $this->pushBinding($this->parent);
        $outbox = app(\App\ClubUnio\Mobile\MobilePushOutbox::class);
        $emit = fn ($id) => $outbox->chatMessage($this->trainer,$this->tenant,(object)['id'=>$id,'kind'=>'text','conversation_id'=>$chat]);
        $emit(101); $emit(101);
        $this->assertSame(1,$this->db->table('clubora.mobile_push_outbox')->count(),'Same binding/event is idempotent');
        $row = $this->db->table('clubora.mobile_push_outbox')->first();
        $this->assertSame($this->parent->id,(int)$row->account_id);
        $this->assertSame(2,(int)$row->device_revision); $this->assertSame(3,(int)$row->binding_revision);
        $membership = $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id);
        $membership->update(['muted'=>true]); $emit(102);
        $membership->update(['muted'=>false,'left_at'=>now()->toIso8601String()]); $emit(103);
        $membership->update(['left_at'=>null]);
        $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']); $emit(104);
        $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'approved']);
        $this->assertSame(1,$this->db->table('clubora.mobile_push_outbox')->count(),'Opt-in is never a replacement for live object access');
        foreach (['binding','device','session','expired','foreign','wrong_session_actor'] as $blocked) {
            if($blocked==='binding') $this->db->table('clubora.mobile_push_bindings')->where('id',$proof['binding'])->update(['revoked_at'=>now()->toIso8601String()]);
            if($blocked==='device') $this->db->table('clubora.mobile_push_devices')->where('id',$proof['device'])->update(['revoked_at'=>now()->toIso8601String()]);
            if($blocked==='session') $this->db->table('clubora.mobile_sessions')->where('id',$proof['session'])->update(['revoked_at'=>now()->toIso8601String()]);
            if($blocked==='expired') $this->db->table('clubora.mobile_sessions')->where('id',$proof['session'])->update(['expires_at'=>now()->subHour()->toIso8601String()]);
            if($blocked==='foreign') $this->db->table('clubora.mobile_push_devices')->where('id',$proof['device'])->update(['fixed_tenant_id'=>$this->tenant->id+99]);
            if($blocked==='wrong_session_actor') $this->db->table('clubora.mobile_push_bindings')->where('id',$proof['binding'])->update(['session_id'=>explode('.',$this->token($this->trainer))[0]]);
            $this->db->flushQueryLog(); $this->db->enableQueryLog();
            try { $emit(105); $queries=$this->db->getQueryLog(); } finally { $this->db->disableQueryLog(); }
            $this->assertCount(1,$queries,$blocked.' proof must disappear before any domain authorization');
            $this->db->table('clubora.mobile_push_bindings')->where('id',$proof['binding'])->update(['revoked_at'=>null,'session_id'=>$proof['session']]);
            $this->db->table('clubora.mobile_push_devices')->where('id',$proof['device'])->update(['revoked_at'=>null,'fixed_tenant_id'=>null]);
            $this->db->table('clubora.mobile_sessions')->where('id',$proof['session'])->update(['revoked_at'=>null,'expires_at'=>now()->addHour()->toIso8601String()]);
        }
        $this->assertSame(1,$this->db->table('clubora.mobile_push_outbox')->count());
        $this->db->table('clubora.mobile_push_devices')->where('id',$proof['device'])->update(['fixed_tenant_id'=>$this->tenant->id]);
        $emit(106);
        $this->assertSame(2,$this->db->table('clubora.mobile_push_outbox')->count(),'Matching custom tenant remains supported');
        $this->assertSame(0,$outbox->enqueue($this->parent,$this->tenant,'chat',$chat,'source.old',now()->subDay()->toIso8601String()));
        $this->assertSame(1,$outbox->enqueue($this->parent,$this->tenant,'chat',$chat,'source.current',now()->addDay()->toIso8601String()));
        Http::assertNothingSent();
    }

    public function test_disabled_push_keeps_chat_path_closed_without_accessing_delivery_tables(): void
    {
        config(['clubunio-mobile-push.enabled'=>false]);
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        try {
            $outbox=app(\App\ClubUnio\Mobile\MobilePushOutbox::class);
            $this->assertSame(0,$outbox->enqueue($this->admin,$this->tenant,'chat',1,'synthetic.closed'));
            $outbox->chatMessage($this->admin,$this->tenant,(object)['id'=>1,'kind'=>'text','conversation_id'=>1]);
            $queries=$this->db->getQueryLog();
        } finally { $this->db->disableQueryLog(); }
        $this->assertSame([],$queries);
    }

    public function test_lookup_by_client_is_owned_fresh_scoped_and_foreign_message_uuids_are_private(): void
    {
        $chat=$this->teamChat($this->parent); $this->teamChat($this->trainer);
        $token=$this->token($this->parent); $client=(string)Str::uuid();
        $row=$this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'),['client_id'=>$client,'text'=>'Saved before timeout'])->assertCreated()->json('data');
        $lookup=$this->url('chats/'.$chat.'/messages/by-client/'.$client);
        $this->getJson($lookup)->assertOk()->assertJsonPath('data.id',$row['id'])->assertJsonPath('data.client_id',$client);
        $this->getJson($this->url('chats/'.$chat.'/messages/by-client/'.Str::uuid()))->assertNotFound();
        $this->getJson($this->url('chats/99999/messages/by-client/'.$client))->assertNotFound();
        $this->withToken($this->token($this->trainer))->getJson($lookup)->assertNotFound();
        $this->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('data.0.client_id',null);
        $this->withToken($token);
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id)->update(['joined_after'=>$row['id']]);
        $this->getJson($lookup)->assertNotFound();
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id)->update(['joined_after'=>0]);
        $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
        $this->getJson($lookup)->assertForbidden();
    }

    public function test_lookup_by_client_never_creates_membership_or_rejoins_left_accounts_and_only_reads(): void
    {
        $chat=$this->teamChat($this->parent); $client=(string)Str::uuid();
        $row=app(ChatService::class)->send($this->parent,$this->tenant,$chat,['client_id'=>$client,'text'=>'Read only lookup']);
        $membership=$this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id);
        $service=app(ChatService::class);
        $read=function()use($service,$chat,$client) {
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            try { return $service->messageByClient($this->parent,$this->tenant,$chat,$client); }
            finally {
                $queries=$this->db->getQueryLog(); $this->db->disableQueryLog();
                $this->assertNotEmpty($queries);
                foreach($queries as $query) $this->assertMatchesRegularExpression('/^select\s/i',ltrim($query['query']));
            }
        };
        $this->assertSame((int)$row->id,$read()['id']);
        $membership->update(['left_at'=>now()->toIso8601String()]);
        $before=(array)$membership->first();
        try { $read(); $this->fail('Left member cannot recover a message'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $this->assertSame(404,$error->getStatusCode()); }
        $this->assertSame($before,(array)$membership->first());
        $membership->delete();
        try { $read(); $this->fail('Missing member cannot recover a message'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $this->assertSame(404,$error->getStatusCode()); }
        $this->assertFalse($membership->exists());
    }

    public function test_worker_bound_session_proof_reuses_facts_without_leaking_or_reusing_revoked_rights(): void
    {
        $this->pushDeliveryFixture(); $chat=$this->teamChat($this->parent); $proof=$this->pushBinding($this->parent);
        $original=request(); app()->instance('request',\Illuminate\Http\Request::create('https://synthetic.example','GET'));
        $worker=app(\App\ClubUnio\Mobile\MobilePushOutbox::class);
        $method=new \ReflectionMethod($worker,'boundTargetAllowed');
        try {
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            $this->assertTrue($method->invoke($worker,$this->parent,$this->tenant,'chat',$chat,$proof['session']));
            $optimized=count($this->db->getQueryLog()); $this->db->flushQueryLog();
            $this->assertTrue($worker->targetAllowed($this->parent,$this->tenant,'chat',$chat));
            $ordinary=count($this->db->getQueryLog()); $this->db->disableQueryLog();
            $this->assertLessThan($ordinary,$optimized);
            $this->assertLessThanOrEqual(15,$optimized);
            foreach (['mobile.actor','mobile.session',\App\ClubUnio\Mobile\MobileReadContext::class,\App\ClubUnio\Mobile\ChatAccess::class] as $key)
                $this->assertFalse(request()->attributes->has($key));
            $this->assertFalse($method->invoke($worker,$this->trainer,$this->tenant,'chat',$chat,$proof['session']),'Session must belong to this recipient');
            $this->db->table('clubora.mobile_sessions')->where('id',$proof['session'])->update(['revoked_at'=>now()->toIso8601String()]);
            $this->assertFalse($method->invoke($worker,$this->parent,$this->tenant,'chat',$chat,$proof['session']));
            $this->db->table('clubora.mobile_sessions')->where('id',$proof['session'])->update(['revoked_at'=>null,'expires_at'=>now()->subMinute()->toIso8601String()]);
            $this->assertFalse($method->invoke($worker,$this->parent,$this->tenant,'chat',$chat,$proof['session']));
            $this->db->table('clubora.mobile_sessions')->where('id',$proof['session'])->update(['expires_at'=>now()->addHour()->toIso8601String()]);
            $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
            $this->assertFalse($method->invoke($worker,$this->parent,$this->tenant,'chat',$chat,$proof['session']),'Next proof must see fresh domain rights');
        } finally { $this->db->disableQueryLog(); app()->instance('request',$original); }
    }

    public function test_worker_read_proofs_are_fresh_per_source_and_recipient_and_remove_local_frames(): void
    {
        $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent);
        $message=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Fresh worker proof']);
        $original=request(); app()->instance('request',\Illuminate\Http\Request::create('https://synthetic.example','GET'));
        try {
            $source=app(\App\ClubUnio\Mobile\ChatPushPublications::class);
            $outbox=app(\App\ClubUnio\Mobile\MobilePushOutbox::class);
            $this->assertNotNull($source->source($this->tenant,$message->id));
            $this->assertTrue($outbox->targetAllowed($this->parent,$this->tenant,'chat',$chat));
            $this->assertFalse(request()->attributes->has(\App\ClubUnio\Mobile\ChatAccess::class));
            $this->db->table('clubora.account_member_links')->whereIn('account_id',[$this->trainer->id,$this->parent->id])->update(['state'=>'revoked']);
            $this->assertNull($source->source($this->tenant,$message->id));
            $this->assertFalse($outbox->targetAllowed($this->parent,$this->tenant,'chat',$chat));
            $this->assertFalse(request()->attributes->has(\App\ClubUnio\Mobile\ChatAccess::class));
        } finally { app()->instance('request',$original); }
    }

    public function test_chat_push_preview_uses_live_text_and_closes_for_join_floor_deletion_and_revocation(): void
    {
        $this->pushDeliveryFixture();
        foreach (['state text DEFAULT \'pending\'', 'lease_until text', 'outcome_code text', 'provider_reference text', 'finished_at text'] as $column)
            $this->db->statement('ALTER TABLE clubora.mobile_push_outbox ADD COLUMN '.$column);
        foreach (['app_id text', 'platform text', 'origin text', 'token_cipher text'] as $column)
            $this->db->statement('ALTER TABLE clubora.mobile_push_devices ADD COLUMN '.$column);
        config(['clubunio-mobile-push.delivery_enabled'=>true]);
        $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent); $proof=$this->pushBinding($this->parent);
        $this->db->table('clubora.mobile_push_devices')->where('id',$proof['device'])->update([
            'app_id'=>'synthetic','platform'=>'ios','origin'=>'https://synthetic.example',
            'token_cipher'=>\Illuminate\Support\Facades\Crypt::encryptString('synthetic-token')]);
        $this->db->table('clubora.mobile_preferences')->updateOrInsert(['account_id'=>$this->parent->id],['language'=>'en']);
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushRegistry::class,new class {
            public function app(...$args): array { return ['fixed_tenant_id'=>null]; }
        });
        $transport=new class {
            public array $notifications=[];
            public function send($app,$token,$envelope,$notification): array {
                $this->notifications[]=$notification; return ['state'=>'accepted','code'=>'provider_accepted'];
            }
        };
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushFcm::class,$transport);
        $worker=app(\App\ClubUnio\Mobile\MobilePushOutbox::class);
        $originalRequest=request(); app()->instance('request',\Illuminate\Http\Request::create('https://synthetic.example','GET'));
        try { foreach (['edited','floor','deleted','revoked'] as $case) {
            $message=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Old text']);
            $this->assertSame(1,$worker->enqueue($this->parent,$this->tenant,'chat',$chat,'chat.message:'.$message->id));
            $row=$this->db->table('clubora.mobile_push_outbox')->where('event_key','chat.message:'.$message->id)->sole();
            $membership=$this->db->table('clubora.chat_memberships')->where('tenant_id',$this->tenant->id)
                ->where('conversation_id',$chat)->where('account_id',$this->parent->id);
            if($case==='edited') $this->db->table('clubora.chat_messages')->where('id',$message->id)->update([
                'body_encrypted'=>\Illuminate\Support\Facades\Crypt::encryptString('Current preview')]);
            if($case==='floor') $membership->update(['joined_after'=>$message->id]);
            if($case==='deleted') $this->db->table('clubora.chat_messages')->where('id',$message->id)->update(['deleted_at'=>now()->toIso8601String()]);
            if($case==='revoked') $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
            $this->assertTrue($worker->deliverOne($row->id));
            $this->assertSame(in_array($case,['deleted','revoked'],true)?'discarded':'accepted',
                $this->db->table('clubora.mobile_push_outbox')->where('id',$row->id)->value('state'));
            $membership->update(['joined_after'=>0]);
        } } finally { app()->instance('request',$originalRequest); }
        $this->assertCount(2,$transport->notifications);
        $this->assertSame('Current preview',$transport->notifications[0]['body']);
        $this->assertSame('Open the app to view the notification.',$transport->notifications[1]['body']);
        Http::assertNothingSent();
    }

    public function test_message_and_publication_are_atomic_and_post_never_calculates_push_recipients(): void
    {
        $this->pushDeliveryFixture(); $chat=$this->teamChat($this->parent);
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushOutbox::class,new class {
            public function chatMessage(...$args): int { throw new \LogicException('Inline fanout is forbidden'); }
        });
        $token=$this->token($this->parent); $payload=['client_id'=>(string)Str::uuid(),'text'=>'Atomic chat publication'];
        $row=$this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'),$payload)->assertCreated()->json('data');
        $this->postJson($this->url('chats/'.$chat.'/messages'),$payload)->assertCreated()->assertJsonPath('data.id',$row['id']);
        $this->postJson($this->url('chats/'.$chat.'/messages'),array_replace($payload,['text'=>'Different payload']))->assertConflict();
        $this->assertSame(1,$this->db->table('clubora.chat_messages')->where('conversation_id',$chat)->count());
        $this->assertSame(1,$this->db->table('clubora.mobile_chat_publications')->count());
        $this->assertSame(0,$this->db->table('clubora.mobile_push_outbox')->count());
        $this->app->instance(\App\ClubUnio\Mobile\ChatPushPublications::class,new class {
            public function record(...$args): void { throw new \RuntimeException('Synthetic intent failure'); }
        });
        $failed=(string)Str::uuid();
        try { app(ChatService::class)->send($this->parent,$this->tenant,$chat,['client_id'=>$failed,'text'=>'Must roll back']); $this->fail('Intent failure accepted'); }
        catch(\RuntimeException $error) { $this->assertSame('Synthetic intent failure',$error->getMessage()); }
        $this->assertFalse($this->db->table('clubora.chat_messages')->where('client_id',$failed)->exists());
        $this->assertSame(1,$this->db->table('clubora.chat_changes')->count());
        $this->assertSame(0,$this->db->transactionLevel());
    }

    public function test_publication_worker_rolls_back_partial_fanout_retries_and_is_idempotent(): void
    {
        $this->pushDeliveryFixture(); $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent); $this->pushBinding($this->parent);
        $message=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Background only']);
        $real=new \App\ClubUnio\Mobile\MobilePushOutbox;
        $fault=new class($real) {
            public bool $fail=true;
            public function __construct(private $real) {}
            public function chatMessage(...$args): int {
                $count=$this->real->chatMessage(...$args);
                if($this->fail) throw new \RuntimeException('Synthetic partial fanout failure');
                return $count;
            }
        };
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushOutbox::class,$fault);
        $worker=app(\App\ClubUnio\Mobile\ChatPushPublications::class);
        $this->assertTrue($worker->processOne());
        $job=$this->db->table('clubora.mobile_chat_publications')->first();
        $this->assertSame('pending',$job->state); $this->assertSame(1,(int)$job->attempts);
        $this->assertSame(0,$this->db->table('clubora.mobile_push_outbox')->count(),'Partial outbox insert must roll back');
        $this->assertFalse($worker->processOne(),'Retry backoff must not busy-loop');
        $this->db->table('clubora.mobile_chat_publications')->update(['next_attempt_at'=>now()->subMinute()->toIso8601String()]);
        $fault->fail=false;
        $this->assertTrue($worker->processOne()); $this->assertFalse($worker->processOne());
        $job=$this->db->table('clubora.mobile_chat_publications')->first();
        $this->assertSame('done',$job->state); $this->assertSame(2,(int)$job->attempts); $this->assertSame(1,(int)$job->queued_count);
        $this->assertSame(1,$this->db->table('clubora.mobile_push_outbox')->count());
        $this->assertSame('chat.message:'.$message->id,$this->db->table('clubora.mobile_push_outbox')->value('event_key'));
        Http::assertNothingSent();
    }

    public function test_publication_worker_discards_deleted_or_revoked_source_and_excludes_late_optin(): void
    {
        $this->pushDeliveryFixture(); $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent);
        $worker=app(\App\ClubUnio\Mobile\ChatPushPublications::class);
        $message=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'No retrospective device delivery']);
        $proof=$this->pushBinding($this->parent);
        $this->db->table('clubora.mobile_push_bindings')->where('id',$proof['binding'])->update(['created_at'=>now()->addHour()->toIso8601String()]);
        $this->assertTrue($worker->processOne());
        $this->assertSame(0,$this->db->table('clubora.mobile_chat_publications')->where('message_id',$message->id)->value('queued_count'));
        foreach(['deleted','inactive','left','revoked'] as $reason) {
            $message=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Fresh source '.$reason]);
            if($reason==='deleted') $this->db->table('clubora.chat_messages')->where('id',$message->id)->update(['deleted_at'=>now()->toIso8601String()]);
            if($reason==='inactive') $this->db->table('clubora.accounts')->where('id',$this->trainer->id)->update(['active'=>false]);
            if($reason==='left') $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->trainer->id)->update(['left_at'=>now()->toIso8601String()]);
            if($reason==='revoked') $this->db->table('clubora.account_member_links')->where('account_id',$this->trainer->id)->update(['state'=>'revoked']);
            $this->assertTrue($worker->processOne());
            $this->assertSame('discarded',$this->db->table('clubora.mobile_chat_publications')->where('message_id',$message->id)->value('state'),$reason);
            $this->db->table('clubora.accounts')->where('id',$this->trainer->id)->update(['active'=>true]);
            $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->trainer->id)->update(['left_at'=>null]);
            $this->db->table('clubora.account_member_links')->where('account_id',$this->trainer->id)->update(['state'=>'approved']);
        }
        $this->assertSame(0,$this->db->table('clubora.mobile_push_outbox')->count());
    }

    public function test_queued_publication_rechecks_source_before_provider_without_exposing_deleted_or_revoked_messages(): void
    {
        $this->pushDeliveryFixture(); $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent);
        $message=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Final source check']);
        $outbox=new \App\ClubUnio\Mobile\MobilePushOutbox;
        $check=new \ReflectionMethod($outbox,'publicationCurrent');
        $row=(object)['kind'=>'chat','object_id'=>$chat,'event_key'=>'chat.message:'.$message->id];
        $this->assertTrue($check->invoke($outbox,$this->tenant,$row));
        $wrong=clone $row; $wrong->object_id=$chat+999;
        $this->assertFalse($check->invoke($outbox,$this->tenant,$wrong));
        $wrong=clone $row; $wrong->kind='announcement';
        $this->assertFalse($check->invoke($outbox,$this->tenant,$wrong));
        $wrong=clone $row; $wrong->event_key='chat.message:invalid';
        $this->assertFalse($check->invoke($outbox,$this->tenant,$wrong));
        $this->db->table('clubora.chat_messages')->where('id',$message->id)->update(['deleted_at'=>now()->toIso8601String()]);
        $this->assertFalse($check->invoke($outbox,$this->tenant,$row));
        $this->db->table('clubora.chat_messages')->where('id',$message->id)->update(['deleted_at'=>null]);
        $this->db->table('clubora.account_member_links')->where('account_id',$this->trainer->id)->update(['state'=>'revoked']);
        $this->assertFalse($check->invoke($outbox,$this->tenant,$row));
        Http::assertNothingSent();
    }

    public function test_publication_retry_budget_stops_after_five_failures(): void
    {
        $this->pushDeliveryFixture(); $chat=$this->teamChat($this->trainer);
        app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Bounded retry']);
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushOutbox::class,new class {
            public function chatMessage(...$args): int { throw new \RuntimeException('Synthetic retry failure'); }
        });
        $worker=app(\App\ClubUnio\Mobile\ChatPushPublications::class);
        for($i=0;$i<5;$i++) {
            $this->db->table('clubora.mobile_chat_publications')->update(['next_attempt_at'=>now()->subMinute()->toIso8601String()]);
            $this->assertTrue($worker->processOne());
        }
        $job=$this->db->table('clubora.mobile_chat_publications')->first();
        $this->assertSame('attention',$job->state); $this->assertSame(5,(int)$job->attempts);
        $this->assertFalse($worker->processOne()); $this->assertSame(0,$this->db->transactionLevel());
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

    public function test_send_uses_shared_tenant_session_and_exclusive_chat_without_changing_other_write_locks(): void
    {
        $chat=$this->teamChat($this->parent); $token=$this->token($this->parent);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $message=$this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'),[
            'client_id'=>(string)Str::uuid(),'text'=>'Shared tenant send',
        ])->assertCreated()->json('data');
        $queries=collect($this->db->getQueryLog())->pluck('query');
        $tenantLocks=$queries->filter(fn($sql)=>str_contains($sql,'from "clubora"."tenants"') && str_contains($sql,'/*'));
        $this->assertCount(2,$tenantLocks);
        foreach($tenantLocks as $sql) $this->assertStringContainsString('for share',$sql);
        $this->assertTrue($queries->contains(fn($sql)=>str_contains($sql,'from "clubora"."mobile_sessions"') && str_contains($sql,'for share')));
        $this->assertTrue($queries->contains(fn($sql)=>str_contains($sql,'from "clubora"."chat_conversations"') && str_contains($sql,'for update')));
        $this->db->flushQueryLog();
        $this->postJson($this->url('chats/'.$chat.'/read'),['message_id'=>$message['id']])->assertOk();
        $this->assertTrue(collect($this->db->getQueryLog())->contains(fn($q)=>str_contains($q['query'],'from "clubora"."tenants"') && str_contains($q['query'],'for update')));
        $this->db->disableQueryLog();
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
        $second=app(ChatService::class)->ensure($this->tenant,'team',$this->team,key:'second-authorized-chat');
        $this->postJson($this->url('chats/'.$second->id.'/messages'),$data)->assertConflict();
        $this->assertSame(1, $this->db->table('clubora.chat_messages')->count());
        $this->assertStringNotContainsString($data['text'], $this->db->table('clubora.chat_messages')->get()->toJson());
        $this->withToken($token)->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('data.0.text', $data['text']);
    }

    public function test_replies_persist_with_fresh_same_chat_visibility_and_owned_idempotency(): void
    {
        $chat=$this->teamChat($this->parent); $this->teamChat($this->trainer);
        $source=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Original private message']);
        $token=$this->token($this->parent); $data=['client_id'=>(string)Str::uuid(),'text'=>'My reply','reply_to_message_id'=>$source->id];
        $reply=$this->withToken($token)->postJson($this->url('chats/'.$chat.'/messages'),$data)->assertCreated()
            ->assertJsonPath('data.reply.id',$source->id)->assertJsonPath('data.reply.text','Original private message')->json('data');
        $this->assertSame($source->id,$this->db->table('clubora.chat_messages')->where('id',$reply['id'])->value('reply_to_message_id'));
        $this->postJson($this->url('chats/'.$chat.'/messages'),$data)->assertCreated()->assertJsonPath('data.id',$reply['id']);
        $this->postJson($this->url('chats/'.$chat.'/messages'),array_replace($data,['reply_to_message_id'=>null]))->assertConflict();
        $this->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('meta.reply_supported',true);
        $other=app(ChatService::class)->ensure($this->tenant,'team',$this->team,key:'another-reply-chat');
        $this->postJson($this->url('chats/'.$other->id.'/messages'),array_replace($data,['client_id'=>(string)Str::uuid()]))->assertNotFound();
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->where('account_id',$this->parent->id)->update(['joined_after'=>$source->id]);
        $this->postJson($this->url('chats/'.$chat.'/messages'),array_replace($data,['client_id'=>(string)Str::uuid()]))->assertNotFound();
        $dto=app(ChatService::class)->messageDto($this->parent,$this->tenant,$this->db->table('clubora.chat_messages')->where('id',$reply['id'])->first());
        $this->assertSame(['id'=>$source->id,'deleted'=>true,'author_name'=>null,'text'=>null,'kind'=>null],$dto['reply']);
        $this->assertSame(2,$this->db->table('clubora.chat_messages')->count());
    }

    public function test_reply_quote_edits_and_deletes_refresh_children_through_change_feed_without_leaking_deleted_content(): void
    {
        $chat=$this->teamChat($this->parent); $this->teamChat($this->trainer); $s=app(ChatService::class);
        $source=$s->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Original']);
        $reply=$s->send($this->parent,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Response','reply_to_message_id'=>$source->id]);
        $cursor=$this->db->table('clubora.chat_changes')->max('id');
        $controls=app(\App\ClubUnio\Mobile\ChatControls::class);
        $controls->edit($this->trainer,$this->tenant,$source->id,1,'Updated original');
        $this->assertTrue($this->db->table('clubora.chat_changes')->where('id','>',$cursor)->where('message_id',$reply->id)->where('revision',1)->exists());
        $this->assertSame('Updated original',$s->messageDto($this->parent,$this->tenant,$reply)['reply']['text']);
        $controls->edit($this->trainer,$this->tenant,$source->id,2,null);
        $quote=$s->messageDto($this->parent,$this->tenant,$reply)['reply'];
        $this->assertTrue($quote['deleted']); $this->assertNull($quote['text']); $this->assertNull($quote['author_name']);
        $this->withToken($this->token($this->parent))->postJson($this->url('chats/'.$chat.'/messages'),[
            'client_id'=>(string)Str::uuid(),'text'=>'Cannot quote deleted','reply_to_message_id'=>$source->id,
        ])->assertNotFound();
        $this->assertSame(1,(int)$this->db->table('clubora.chat_messages')->where('id',$reply->id)->value('revision'));
    }

    public function test_reply_projection_is_batched_and_cross_tenant_or_corrupt_cross_chat_quotes_are_closed(): void
    {
        $chat=$this->teamChat($this->parent); $s=app(ChatService::class);
        $source=$s->send($this->parent,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>str_repeat('a',300)]);
        $rows=[];
        for($i=0;$i<35;$i++) $rows[]=$s->send($this->parent,$this->tenant,$chat,[
            'client_id'=>(string)Str::uuid(),'text'=>'Bounded reply','reply_to_message_id'=>$source->id,
        ]);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $single=$s->messageDtos($this->parent,$this->tenant,[$rows[0]]); $one=count($this->db->getQueryLog());
        $this->db->flushQueryLog(); $many=$s->messageDtos($this->parent,$this->tenant,$rows); $all=count($this->db->getQueryLog());
        $this->db->disableQueryLog();
        $this->assertSame($one,$all); $this->assertSame(240,mb_strlen($many[$rows[0]->id]['reply']['text']));
        $other=$s->ensure($this->tenant,'team',$this->team,key:'corrupt-reply-fixture');
        $this->db->table('clubora.chat_messages')->where('id',$source->id)->update(['conversation_id'=>$other->id]);
        $this->assertTrue($s->messageDto($this->parent,$this->tenant,$rows[0])['reply']['deleted']);
        $this->db->table('clubora.chat_messages')->where('id',$source->id)->update(['tenant_id'=>$this->tenant->id+999]);
        $quote=$s->messageDto($this->parent,$this->tenant,$rows[0])['reply'];
        $this->assertTrue($quote['deleted']); $this->assertNull($quote['author_name']);
    }

    public function test_read_receipts_are_own_only_minimized_cursor_based_and_paginated(): void
    {
        $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent); $this->teamChat($this->player);
        $s=app(ChatService::class); $row=$s->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Read cursor source']);
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat)->update(['read_message_id'=>$row->id]);
        $path=$this->url('messages/'.$row->id.'/readers');
        $first=$this->withToken($this->token($this->trainer))->getJson($path.'?limit=1')->assertOk()->assertJsonPath('meta.has_more',true)->json();
        $this->assertCount(1,$first['data']); $this->assertSame(['account_id','name'],array_keys($first['data'][0]));
        $this->assertNotSame($this->trainer->id,$first['data'][0]['account_id']);
        $second=$this->getJson($path.'?limit=1&after='.$first['meta']['next_after'])->assertOk()->assertJsonPath('meta.has_more',false)->json();
        $this->assertCount(1,$second['data']); $this->assertGreaterThan($first['data'][0]['account_id'],$second['data'][0]['account_id']);
        $this->getJson($path.'?limit=51')->assertUnprocessable(); $this->getJson($path.'?after=-1')->assertUnprocessable();
        $this->getJson($path.'?unread=true')->assertUnprocessable();
        $own=$s->messageDto($this->trainer,$this->tenant,$row); $other=$s->messageDto($this->parent,$this->tenant,$row);
        $this->assertTrue($own['can_view_readers']); $this->assertFalse($other['can_view_readers']);
        $this->withToken($this->token($this->parent))->getJson($path)->assertNotFound();
        $this->withToken($this->token($this->admin))->getJson($path)->assertNotFound();
        $this->withToken($this->token($this->trainer))->getJson($this->url('chats/'.$chat.'/messages'))->assertOk()->assertJsonPath('meta.read_receipts_supported',true);
    }

    public function test_read_receipts_recheck_readers_scope_and_never_rejoin_or_create_owner_membership(): void
    {
        $chat=$this->teamChat($this->trainer); $this->teamChat($this->parent); $this->teamChat($this->player);
        $row=app(ChatService::class)->send($this->trainer,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Fresh readers']);
        $member=$this->db->table('clubora.chat_memberships')->where('conversation_id',$chat);
        $member->update(['read_message_id'=>$row->id]);
        $path=$this->url('messages/'.$row->id.'/readers');
        $this->withToken($this->token($this->trainer));
        foreach(['not_read','late_join','left','inactive','api_only','revoked_link','no_tenant'] as $reason) {
            $parentMember=(clone $member)->where('account_id',$this->parent->id);
            if($reason==='not_read') $parentMember->update(['read_message_id'=>0]);
            if($reason==='late_join') $parentMember->update(['joined_after'=>$row->id]);
            if($reason==='left') $parentMember->update(['left_at'=>now()->toIso8601String()]);
            if($reason==='inactive') $this->db->table('clubora.accounts')->where('id',$this->parent->id)->update(['active'=>false]);
            if($reason==='api_only') $this->db->table('clubora.accounts')->where('id',$this->parent->id)->update(['api_only'=>true]);
            if($reason==='revoked_link') $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
            if($reason==='no_tenant') $this->db->table('clubora.tenant_accounts')->where('account_id',$this->parent->id)->delete();
            $ids=array_column($this->getJson($path)->assertOk()->json('data'),'account_id');
            $this->assertNotContains($this->parent->id,$ids,$reason); $this->assertContains($this->player->id,$ids);
            $parentMember->update(['read_message_id'=>$row->id,'joined_after'=>0,'left_at'=>null]);
            $this->db->table('clubora.accounts')->where('id',$this->parent->id)->update(['active'=>true,'api_only'=>false]);
            $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'approved']);
            if($reason==='no_tenant') $this->db->table('clubora.tenant_accounts')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->parent->id,'role'=>'parent','portal_enabled'=>true]);
        }
        $owner=(clone $member)->where('account_id',$this->trainer->id);
        $owner->update(['left_at'=>now()->toIso8601String()]); $before=(array)$owner->first();
        $this->getJson($path)->assertNotFound(); $this->assertSame($before,(array)$owner->first());
        $owner->delete(); $this->getJson($path)->assertNotFound(); $this->assertFalse($owner->exists());
    }

    public function test_read_receipts_advance_empty_filtered_pages_and_make_no_database_writes(): void
    {
        $chat=$this->teamChat($this->admin); $row=app(ChatService::class)->send($this->admin,$this->tenant,$chat,['client_id'=>(string)Str::uuid(),'text'=>'Bounded read page']);
        for($i=0;$i<102;$i++) {
            $account=$this->account('parent');
            $this->db->table('clubora.chat_memberships')->insert(['tenant_id'=>$this->tenant->id,'conversation_id'=>$chat,'account_id'=>$account->id,'joined_after'=>0,'read_message_id'=>$row->id]);
        }
        $last=$this->account('admin');
        $this->db->table('clubora.chat_memberships')->insert(['tenant_id'=>$this->tenant->id,'conversation_id'=>$chat,'account_id'=>$last->id,'joined_after'=>0,'read_message_id'=>$row->id]);
        $this->withToken($this->token($this->admin));
        $path=$this->url('messages/'.$row->id.'/readers');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $first=$this->getJson($path)->assertOk()->assertJsonCount(0,'data')->assertJsonPath('meta.has_more',true)->json();
        $this->assertGreaterThan(0,$first['meta']['next_after']);
        $second=$this->getJson($path.'?after='.$first['meta']['next_after'])->assertOk()->assertJsonPath('meta.has_more',false)->json();
        $this->assertSame([$last->id],array_column($second['data'],'account_id'));
        $queries=$this->db->getQueryLog(); $this->db->disableQueryLog();
        foreach($queries as $query) $this->assertMatchesRegularExpression('/^select\s/i',ltrim($query['query']));
        $this->db->table('clubora.chat_messages')->where('id',$row->id)->update(['deleted_at'=>now()->toIso8601String()]);
        $this->getJson($path)->assertNotFound();
        $this->assertFalse(app(ChatService::class)->messageDto($this->admin,$this->tenant,$this->db->table('clubora.chat_messages')->where('id',$row->id)->first())['can_view_readers']);
    }

    public function test_read_receipts_close_for_foreign_tenants_owner_join_floor_and_current_direct_blocks(): void
    {
        $service=app(ChatService::class); $chat=$service->direct($this->parent,$this->tenant,$this->trainer->id);
        $message=$service->send($this->parent,$this->tenant,$chat->id,['client_id'=>(string)Str::uuid(),'text'=>'Direct read cursor']);
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat->id)->update(['read_message_id'=>$message->id]);
        $path=$this->url('messages/'.$message->id.'/readers');
        $this->withToken($this->token($this->parent))->getJson($path)->assertOk()->assertJsonPath('data.0.account_id',$this->trainer->id);
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat->id)->where('account_id',$this->parent->id)->update(['joined_after'=>$message->id]);
        $this->getJson($path)->assertNotFound();
        $this->db->table('clubora.chat_memberships')->where('conversation_id',$chat->id)->where('account_id',$this->parent->id)->update(['joined_after'=>0]);
        $this->db->table('clubora.chat_blocks')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->trainer->id,'other_account_id'=>$this->parent->id,'created_at'=>now()->toIso8601String()]);
        $this->getJson($path)->assertNotFound();
        $other=Tenant::create(['name'=>'Other readers fixture','slug'=>'other-readers']);
        $otherActor=$this->account('admin',$other);
        $foreign=$service->ensure($other,'announcements');
        $foreignId=$this->db->table('clubora.chat_messages')->insertGetId(['tenant_id'=>$other->id,'conversation_id'=>$foreign->id,'account_id'=>$otherActor->id,
            'client_id'=>(string)Str::uuid(),'kind'=>'text','content_hash'=>str_repeat('0',64),'body_encrypted'=>\Illuminate\Support\Facades\Crypt::encryptString('Foreign'),'created_at'=>now()->toIso8601String()]);
        $this->getJson($this->url('messages/'.$foreignId.'/readers'))->assertNotFound();
        Http::assertNothingSent();
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

    public function test_reaction_participant_pages_are_bounded_name_only_and_do_not_mutate_reactions(): void
    {
        $chat=$this->teamChat($this->parent); $this->teamChat($this->player);
        $parent=$this->token($this->parent); $player=$this->token($this->player);
        $message=$this->withToken($parent)->postJson($this->url('chats/'.$chat.'/messages'),['client_id'=>(string)Str::uuid(),'text'=>'Reaction participants'])->assertCreated()->json('data');
        foreach ([$parent,$player] as $token) $this->withToken($token)->postJson($this->url('messages/'.$message['id'].'/reaction'),['revision'=>1,'emoji'=>'👍'])->assertOk();
        $count=$this->db->table('clubora.chat_changes')->count();
        $url=$this->url('messages/'.$message['id'].'/reactions');
        $ids=[$this->parent->id,$this->player->id]; sort($ids);
        $page=$this->withToken($parent)->getJson($url.'?limit=1')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('meta.has_more',true)->assertJsonPath('meta.next_after',$ids[0]);
        $this->assertEqualsCanonicalizing(['account_id','name','emoji','mine'],array_keys($page->json('data.0')));
        $this->assertSame($ids[0],$page->json('data.0.account_id'));
        $this->assertSame($ids[0]===$this->parent->id,$page->json('data.0.mine'));
        $second=$this->getJson($url.'?limit=1&after='.$ids[0])->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.account_id',$ids[1])->assertJsonPath('meta.has_more',false)->assertJsonPath('meta.next_after',$ids[1]);
        $this->assertSame($ids[1]===$this->parent->id,$second->json('data.0.mine'));
        $this->getJson($url.'?after='.$ids[1])->assertOk()->assertJsonCount(0,'data')->assertJsonPath('meta.next_after',$ids[1]);
        $this->getJson($url.'?limit=51')->assertUnprocessable();
        $this->getJson($url.'?after=-1')->assertUnprocessable();
        $this->getJson($url.'?email=true')->assertUnprocessable();
        $this->assertSame(2,$this->db->table('clubora.chat_reactions')->count());
        $this->assertSame($count,$this->db->table('clubora.chat_changes')->count());
        Http::assertNothingSent();
    }

    public function test_reaction_participant_read_rechecks_history_tenant_block_deletion_and_revocation(): void
    {
        $chat=$this->teamChat($this->parent); $parent=$this->token($this->parent);
        $message=$this->withToken($parent)->postJson($this->url('chats/'.$chat.'/messages'),['client_id'=>(string)Str::uuid(),'text'=>'Scoped participants'])->assertCreated()->json('data');
        $url=$this->url('messages/'.$message['id'].'/reactions');
        $this->teamChat($this->player); // Joined after the message: do not reveal historic participant names.
        $this->withToken($this->token($this->player))->getJson($url)->assertNotFound();
        $foreign=Tenant::create(['slug'=>'foreign-reaction','name'=>'Foreign synthetic']);
        $this->withToken($parent)->getJson('/api/mobile/v1/clubs/'.$foreign->id.'/messages/'.$message['id'].'/reactions')->assertForbidden();
        $this->db->table('clubora.chat_messages')->where('id',$message['id'])->update(['deleted_at'=>now()->toIso8601String()]);
        $this->getJson($url)->assertNotFound();
        $this->db->table('clubora.chat_messages')->where('id',$message['id'])->update(['deleted_at'=>null]);
        $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
        $this->getJson($url)->assertForbidden();
        // Direct conversations additionally honour fresh block state.
        $direct=app(ChatService::class)->direct($this->trainer,$this->tenant,$this->player->id);
        $trainer=$this->token($this->trainer);
        $sent=$this->withToken($trainer)->postJson($this->url('chats/'.$direct->id.'/messages'),['client_id'=>(string)Str::uuid(),'text'=>'Direct participants'])->assertCreated()->json('data');
        $directUrl=$this->url('messages/'.$sent['id'].'/reactions');
        $this->getJson($directUrl)->assertOk();
        $this->db->table('clubora.chat_blocks')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->player->id,'other_account_id'=>$this->trainer->id,'created_at'=>now()->toIso8601String()]);
        $this->getJson($directUrl)->assertNotFound();
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

    public function test_empty_central_language_policy_closes_preferences_and_chat_opt_in_without_rewriting_saved_rows(): void
    {
        config(['clubunio-languages.enabled'=>[],'clubunio-languages.default'=>'de']);
        $token=$this->token($this->parent);
        $this->db->table('clubora.mobile_preferences')->insert(['account_id'=>$this->parent->id,'language'=>'fr']);
        $this->db->table('clubora.chat_preferences')->insert(['tenant_id'=>$this->tenant->id,'account_id'=>$this->parent->id,
            'target_language'=>'en','translation_enabled'=>true,'revision'=>3]);
        $this->withToken($token)->getJson('/api/mobile/v1/context')->assertOk()->assertJsonPath('data.language',null)
            ->assertJsonPath('data.language_policy',['enabled'=>[],'default'=>null])->assertJsonPath('data.language_fallback',true);
        $this->getJson($this->url('chat-preferences'))->assertOk()->assertJsonPath('data.target_language',null)
            ->assertJsonPath('data.translation_enabled',false)->assertJsonPath('data.revision',3);
        $this->postJson('/api/mobile/v1/preferences',['language'=>'en'])->assertUnprocessable();
        $this->postJson($this->url('chat-preferences'),['revision'=>3,'target_language'=>'en','translation_enabled'=>true])->assertUnprocessable();
        config(['clubunio-mobile.ai.enabled'=>true]);
        $this->postJson($this->url('ai'),['request_key'=>(string)Str::uuid(),'operation'=>'friendly','language'=>'en','text'=>'Synthetic draft','consent'=>true])->assertUnprocessable();
        $this->assertSame('fr',$this->db->table('clubora.mobile_preferences')->where('account_id',$this->parent->id)->value('language'));
        $this->assertSame('en',$this->db->table('clubora.chat_preferences')->where('account_id',$this->parent->id)->value('target_language'));
        Http::assertNothingSent();
    }

    public function test_portuguese_central_alias_is_saved_canonically_and_revoked_policy_never_inherits_consent(): void
    {
        config(['clubunio-languages.enabled'=>['pt'=>'Português'],'clubunio-languages.default'=>'pt']);
        $token=$this->token($this->parent); $this->withToken($token);
        $this->postJson('/api/mobile/v1/preferences',['language'=>'pt'])->assertOk()->assertJsonPath('data.language','pt-PT')
            ->assertJsonPath('data.language_policy',['enabled'=>['pt-PT'],'default'=>'pt-PT']);
        $this->postJson($this->url('chat-preferences'),['revision'=>0,'target_language'=>'pt','translation_enabled'=>true])->assertOk()
            ->assertJsonPath('data.target_language','pt-PT')->assertJsonPath('data.translation_enabled',true);
        $this->assertSame('pt-PT',$this->db->table('clubora.mobile_preferences')->where('account_id',$this->parent->id)->value('language'));
        $this->assertSame('pt-PT',$this->db->table('clubora.chat_preferences')->where('account_id',$this->parent->id)->value('target_language'));
        config(['clubunio-languages.enabled'=>['de'=>'Deutsch'],'clubunio-languages.default'=>'de']);
        $this->getJson($this->url('chat-preferences'))->assertOk()->assertJsonPath('data.target_language','de')
            ->assertJsonPath('data.translation_enabled',false)->assertJsonPath('data.language_fallback',true)->assertJsonPath('data.revision',1);
        $this->postJson('/api/mobile/v1/preferences',['language'=>'pt-PT'])->assertUnprocessable();
        $this->postJson($this->url('chat-preferences'),['revision'=>1,'target_language'=>'pt-PT','translation_enabled'=>true])->assertUnprocessable();
        Http::assertNothingSent();
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

    public function test_push_enabled_planning_lists_never_build_squad_snapshots_but_detail_remains_fresh(): void
    {
        foreach (['title text','kind text','starts_at text','ends_at text','deadline_at text','mode text','status text','revision integer DEFAULT 1',
            'location text','description text','capacity integer','series_key text'] as $column) $this->db->statement('ALTER TABLE clubora.team_events ADD COLUMN '.$column);
        $this->db->statement('ALTER TABLE clubora.members ADD COLUMN updated_at text');
        $this->db->unprepared('CREATE TABLE clubora.team_invitations (tenant_id integer,event_id integer,member_id integer);
CREATE TABLE clubora.team_participations (id integer PRIMARY KEY,tenant_id integer,event_id integer,member_id integer,status text,attendance text,comment_encrypted text,revision integer,updated_at text);
CREATE TABLE clubora.team_guests (id integer PRIMARY KEY,tenant_id integer,event_id integer,first_name text,last_name text,status text,attendance text,comment_encrypted text,revision integer,deleted_at text);
CREATE TABLE clubora.object_changes (id integer PRIMARY KEY,tenant_id integer,object_type text,object_id integer);');
        $ids = [];
        for ($i = 0; $i < 20; $i++) $ids[] = $this->db->table('clubora.team_events')->insertGetId([
            'tenant_id' => $this->tenant->id, 'team_season_id' => $this->team, 'title' => 'Synthetic push list '.$i,
            'kind' => 'TRAINING', 'mode' => 'ANMELDEN', 'status' => 'AKTIV', 'revision' => 1,
            'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String(),
            'deadline_at' => now()->addDay()->toIso8601String(),
        ]);
        $ready = new class {
            public int $calls = 0;
            public function available(): bool { $this->calls++; return true; }
        };
        $snapshot = new class {
            public array $calls = [];
            public function snapshot($actor, $tenant, $id): array {
                $p = app(\App\ClubUnio\Services\TeamPlanningService::class);
                $event = $p->event($actor, $tenant, $id, true, 'update');
                $p->event($actor, $tenant, $id, true, 'export');
                $this->calls[] = [(int)$actor->id, (int)$tenant->id, $id, (int)$event->revision];
                return ['revision' => hash('sha256', (string)$event->revision), 'recipient_fingerprint' => str_repeat('a',64)];
            }
        };
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushReady::class, $ready);
        $this->app->instance(\App\ClubUnio\Mobile\MobilePushSquad::class, $snapshot);
        $request = fn ($limit) => \Illuminate\Http\Request::create('/', 'GET', ['recperpage' => $limit]);
        $data = fn () => app(\App\ClubUnio\Mobile\MobilePlanningData::class);
        $data()->events($request(1), $this->admin, $this->tenant);
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        $data()->events($request(1), $this->admin, $this->tenant);
        $small = count($this->db->getQueryLog()); $this->db->flushQueryLog();
        $many = $data()->events($request(20), $this->admin, $this->tenant);
        $large = count($this->db->getQueryLog()); $this->db->disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertCount(20, $many['TeamTermine']);
        $this->assertSame(0, $ready->calls, 'List/Today must not inspect push credentials per event');
        $this->assertSame([], $snapshot->calls, 'List/Today must not enumerate recipients');
        foreach ($many['TeamTermine'] as $row) {
            $this->assertFalse($row['CanSendSquadPush']);
            $this->assertArrayNotHasKey('PushRevision', $row);
            $this->assertArrayNotHasKey('PushRecipientFingerprint', $row);
            $this->assertTrue($row['CanShareSquad']);
        }
        $detail = $data()->event($this->admin, $this->tenant, $ids[0], false);
        $this->assertTrue($detail['CanSendSquadPush']);
        $this->assertSame(hash('sha256','1'), $detail['PushRevision']);
        $quick = $data()->event($this->admin, $this->tenant, $ids[0], false, false);
        $this->assertTrue($quick['CanSendSquadPush'], 'Opting out of preparation cannot remove the fresh push capability');
        $this->assertArrayNotHasKey('PushRevision', $quick);
        $this->assertArrayNotHasKey('PushRecipientFingerprint', $quick);
        $this->assertSame(array_diff_key($detail, array_flip(['PushRevision','PushRecipientFingerprint'])), $quick,
            'Lightweight detail retains the exact roster, rights and event projection');
        $this->assertCount(1, $snapshot->calls, 'Lightweight detail cannot prepare recipients');
        $this->db->table('clubora.team_events')->where('id',$ids[0])->update(['revision'=>2]);
        $fresh = $data()->event($this->admin, $this->tenant, $ids[0], false);
        $this->assertSame(hash('sha256','2'), $fresh['PushRevision']);
        $this->assertCount(2, $snapshot->calls);
        $parent = $data()->event($this->parent, $this->tenant, $ids[0], false);
        $this->assertFalse($parent['CanSendSquadPush']);
        $this->assertCount(2, $snapshot->calls, 'Parent detail must not inherit admin publication rights');
        $token = $this->token($this->admin);
        $url = $this->url('planning/events/'.$ids[0]);
        $this->withToken($token)->getJson($url.'?include_push_snapshot=0')->assertOk()
            ->assertJsonPath('data.TeamTermin.CanSendSquadPush', true)->assertJsonMissingPath('data.TeamTermin.PushRevision');
        $this->assertCount(2, $snapshot->calls);
        foreach (['invalid','false','2',''] as $value) $this->getJson($url.'?include_push_snapshot='.$value)->assertUnprocessable();
        $this->assertCount(2, $snapshot->calls, 'Malformed opt-outs must fail before projection');
        $this->getJson($url)->assertOk()->assertJsonPath('data.TeamTermin.PushRevision', hash('sha256','2'));
        $this->getJson($url.'?include_push_snapshot=1')->assertOk()->assertJsonPath('data.TeamTermin.PushRevision', hash('sha256','2'));
        $this->assertCount(4, $snapshot->calls, 'Omitted flag and explicit true retain the full old contract');
        $this->getJson($this->url('planning/events/999999?include_push_snapshot=0'))->assertNotFound();
        $parentToken = $this->token($this->parent);
        $this->withToken($parentToken)->getJson($url.'?include_push_snapshot=0')->assertOk()
            ->assertJsonPath('data.TeamTermin.CanSendSquadPush', false)->assertJsonMissingPath('data.TeamTermin.PushRevision');
        $this->db->table('clubora.account_member_links')->where('account_id',$this->parent->id)->update(['state'=>'revoked']);
        $this->getJson($url.'?include_push_snapshot=0')->assertForbidden();
        $this->assertCount(4, $snapshot->calls, 'Opt-out never bypasses revoked membership or grants push to a parent');
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
