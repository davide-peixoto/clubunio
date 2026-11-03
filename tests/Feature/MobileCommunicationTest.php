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
        foreach (['2026_11_02_000082_mobile_communication.php', '2026_11_02_000083_mobile_chat_controls.php'] as $migration) {
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

}
