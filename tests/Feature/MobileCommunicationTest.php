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
            'clubunio-mobile.enabled' => true, 'clubunio-mobile.ai.enabled' => false, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
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
CREATE TABLE clubora.tenant_domains (id integer PRIMARY KEY, tenant_id integer, host text, active boolean, ownership_verified boolean, dns_verified boolean, tls_verified boolean);
CREATE TABLE clubora.accounts (id integer PRIMARY KEY AUTOINCREMENT, username text UNIQUE, password text, display_name text, active boolean DEFAULT 1, api_only boolean DEFAULT 0, created_at text, updated_at text);
CREATE TABLE clubora.tenant_accounts (tenant_id integer, account_id integer, role text, portal_enabled boolean DEFAULT 1, access_profile_id integer, PRIMARY KEY(tenant_id,account_id));
CREATE TABLE clubora.members (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, first_name text, last_name text, birth_date text, exit_date text, archived_at text, status_id integer, UNIQUE(tenant_id,id));
CREATE TABLE clubora.member_statuses (id integer PRIMARY KEY, tenant_id integer, ends_membership boolean);
CREATE TABLE clubora.account_member_links (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, account_id integer, member_id integer, state text, kind text);
CREATE TABLE clubora.seasons (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, name text);
CREATE TABLE clubora.teams (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, name text, active boolean DEFAULT 1);
CREATE TABLE clubora.team_seasons (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_id integer, season_id integer, label text, active boolean DEFAULT 1, UNIQUE(tenant_id,id));
CREATE TABLE clubora.team_players (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, active boolean DEFAULT 1);
CREATE TABLE clubora.team_staff (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, active boolean DEFAULT 1);
CREATE TABLE clubora.technical_leads (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, member_id integer, active boolean DEFAULT 1);
CREATE TABLE clubora.team_events (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, team_season_id integer, UNIQUE(tenant_id,id));
CREATE TABLE clubora.invoices (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, member_id integer, active boolean DEFAULT 1, status_id integer, workflow_status_id integer, bexio_invoice_id integer, legacy_id integer, UNIQUE(tenant_id,id));
CREATE TABLE clubora.invoice_workflow_statuses (id integer PRIMARY KEY, tenant_id integer, code text, name text);
CREATE TABLE clubora.invoice_statuses (id integer PRIMARY KEY, tenant_id integer, code text, name text);
CREATE TABLE clubora.bexio_checks (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, object_type text, object_id integer, state text, remote_status text, remote_id text, applied_at text);
CREATE TABLE clubora.bexio_balances (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, object_type text, object_id integer, remote_status text, remote_id text);
SQL);
        $source = file_get_contents(database_path('clubunio/2026_11_02_000082_mobile_communication.php'));
        preg_match("/<<<'SQL'\n(.*?)\nSQL/s", str_replace("\r\n", "\n", $source), $match);
        $sql = preg_replace('/REVOKE ALL.*?FROM PUBLIC;/s', '', $match[1]);
        $sql = str_replace('id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY', 'id integer PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = str_replace(['timestamptz', 'jsonb', 'uuid', 'DEFAULT now()'], ['text', 'text', 'text', 'DEFAULT CURRENT_TIMESTAMP'], $sql);
        $sql = preg_replace('/REFERENCES clubora\./', 'REFERENCES ', $sql);
        $sql = preg_replace('/CREATE INDEX (\w+) ON clubora\.(\w+)/', 'CREATE INDEX clubora.$1 ON $2', $sql);
        $this->db->unprepared($sql);
        $this->tenant = Tenant::create(['slug' => 'mobile-test', 'name' => 'Mobile fixture']);
        $this->admin = $this->account('admin');
        $this->player = $this->account('member');
        $this->parent = $this->account('parent');
        $this->trainer = $this->account('trainer');
        $child = $this->member('Child', '2013-04-06');
        $coach = $this->member('Coach', '1985-02-03');
        foreach ([[$this->player, $child, 'self'], [$this->parent, $child, 'parent'], [$this->trainer, $coach, 'self']] as [$actor, $member, $kind]) {
            $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => $kind, 'state' => 'approved']);
        }
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => '2026/27']);
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
        $data = ['client_id' => (string) Str::uuid(), 'text' => 'Club announcement'];
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
        $data = ['client_id' => (string) Str::uuid(), 'text' => 'Important club notice'];
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

    public function test_ai_cache_budget_and_provider_switch_do_not_change_chat_messages(): void
    {
        config(['clubunio-mobile.ai.enabled' => true, 'clubunio-mobile.ai.openai.key' => 'synthetic-test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => 'Olá equipa']]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30]])]);
        $chat = $this->teamChat($this->parent);
        $token = $this->token($this->parent);
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
}
