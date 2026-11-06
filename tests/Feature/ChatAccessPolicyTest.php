<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{ChatAccess, ChatControls, ChatPreferences, ChatService, MobileAccess, MobileReadContext};
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BackofficeReadContext, ModulePermissionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class ChatAccessPolicyTest extends TestCase
{
    private $db;
    private Account $actor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'clubunio', 'database.connections.clubunio' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('clubunio');
        $this->db = DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $this->db->unprepared(<<<'SQL'
CREATE TABLE clubora.accounts (id integer PRIMARY KEY, active boolean, api_only boolean, display_name text);
CREATE TABLE clubora.tenants (id integer PRIMARY KEY);
INSERT INTO clubora.tenants VALUES (10);
CREATE TABLE clubora.tenant_accounts (account_id integer, tenant_id integer, role text, access_profile_id integer);
CREATE TABLE clubora.account_roles (code text PRIMARY KEY, active boolean);
CREATE TABLE clubora.access_profiles (id integer PRIMARY KEY, tenant_id integer, active boolean, permissions text);
INSERT INTO clubora.accounts VALUES (1, 1, 0, 'Synthetic profile'), (2, 1, 0, 'Synthetic office');
INSERT INTO clubora.tenant_accounts VALUES (1, 10, 'profile', 100), (2, 10, 'office', null);
INSERT INTO clubora.account_roles VALUES ('profile', 1);
INSERT INTO clubora.access_profiles VALUES (100, 10, 1, '["chat.view"]');
SQL);
        $this->actor = Account::findOrFail(1);
        $this->tenant = new Tenant;
        $this->tenant->forceFill(['id' => 10]);
        $this->app->instance('request', Request::create('/chat-data/list', 'GET'));
    }

    private function grants(array $functions): void
    {
        $this->db->table('clubora.access_profiles')->where('id', 100)->update([
            'permissions' => json_encode(array_map(fn ($function) => 'chat.'.$function, $functions)),
        ]);
    }

    private function forbidden(callable $run): void
    {
        try {
            $run();
            $this->fail('Expected authorization denial before touching chat data');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_profile_functions_are_independent_and_never_grant_personal_or_financial_access(): void
    {
        $policy = app(ChatAccess::class);
        $this->assertTrue($policy->allows($this->actor, $this->tenant, 'view'));
        $this->assertFalse($policy->allows($this->actor, $this->tenant, 'send'));
        $this->grants(['view', 'announce', 'moderate']);
        $this->assertTrue($policy->allows($this->actor, $this->tenant, 'announce'));
        $this->assertFalse($policy->admin($this->actor, $this->tenant));
        $this->assertTrue(app(ChatControls::class)->moderator($this->actor, $this->tenant,
            (object) ['tenant_id' => 10, 'kind' => 'announcements', 'team_season_id' => null]));
        $this->assertFalse(app(ChatControls::class)->moderator($this->actor, $this->tenant,
            (object) ['tenant_id' => 10, 'kind' => 'direct', 'team_season_id' => null]));
        $this->grants(['view', 'groups']);
        $this->assertTrue($policy->admin($this->actor, $this->tenant));
        $this->assertFalse($policy->allows($this->actor, $this->tenant, 'announce'));
        $this->assertFalse($policy->allows($this->actor, $this->tenant, 'invoices'));
        $this->assertFalse(app(ModulePermissionService::class)->allows($this->actor, $this->tenant, 'invoices', 'view'));
        $this->forbidden(fn () => app(MobileAccess::class)->enter($this->actor, $this->tenant));
        $office = Account::findOrFail(2);
        $this->assertTrue($policy->admin($office, $this->tenant));
        $this->assertTrue($policy->allows($office, $this->tenant, 'announce'));
        $this->assertFalse(app(MobileAccess::class)->admin($office, $this->tenant));
    }

    public function test_read_scope_memo_is_bounded_and_write_checks_discard_every_read_snapshot(): void
    {
        $this->grants(['view', 'send']);
        $policy = app(ChatAccess::class);
        $policy->duringRead($this->actor, $this->tenant, function () use ($policy) {
            $this->assertTrue($policy->allows($this->actor, $this->tenant, 'send'));
            $this->db->enableQueryLog();
            $this->db->flushQueryLog();
            for ($i = 0; $i < 20; $i++) {
                $policy->enter($this->actor, $this->tenant);
                $this->assertTrue($policy->allows($this->actor, $this->tenant, 'send'));
            }
            $this->assertCount(0, $this->db->getQueryLog());
            $otherTenant = new Tenant;
            $otherTenant->forceFill(['id' => 11]);
            $this->assertFalse($policy->allows($this->actor, $otherTenant, 'view'));
            $this->grants(['view']);
            request()->attributes->set(BackofficeReadContext::class, ['account' => 1,
                'tenants' => collect(), 'permissionCache' => ['permissions:10' => ['chat.view', 'chat.send']]]);
            $policy->duringRead($this->actor, $this->tenant, function () {
                $this->forbidden(fn () => app(ChatService::class)->send($this->actor, $this->tenant, 1, []));
            });
            $this->assertFalse(request()->attributes->has(ChatAccess::class));
            $this->assertFalse(request()->attributes->has(BackofficeReadContext::class));
            $this->assertFalse(request()->attributes->has(MobileReadContext::class));
            $this->assertFalse(request()->attributes->has('mobile.session'));
        });
        $this->assertFalse(request()->attributes->has(ChatAccess::class));
        $this->assertFalse($policy->allows($this->actor, $this->tenant, 'send'));
    }

    public function test_scope_cleanup_and_revocation_are_observed_on_next_call_and_posts_never_memoize(): void
    {
        $policy = app(ChatAccess::class);
        try {
            $policy->duringRead($this->actor, $this->tenant, fn () => throw new \RuntimeException('scope test'));
        } catch (\RuntimeException $error) {
            $this->assertSame('scope test', $error->getMessage());
        }
        $this->assertFalse(request()->attributes->has(ChatAccess::class));
        request()->setMethod('POST');
        $policy->duringRead($this->actor, $this->tenant, function () use ($policy) {
            $this->assertFalse(request()->attributes->has(ChatAccess::class));
            $this->assertTrue($policy->allows($this->actor, $this->tenant, 'view'));
            $this->db->table('clubora.accounts')->where('id', 1)->update(['active' => false]);
            $this->assertFalse($policy->allows($this->actor, $this->tenant, 'view'));
            $this->db->table('clubora.accounts')->where('id', 1)->update(['active' => true]);
            $this->db->table('clubora.tenant_accounts')->where('account_id', 1)->delete();
            $this->assertFalse($policy->allows($this->actor, $this->tenant, 'view'));
        });
    }

    public function test_read_only_and_simulation_cannot_mutate_services_without_a_controller(): void
    {
        $s = app(ChatService::class);
        $c = app(ChatControls::class);
        foreach ([fn () => $s->send($this->actor, $this->tenant, 1, []),
            fn () => $s->chat($this->actor, $this->tenant, 1, true),
            fn () => $s->direct($this->actor, $this->tenant, 2),
            fn () => $s->vote($this->actor, $this->tenant, 1, []),
            fn () => $c->edit($this->actor, $this->tenant, 1, 1, null),
            fn () => $c->react($this->actor, $this->tenant, 1, 1, null),
            fn () => $c->report($this->actor, $this->tenant, 1, 'test'),
            fn () => $c->resolve($this->actor, $this->tenant, 1),
            fn () => app(ChatPreferences::class)->save($this->actor, $this->tenant, [])] as $write) {
            $this->forbidden($write);
        }
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => 10, 'target_id' => 1]);
        $this->forbidden(fn () => $s->read($this->actor, $this->tenant, 1, 0));
        $this->assertTrue(app(ChatAccess::class)->allows($this->actor, $this->tenant, 'view'));
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => 11, 'target_id' => 1]);
        $this->assertFalse(app(ChatAccess::class)->allows($this->actor, $this->tenant, 'view'));
    }

    public function test_conversation_flags_require_send_but_keep_announce_and_groups_separate(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $method = new \ReflectionMethod(ChatService::class, 'conversationDto');
        $chat = (object) ['id' => 1, 'tenant_id' => 10, 'kind' => 'team', 'team_season_id' => 2];
        $projection = ['membership' => (object) ['muted' => false], 'chat_preferences' => [], 'unread' => 0,
            'admin' => false, 'profile' => true, 'staff' => true, 'send' => false, 'announce' => false,
            'poll' => true, 'moderate' => false, 'upload' => true, 'download' => false];
        $dto = $method->invoke(app(ChatService::class), $this->actor, $this->tenant, $chat, collect(), $projection);
        foreach (['can_write', 'can_poll', 'can_link_event', 'uploads', 'can_download', 'can_moderate'] as $flag) {
            $this->assertFalse($dto[$flag], $flag);
        }
        $chat->kind = 'announcements';
        $projection['send'] = true;
        $projection['announce'] = true;
        $projection['staff'] = false;
        $dto = $method->invoke(app(ChatService::class), $this->actor, $this->tenant, $chat, collect(), $projection);
        $this->assertTrue($dto['can_write']);
        $this->assertFalse($dto['can_poll']);
        $this->assertFalse($dto['can_moderate']);
    }

    public function test_explicit_profile_poll_grant_works_in_own_dm_without_granting_other_private_chats(): void
    {
        config(['clubunio-mobile-push.enabled' => false]);
        $this->db->unprepared(<<<'SQL'
CREATE TABLE clubora.chat_conversations (id integer PRIMARY KEY, tenant_id integer, kind text, scope_key text, team_season_id integer, event_id integer);
CREATE TABLE clubora.chat_memberships (tenant_id integer, conversation_id integer, account_id integer, joined_after integer, left_at text, read_message_id integer, muted boolean);
CREATE TABLE clubora.chat_blocks (tenant_id integer, account_id integer, other_account_id integer);
CREATE TABLE clubora.chat_messages (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, conversation_id integer, account_id integer, client_id text, content_hash text, kind text, body_encrypted text, object_id integer, revision integer DEFAULT 1, created_at text, deleted_at text);
CREATE TABLE clubora.chat_polls (id integer PRIMARY KEY AUTOINCREMENT, tenant_id integer, conversation_id integer, question text, options text, multiple boolean, closes_at text);
CREATE TABLE clubora.chat_changes (tenant_id integer, conversation_id integer, message_id integer, actor_id integer, action text, revision integer, created_at text);
INSERT INTO clubora.chat_conversations VALUES (1, 10, 'direct', 'direct:1:2', null, null), (2, 10, 'direct', 'direct:2:3', null, null);
INSERT INTO clubora.chat_memberships VALUES (10, 1, 1, 0, null, 0, 0), (10, 1, 2, 0, null, 0, 0), (10, 2, 2, 0, null, 0, 0), (10, 2, 3, 0, null, 0, 0);
SQL);
        $this->grants(['view', 'send', 'poll']);
        $s = app(ChatService::class);
        $input = ['client_id' => (string) Str::uuid(), 'kind' => 'poll', 'text' => 'Synthetic question',
            'poll' => ['options' => ['One', 'Two'], 'multiple' => false, 'closes_at' => now()->addHour()->toIso8601String()]];
        $message = $s->send($this->actor, $this->tenant, 1, $input);
        $this->assertSame('poll', $message->kind);
        $this->assertFalse(app(ChatAccess::class)->admin($this->actor, $this->tenant));
        $private = $s->table($this->tenant, 'chat_conversations')->where('id', 2)->first();
        $this->assertFalse($s->allowed($this->actor, $this->tenant, $private));
        $this->grants(['view', 'send', 'groups', 'moderate']);
        $this->assertFalse($s->allowed($this->actor, $this->tenant, $private));
        $this->assertFalse(app(ChatControls::class)->moderator($this->actor, $this->tenant, $private));
        // Revocation also applies to idempotent retries, before returning a prior success.
        $this->forbidden(fn () => $s->send($this->actor, $this->tenant, 1, $input));
        $this->assertSame(1, $s->table($this->tenant, 'chat_polls')->count());
    }

    public function test_profile_group_moderation_needs_both_visible_group_scope_and_moderate(): void
    {
        $this->db->unprepared(<<<'SQL'
CREATE TABLE clubora.seasons (id integer PRIMARY KEY, tenant_id integer, legacy_id integer, name text, active boolean, starts_on text, ends_on text);
CREATE TABLE clubora.teams (id integer PRIMARY KEY, tenant_id integer, name text, active boolean);
CREATE TABLE clubora.team_seasons (id integer PRIMARY KEY, tenant_id integer, team_id integer, season_id integer, label text, active boolean);
CREATE TABLE clubora.tenant_settings (tenant_id integer, area text, key text, encrypted_value text);
INSERT INTO clubora.seasons VALUES (1, 10, null, 'Synthetic season', 1, null, null);
INSERT INTO clubora.teams VALUES (1, 10, 'Synthetic team', 1);
INSERT INTO clubora.team_seasons VALUES (1, 10, 1, 1, 'Synthetic team season', 1);
SQL);
        $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => 10, 'area' => 'general',
            'key' => 'SaisonID', 'encrypted_value' => Crypt::encryptString('1')]);
        $chat = (object) ['tenant_id' => 10, 'kind' => 'staff', 'team_season_id' => 1];
        $controls = app(ChatControls::class);
        $this->grants(['view', 'moderate']);
        $this->assertFalse($controls->moderator($this->actor, $this->tenant, $chat));
        $this->grants(['view', 'groups']);
        $this->assertFalse($controls->moderator($this->actor, $this->tenant, $chat));
        $this->grants(['view', 'groups', 'moderate']);
        $this->assertTrue($controls->moderator($this->actor, $this->tenant, $chat));
        $this->assertTrue(app(ChatService::class)->allowed($this->actor, $this->tenant, $chat));
        $this->db->table('clubora.team_seasons')->where('id', 1)->update(['active' => false]);
        $this->assertFalse(app(ChatService::class)->allowed($this->actor, $this->tenant, $chat));
    }

    public function test_overview_latest_message_is_one_bounded_query_and_never_exposes_prejoin_deleted_or_foreign_content(): void
    {
        $this->db->unprepared(<<<'SQL'
CREATE TABLE clubora.chat_messages (id integer PRIMARY KEY, tenant_id integer, conversation_id integer, account_id integer, body_encrypted text, created_at text, deleted_at text);
CREATE TABLE clubora.chat_memberships (tenant_id integer, conversation_id integer, account_id integer, joined_after integer, left_at text);
INSERT INTO clubora.chat_memberships VALUES (10, 1, 1, 2, null), (10, 2, 1, 5, null), (10, 3, 1, 0, 'left'), (11, 4, 1, 0, null);
SQL);
        foreach ([[1, 10, 1, 'Before join', null], [2, 10, 1, 'At join', null],
            [3, 10, 1, str_repeat('Visible ', 40), null], [4, 10, 1, 'Deleted secret', 'deleted'],
            [5, 10, 2, 'Old history', null], [6, 10, 3, 'Left conversation', null],
            [7, 11, 4, 'Other tenant', null], [8, 10, 5, 'No membership', null]] as [$id, $tenant, $chat, $text, $deleted]) {
            $this->db->table('clubora.chat_messages')->insert(['id' => $id, 'tenant_id' => $tenant,
                'conversation_id' => $chat, 'account_id' => 2, 'body_encrypted' => Crypt::encryptString($text),
                'created_at' => '2026-11-04T12:00:00+00:00', 'deleted_at' => $deleted]);
        }
        $method = new \ReflectionMethod(ChatService::class, 'latestMessages');
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $result = $method->invoke(app(ChatService::class), $this->actor, $this->tenant, [1, 2, 3, 4, 5]);
        $this->assertCount(1, $this->db->getQueryLog());
        $this->assertSame([1], array_keys($result));
        $this->assertSame(3, $result[1]['id']);
        $this->assertSame(160, mb_strlen($result[1]['text']));
        $this->assertSame('Synthetic office', $result[1]['author_name']);
        $this->assertSame(['id', 'text', 'created_at', 'author_name'], array_keys($result[1]));
        $this->db->table('clubora.chat_messages')->where('id', 3)->update(['deleted_at' => 'deleted']);
        $this->assertSame([], $method->invoke(app(ChatService::class), $this->actor, $this->tenant, [1]));
    }
}
