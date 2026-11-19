<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{ChatAccess, ChatControls, ChatService, MobileAccess};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Http, Mail, Queue};
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use App\ClubUnio\Mobile\MalwareScanner;
use Tests\TestCase;

final class WebChatTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $office;
    private Account $reader;
    private int $profile;
    private array $contexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Synthetic PostgreSQL rollback opt-in required');
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'clubunio-mobile-push.enabled' => false,
            'clubunio-mobile-push.delivery_enabled' => false, 'clubunio-mobile.ai.enabled' => false]);
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner {
            public function scan(string $bytes): array { throw new \LogicException('Explicit synthetic scanner required'); }
        });
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL lock_timeout TO '3s'");
        $this->db->statement("SET LOCAL statement_timeout TO '20s'");
        $this->tenant = Tenant::create(['slug' => 'webchat-'.Str::lower(Str::random(12)), 'name' => 'Synthetic webchat']);
        $this->profile = $this->db->table('clubunio.access_profiles')->insertGetId(['tenant_id' => $this->tenant->id,
            'name' => 'Synthetic chat reader', 'active' => true, 'permissions' => json_encode(['chat.view'])]);
        $this->admin = $this->account('admin'); $this->office = $this->account('office'); $this->reader = $this->account('profile');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->tenant)) {
                Http::assertNothingSent(); Mail::assertNothingSent(); Mail::assertNothingQueued(); Queue::assertNothingPushed();
                $this->assertGreaterThan(0, $this->db->transactionLevel(), 'Fixture transaction must never be committed');
            }
        } finally {
            if (isset($this->db)) {
                while ($this->db->transactionLevel()) $this->db->rollBack();
                if (isset($this->tenant)) $this->assertFalse($this->db->table('clubunio.tenants')->where('id', $this->tenant->id)->exists());
            }
            parent::tearDown();
        }
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => 'webchat-'.Str::random(20), 'display_name' => 'Synthetic '.$role,
            'password' => 'not-a-login-secret', 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $a->id,
            'role' => $role, 'portal_enabled' => false, 'access_profile_id' => $role === 'profile' ? $this->profile : null]);
        $this->contexts[$a->id] = ['tenant' => (int)$this->tenant->id, 'revision' => (string)Str::uuid()];
        return $a;
    }

    private function chatRequest(Account $actor, string $operation, array $data = [], bool $write = false)
    {
        $this->actingAs($actor, 'clubunio')->withSession(['clubunio_context.'.$actor->id => $this->contexts[$actor->id]]);
        $data += ['context' => $this->contexts[$actor->id]];
        return $write ? $this->postJson('/chat-data/'.$operation, $data)
            : $this->getJson('/chat-data/'.$operation.'?'.http_build_query($data));
    }

    private function direct(): int
    {
        return $this->chatRequest($this->office, 'direct', ['account_id' => $this->admin->id], true)->assertOk()->json('data.id');
    }

    public function test_web_send_delta_edit_poll_and_idempotency_use_shared_mobile_records(): void
    {
        $chat = $this->direct();
        $this->chatRequest($this->admin, 'conversation', ['chat_id' => $chat])->assertOk();
        $input = ['chat_id' => $chat, 'client_id' => (string)Str::uuid(), 'text' => 'Synthetic web message', 'kind' => 'text'];
        $message = $this->chatRequest($this->office, 'send', $input, true)->assertOk()->json('data');
        $this->chatRequest($this->office, 'send', $input, true)->assertOk()->assertJsonPath('data.id', $message['id']);
        $this->assertSame(1, $this->db->table('clubunio.chat_messages')->where('tenant_id', $this->tenant->id)->count());
        $this->chatRequest($this->office, 'send', array_replace($input, ['text' => 'Different']), true)->assertStatus(409);
        $this->chatRequest($this->admin, 'messages', ['chat_id' => $chat])->assertOk()->assertJsonPath('data.0.text', 'Synthetic web message');
        $this->chatRequest($this->office, 'edit', ['message_id' => $message['id'], 'revision' => 1, 'text' => 'Edited'], true)->assertOk()->assertJsonPath('data.revision', 2);
        $this->chatRequest($this->admin, 'react', ['message_id' => $message['id'], 'revision' => 2, 'emoji' => '👍'], true)->assertOk();
        $this->chatRequest($this->admin, 'changes', ['chat_id' => $chat, 'after' => 0])->assertOk()->assertJsonPath('data.0.message.text', 'Edited');
        $poll = $this->chatRequest($this->office, 'send', ['chat_id' => $chat, 'client_id' => (string)Str::uuid(), 'text' => 'Synthetic poll', 'kind' => 'poll',
            'poll' => ['options' => ['One', 'Two'], 'multiple' => false, 'closes_at' => now()->addHour()->toIso8601String()]], true)->assertOk()->json('data');
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $poll['poll']['id'], 'choices' => [$poll['poll']['options'][0]['id']]], true)
            ->assertOk()->assertJsonPath('data.options.0.count', 1);
        $this->chatRequest($this->office, 'delete', ['message_id' => $message['id'], 'revision' => 2], true)->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertFalse(app(MobileAccess::class)->admin($this->office, $this->tenant));
        Http::assertNothingSent(); Mail::assertNothingSent();
    }

    public function test_read_only_and_tenant_context_fail_closed_without_expanding_finance(): void
    {
        $list = $this->chatRequest($this->reader, 'list')->assertOk()->json('data');
        $this->assertFalse($list['capabilities']['send']);
        $chat = collect($list['conversations'])->firstWhere('kind', 'announcements')['id'];
        foreach (['send' => ['chat_id' => $chat, 'client_id' => (string)Str::uuid(), 'text' => 'Forbidden'],
            'direct' => ['account_id' => $this->admin->id], 'mute' => ['chat_id' => $chat, 'muted' => true],
            'preferences-save' => ['revision' => 0, 'translation_enabled' => true, 'target_language' => 'en']] as $op => $data) {
            $this->chatRequest($this->reader, $op, $data, true)->assertForbidden();
        }
        $this->chatRequest($this->reader, 'preview')->assertForbidden();
        $this->chatRequest($this->reader, 'conversation', ['chat_id' => $this->direct()])->assertNotFound();
        $this->chatRequest($this->admin, 'list', ['context' => ['tenant' => $this->tenant->id, 'revision' => (string)Str::uuid()]])->assertStatus(419);
        $this->chatRequest($this->admin, 'list', ['context' => ['tenant' => $this->tenant->id + 1, 'revision' => $this->contexts[$this->admin->id]['revision']]])->assertStatus(419);
        $this->chatRequest($this->admin, 'send')->assertNotFound();
        $this->assertFalse(app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($this->reader, $this->tenant, 'invoices', 'view'));
        $this->db->table('clubunio.access_profiles')->where('id', $this->profile)->update(['active' => false]);
        $this->chatRequest($this->reader, 'list')->assertForbidden();
    }

    public function test_office_announcement_requires_current_preview_and_private_dm_is_not_moderated(): void
    {
        $s = app(ChatService::class); $chat = $s->ensure($this->tenant, 'announcements');
        $this->chatRequest($this->office, 'conversation', ['chat_id' => $chat->id])->assertOk()->assertJsonPath('data.can_write', true);
        $preview = $this->chatRequest($this->office, 'preview')->assertOk()->json('data');
        $input = ['chat_id' => $chat->id, 'client_id' => (string)Str::uuid(), 'kind' => 'announcement', 'text' => 'Synthetic announcement', 'recipient_fingerprint' => $preview['fingerprint']];
        $new = $this->account('office');
        $this->chatRequest($this->office, 'send', $input, true)->assertStatus(409);
        $input['recipient_fingerprint'] = $this->chatRequest($this->office, 'preview')->assertOk()->json('data.fingerprint');
        $this->chatRequest($this->office, 'send', $input, true)->assertOk();
        $this->assertSame(4, $s->table($this->tenant, 'mobile_inbox')->where('kind', 'announcement')->count());
        $private = $s->direct($this->admin, $this->tenant, $new->id);
        $this->chatRequest($this->office, 'conversation', ['chat_id' => $private->id])->assertNotFound();
        $this->chatRequest($this->office, 'reports', ['chat_id' => $this->direct()])->assertForbidden();
        $this->chatRequest($this->office, 'block', ['account_id' => $this->admin->id, 'blocked' => true], true)->assertOk();
        $this->chatRequest($this->admin, 'direct', ['account_id' => $this->office->id], true)->assertNotFound();
    }

    public function test_page_renders_in_the_authenticated_shell_in_both_enabled_languages(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->admin->forceFill(['preferred_locale' => $locale])->save();
            $this->actingAs($this->admin, 'clubunio')->withSession(['clubunio_context.'.$this->admin->id => $this->contexts[$this->admin->id]])
                ->get('/chats')->assertOk()->assertSee('data-cu-chat', false)->assertSee('csrf-token', false)
                ->assertSee($locale === 'en' ? 'Search conversations' : 'Unterhaltungen suchen');
        }
    }

    public function test_transport_context_unknown_objects_and_http_method_boundaries_fail_closed(): void
    {
        $this->getJson('/chat-data/list')->assertUnauthorized();
        $this->actingAs($this->admin, 'clubunio')->withSession(['clubunio_context.'.$this->admin->id => $this->contexts[$this->admin->id]]);
        foreach ([[], ['context' => 'invalid'], ['context' => ['tenant' => 0, 'revision' => 'invalid']],
            ['context' => ['tenant' => $this->tenant->id, 'revision' => []]]] as $data) {
            $this->postJson('/chat-data/send', $data)->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        }
        $old = $this->contexts[$this->admin->id];
        $this->contexts[$this->admin->id]['revision'] = (string) Str::uuid();
        $this->chatRequest($this->admin, 'list', ['context' => $old])->assertStatus(419);
        $this->chatRequest($this->admin, 'list', ['context' => $this->contexts[$this->admin->id] + ['extra' => true]])->assertUnprocessable();
        $this->chatRequest($this->admin, 'list', ['context' => ['tenant' => PHP_INT_MAX, 'revision' => $old['revision']]])->assertStatus(419);
        foreach (['direct', 'send', 'read', 'mute', 'vote', 'react', 'edit', 'delete', 'report', 'resolve',
            'preferences-save', 'block', 'upload', 'scan', 'attachment-remove', 'ai', 'event-chat', 'unknown'] as $op) {
            $this->chatRequest($this->admin, $op)->assertNotFound();
        }
        foreach (['list', 'conversation', 'messages', 'changes', 'contacts', 'events', 'preview', 'reports', 'reactions', 'preferences', 'blocks', 'download'] as $op) {
            $this->chatRequest($this->admin, $op, [], true)->assertNotFound();
        }
        config(['clubunio-mobile.uploads_enabled' => true]);
        foreach (['conversation' => ['chat_id' => PHP_INT_MAX], 'messages' => ['chat_id' => PHP_INT_MAX],
            'changes' => ['chat_id' => PHP_INT_MAX, 'after' => 0], 'reactions' => ['message_id' => PHP_INT_MAX],
            'download' => ['attachment_id' => (string) Str::uuid()], 'events' => ['team_season_id' => PHP_INT_MAX]] as $op => $data) {
            $this->chatRequest($this->admin, $op, $data)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach (['scan' => ['attachment_id' => (string) Str::uuid()], 'attachment-remove' => ['attachment_id' => (string) Str::uuid()],
            'vote' => ['poll_id' => PHP_INT_MAX, 'choices' => []], 'resolve' => ['report_id' => PHP_INT_MAX],
            'edit' => ['message_id' => PHP_INT_MAX, 'revision' => 1, 'text' => 'Synthetic'],
            'event-chat' => ['event_id' => PHP_INT_MAX], 'block' => ['account_id' => PHP_INT_MAX, 'blocked' => true]] as $op => $data) {
            $this->chatRequest($this->admin, $op, $data, true)->assertNotFound();
        }
        $this->assertSame(0, $this->db->table('clubunio.chat_messages')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_transport_rejects_invalid_identifiers_payloads_and_pagination(): void
    {
        $chat = $this->direct();
        $message = $this->chatRequest($this->office, 'send', ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic'], true)->assertOk()->json('data');
        $cases = [
            ['conversation', ['chat_id' => 0]], ['messages', ['chat_id' => $chat, 'after' => -1]],
            ['messages', ['chat_id' => $chat, 'after' => 1, 'before' => 2]], ['changes', ['chat_id' => $chat]],
            ['changes', ['chat_id' => $chat, 'after' => 'bad']], ['contacts', ['q' => str_repeat('x', 121)]],
            ['events', ['team_season_id' => []]], ['download', ['attachment_id' => 'bad']],
            ['reactions', ['message_id' => $message['id'], 'limit' => 51]], ['reactions', ['message_id' => $message['id'], 'after' => -1]],
        ];
        foreach ($cases as [$op, $data]) $this->chatRequest($this->admin, $op, $data)->assertUnprocessable();
        $cases = [
            ['direct', ['account_id' => -1]], ['send', ['chat_id' => $chat, 'text' => 'Synthetic', 'client_id' => 'bad']],
            ['send', ['chat_id' => $chat, 'text' => str_repeat('x', 4001), 'client_id' => (string) Str::uuid()]],
            ['send', ['chat_id' => $chat, 'text' => 'Synthetic', 'client_id' => (string) Str::uuid(), 'kind' => 'bad']],
            ['read', ['chat_id' => $chat, 'message_id' => -1]], ['mute', ['chat_id' => $chat, 'muted' => 'yes']],
            ['vote', ['poll_id' => 1, 'choices' => ['bad']]], ['react', ['message_id' => $message['id'], 'revision' => 1, 'emoji' => 'bad']],
            ['edit', ['message_id' => $message['id'], 'revision' => 0, 'text' => 'Synthetic']], ['delete', ['message_id' => $message['id']]],
            ['report', ['message_id' => $message['id'], 'reason' => str_repeat('x', 1001)]], ['resolve', ['report_id' => 0]],
            ['block', ['account_id' => $this->office->id, 'blocked' => 'bad']], ['upload', ['chat_id' => $chat, 'client_id' => (string) Str::uuid()]],
            ['scan', ['attachment_id' => 'bad']], ['attachment-remove', ['attachment_id' => 'bad']], ['event-chat', ['event_id' => 0]],
        ];
        foreach ($cases as [$op, $data]) $this->chatRequest($this->admin, $op, $data, true)->assertUnprocessable();
        $this->assertSame(1, $this->db->table('clubunio.chat_messages')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_read_only_profile_cannot_mutate_and_revocation_is_fresh_between_requests(): void
    {
        $this->chatRequest($this->reader, 'list')->assertOk();
        foreach (['direct', 'send', 'mute', 'vote', 'react', 'edit', 'delete', 'report', 'resolve', 'preferences-save',
            'block', 'upload', 'scan', 'attachment-remove', 'ai', 'event-chat'] as $op) {
            $this->chatRequest($this->reader, $op, $op === 'resolve' ? ['report_id' => PHP_INT_MAX] : [], true)->assertForbidden();
        }
        $this->chatRequest($this->office, 'list')->assertOk();
        $this->db->table('clubunio.accounts')->where('id', $this->office->id)->update(['api_only' => true]);
        $this->chatRequest($this->office, 'list')->assertForbidden();
        $this->db->table('clubunio.accounts')->where('id', $this->office->id)->update(['api_only' => false, 'active' => false]);
        $this->chatRequest($this->office, 'list')->assertForbidden();
        $this->db->table('clubunio.accounts')->where('id', $this->office->id)->update(['active' => true]);
        $this->chatRequest($this->office, 'list')->assertOk();
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->office->id)->delete();
        $this->chatRequest($this->office, 'list')->assertForbidden();
    }

    public function test_private_message_reaction_and_report_routes_never_grant_foreign_dm_access(): void
    {
        $chat = $this->direct();
        $message = $this->chatRequest($this->office, 'send', ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic private'], true)->assertOk()->json('data');
        $stranger = $this->account('admin');
        foreach (['conversation' => ['chat_id' => $chat], 'messages' => ['chat_id' => $chat], 'changes' => ['chat_id' => $chat, 'after' => 0],
            'reactions' => ['message_id' => $message['id']], 'reports' => ['chat_id' => $chat]] as $op => $data) {
            $this->chatRequest($stranger, $op, $data)->assertNotFound()->assertDontSee('Synthetic private');
        }
        $this->chatRequest($this->admin, 'report', ['message_id' => $message['id'], 'reason' => 'Synthetic reason'], true)->assertForbidden();
        $this->chatRequest($this->admin, 'delete', ['message_id' => $message['id'], 'revision' => 1], true)->assertForbidden();
        $this->chatRequest($this->admin, 'react', ['message_id' => $message['id'], 'revision' => 1, 'emoji' => '👍'], true)->assertOk();
        $this->chatRequest($this->office, 'reactions', ['message_id' => $message['id'], 'limit' => 1])->assertOk()->assertJsonPath('data.0.account_id', $this->admin->id)->assertJsonPath('meta.has_more', false);
        $this->chatRequest($this->admin, 'react', ['message_id' => $message['id'], 'revision' => 1, 'emoji' => null], true)->assertOk();
        $this->chatRequest($this->office, 'reactions', ['message_id' => $message['id']])->assertOk()->assertJsonCount(0, 'data');
        $this->chatRequest($this->office, 'edit', ['message_id' => $message['id'], 'revision' => 1, 'text' => 'Revision two'], true)->assertOk();
        $this->chatRequest($this->admin, 'react', ['message_id' => $message['id'], 'revision' => 1, 'emoji' => '👍'], true)->assertConflict();
    }

    private function upload(int $chat, ?string $client = null, string $bytes = "%PDF-1.4\nSynthetic fixture\n%%EOF")
    {
        $this->actingAs($this->office, 'clubunio')->withSession(['clubunio_context.'.$this->office->id => $this->contexts[$this->office->id]]);
        return $this->post('/chat-data/upload', ['context' => $this->contexts[$this->office->id], 'chat_id' => $chat,
            'client_id' => $client ?? (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', $bytes)], ['Accept' => 'application/json']);
    }

    public function test_upload_quarantine_scan_publication_download_and_deletion_are_private(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $chat = $this->direct(); $client = (string) Str::uuid();
        $attachment = $this->upload($chat, $client)->assertOk()->assertJsonPath('data.state', 'quarantine')->json('data.id');
        $this->upload($chat, $client)->assertOk()->assertJsonPath('data.id', $attachment);
        $this->upload($chat, $client, "%PDF-1.4\nChanged\n%%EOF")->assertConflict();
        $this->chatRequest($this->office, 'download', ['attachment_id' => $attachment])->assertNotFound();
        $this->chatRequest($this->admin, 'scan', ['attachment_id' => $attachment], true)->assertNotFound();
        $input = ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic attachment', 'attachment_id' => $attachment];
        $this->chatRequest($this->office, 'send', $input, true)->assertConflict();
        $scanner = new class implements MalwareScanner {
            public int $calls = 0;
            public function scan(string $bytes): array { $this->calls++; return ['clean' => true, 'version' => 'synthetic-only']; }
        };
        $this->app->instance(MalwareScanner::class, $scanner);
        $this->chatRequest($this->office, 'scan', ['attachment_id' => $attachment], true)->assertOk()->assertJsonPath('data.state', 'clean');
        $this->chatRequest($this->office, 'scan', ['attachment_id' => $attachment], true)->assertOk();
        $this->assertSame(1, $scanner->calls);
        $this->chatRequest($this->office, 'download', ['attachment_id' => $attachment])->assertNotFound();
        $message = $this->chatRequest($this->office, 'send', $input, true)->assertOk()->json('data');
        $this->chatRequest($this->admin, 'download', ['attachment_id' => $attachment])->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertSee('Synthetic fixture');
        $this->chatRequest($this->account('admin'), 'download', ['attachment_id' => $attachment])->assertNotFound();
        $this->chatRequest($this->reader, 'download', ['attachment_id' => $attachment])->assertForbidden();
        $this->chatRequest($this->office, 'attachment-remove', ['attachment_id' => $attachment], true)->assertConflict();
        $this->chatRequest($this->office, 'delete', ['message_id' => $message['id'], 'revision' => 1], true)->assertOk();
        $this->chatRequest($this->admin, 'download', ['attachment_id' => $attachment])->assertNotFound();
        $this->assertNull($this->db->table('clubunio.chat_attachments')->where('id', $attachment)->value('bytes_encrypted'));
    }

    public function test_rejected_and_unavailable_scans_never_publish_and_upload_limits_apply(): void
    {
        config(['clubunio-mobile.uploads_enabled' => true]);
        $chat = $this->direct();
        $this->upload($chat, null, 'not a PDF')->assertUnprocessable();
        $this->upload($chat, null, "%PDF-1.4\n".str_repeat('x', 5242880)."\n%%EOF")->assertUnprocessable();
        $attachment = $this->upload($chat)->assertOk()->json('data.id');
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner {
            public function scan(string $bytes): array { abort(503); }
        });
        $this->chatRequest($this->office, 'scan', ['attachment_id' => $attachment], true)->assertStatus(503);
        $this->assertSame('quarantine', $this->db->table('clubunio.chat_attachments')->where('id', $attachment)->value('state'));
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner {
            public function scan(string $bytes): array { return ['clean' => false, 'version' => 'synthetic-rejection']; }
        });
        $this->chatRequest($this->office, 'scan', ['attachment_id' => $attachment], true)->assertOk()->assertJsonPath('data.state', 'rejected');
        $this->assertNull($this->db->table('clubunio.chat_attachments')->where('id', $attachment)->value('bytes_encrypted'));
        $this->chatRequest($this->office, 'download', ['attachment_id' => $attachment])->assertNotFound();
        $this->chatRequest($this->office, 'send', ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Rejected', 'attachment_id' => $attachment], true)->assertConflict();
        $this->chatRequest($this->office, 'attachment-remove', ['attachment_id' => $attachment], true)->assertOk();
    }

    public function test_poll_ballots_replace_clear_and_expire_without_changing_other_domain_records(): void
    {
        $chat = $this->direct();
        $poll = $this->chatRequest($this->office, 'send', ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic ballot', 'kind' => 'poll',
            'poll' => ['options' => ['One', 'Two'], 'multiple' => false, 'closes_at' => now()->addHour()->toIso8601String()]], true)->assertOk()->json('data');
        $id = $poll['poll']['id']; $options = array_column($poll['poll']['options'], 'id');
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => $options], true)->assertUnprocessable();
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => [(string) Str::uuid()]], true)->assertUnprocessable();
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => [$options[0], $options[0]]], true)->assertUnprocessable();
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => [$options[0]]], true)->assertOk()->assertJsonPath('data.options.0.count', 1);
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => [$options[1]]], true)->assertOk()->assertJsonPath('data.options.0.count', 0)->assertJsonPath('data.options.1.count', 1);
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => []], true)->assertOk()->assertJsonPath('data.my_choices', []);
        $this->chatRequest($this->account('admin'), 'vote', ['poll_id' => $id, 'choices' => [$options[0]]], true)->assertNotFound();
        $this->db->table('clubunio.chat_polls')->where('id', $id)->update(['closes_at' => now()->subMinute()->toIso8601String()]);
        $this->chatRequest($this->admin, 'vote', ['poll_id' => $id, 'choices' => [$options[0]]], true)->assertConflict();
        $this->assertSame(0, $this->db->table('clubunio.team_participations')->where('tenant_id', $this->tenant->id)->count());
        $this->chatRequest($this->office, 'delete', ['message_id' => $poll['id'], 'revision' => 1], true)->assertOk();
        $this->assertSame(0, $this->db->table('clubunio.chat_votes')->where('poll_id', $id)->count());
    }

    public function test_announcement_reports_are_deduplicated_resolved_and_recipient_revocation_invalidates_preview(): void
    {
        $chat = app(ChatService::class)->ensure($this->tenant, 'announcements');
        $preview = $this->chatRequest($this->office, 'preview')->assertOk()->json('data');
        $input = ['chat_id' => $chat->id, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic announcement', 'kind' => 'announcement',
            'recipient_fingerprint' => $preview['fingerprint']];
        $this->db->table('clubunio.access_profiles')->where('id', $this->profile)->update(['active' => false]);
        $this->chatRequest($this->office, 'send', $input, true)->assertConflict();
        $this->assertSame(0, $this->db->table('clubunio.mobile_inbox')->where('tenant_id', $this->tenant->id)->count());
        $input['recipient_fingerprint'] = $this->chatRequest($this->office, 'preview')->assertOk()->json('data.fingerprint');
        $message = $this->chatRequest($this->office, 'send', $input, true)->assertOk()->json('data');
        $this->chatRequest($this->office, 'send', $input, true)->assertOk()->assertJsonPath('data.id', $message['id']);
        $this->assertSame(2, $this->db->table('clubunio.mobile_inbox')->where('tenant_id', $this->tenant->id)->count());
        foreach ([1, 2] as $attempt) $this->chatRequest($this->admin, 'report', ['message_id' => $message['id'], 'reason' => 'Synthetic reason'], true)->assertOk();
        $reports = $this->chatRequest($this->office, 'reports', ['chat_id' => $chat->id])->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->chatRequest($this->office, 'resolve', ['report_id' => $reports[0]['id']], true)->assertOk()->assertJsonPath('data.resolved', true);
        $this->chatRequest($this->office, 'reports', ['chat_id' => $chat->id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_group_moderator_can_resolve_without_send_but_cannot_delete_or_read_private_chats(): void
    {
        $this->db->table('clubunio.access_profiles')->where('id', $this->profile)->update([
            'permissions' => json_encode(['chat.view', 'chat.groups', 'chat.moderate']),
        ]);
        $this->chatRequest($this->reader, 'list')->assertOk()->assertJsonPath('data.capabilities.send', false)
            ->assertJsonPath('data.capabilities.groups', true)->assertJsonPath('data.capabilities.moderate', true);
        $chat = app(ChatService::class)->ensure($this->tenant, 'announcements');
        $preview = $this->chatRequest($this->office, 'preview')->assertOk()->json('data');
        $message = $this->chatRequest($this->office, 'send', ['chat_id' => $chat->id, 'client_id' => (string) Str::uuid(),
            'kind' => 'announcement', 'text' => 'Synthetic moderation', 'recipient_fingerprint' => $preview['fingerprint']], true)->assertOk()->json('data');
        $this->chatRequest($this->admin, 'report', ['message_id' => $message['id'], 'reason' => 'Synthetic reason'], true)->assertOk();
        $report = $this->chatRequest($this->reader, 'reports', ['chat_id' => $chat->id])->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->chatRequest($this->reader, 'delete', ['message_id' => $message['id'], 'revision' => 1], true)->assertForbidden();
        $this->chatRequest($this->reader, 'send', ['chat_id' => $chat->id, 'client_id' => (string) Str::uuid(), 'text' => 'Forbidden'], true)->assertForbidden();
        $this->chatRequest($this->reader, 'resolve', ['report_id' => $report['id']], true)->assertOk()->assertJsonPath('data.resolved', true);
        $this->chatRequest($this->reader, 'reports', ['chat_id' => $chat->id])->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame((int) $this->reader->id, (int) $this->db->table('clubunio.chat_reports')->where('id', $report['id'])->value('resolved_by'));
        $this->assertNull($this->db->table('clubunio.chat_messages')->where('id', $message['id'])->value('deleted_at'));
        $this->chatRequest($this->reader, 'reports', ['chat_id' => $this->direct()])->assertNotFound();
        $this->assertFalse(app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($this->reader, $this->tenant, 'invoices', 'view'));
        $this->db->table('clubunio.access_profiles')->where('id', $this->profile)->update(['permissions' => json_encode(['chat.view', 'chat.groups'])]);
        $this->chatRequest($this->reader, 'resolve', ['report_id' => $report['id']], true)->assertForbidden();
    }

    private function syntheticBinding(Account $actor): string
    {
        $session = (string) Str::uuid(); $device = (string) Str::uuid(); $binding = (string) Str::uuid();
        $this->db->table('clubunio.mobile_sessions')->insert(['id' => $session, 'account_id' => $actor->id,
            'token_hash' => hash('sha256', Str::random(64)), 'device_name' => 'Synthetic web chat', 'expires_at' => now()->addHour()->toIso8601String()]);
        $this->db->table('clubunio.mobile_push_devices')->insert(['id' => $device, 'proof_hash' => hash('sha256', $device),
            'app_id' => 'ch.clubunio.synthetic', 'platform' => 'android', 'origin' => 'https://synthetic.invalid',
            'token_cipher' => \Illuminate\Support\Facades\Crypt::encryptString('synthetic-not-a-provider-token'), 'token_hash' => hash('sha256', $binding)]);
        $this->db->table('clubunio.mobile_push_bindings')->insert(['id' => $binding, 'device_id' => $device, 'account_id' => $actor->id, 'session_id' => $session]);
        return $binding;
    }

    public function test_active_tenant_switch_rejects_old_tab_and_foreign_objects_without_losing_original_context(): void
    {
        $chat = $this->direct();
        $message = $this->chatRequest($this->office, 'send', ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic first tenant'], true)->assertOk()->json('data');
        $old = $this->contexts[$this->admin->id];
        $other = Tenant::create(['slug' => 'webchat-foreign-'.Str::lower(Str::random(12)), 'name' => 'Synthetic other tenant']);
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $other->id, 'account_id' => $this->admin->id, 'role' => 'admin']);
        $this->contexts[$this->admin->id] = ['tenant' => (int) $other->id, 'revision' => (string) Str::uuid()];
        $this->chatRequest($this->admin, 'list', ['context' => $old])->assertStatus(419);
        $this->chatRequest($this->admin, 'send', ['context' => $old, 'chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Stale draft'], true)->assertStatus(419);
        $this->chatRequest($this->admin, 'messages', ['chat_id' => $chat])->assertNotFound();
        $this->chatRequest($this->admin, 'reactions', ['message_id' => $message['id']])->assertNotFound();
        $this->chatRequest($this->admin, 'list')->assertOk()->assertDontSee('Synthetic first tenant');
        $this->contexts[$this->admin->id] = ['tenant' => PHP_INT_MAX, 'revision' => (string) Str::uuid()];
        $this->chatRequest($this->admin, 'list')->assertForbidden();
        $this->contexts[$this->admin->id] = $old;
        $this->chatRequest($this->admin, 'messages', ['chat_id' => $chat])->assertOk()->assertJsonPath('data.0.id', $message['id']);
        $this->assertSame(0, $this->db->table('clubunio.chat_messages')->where('tenant_id', $other->id)->count());
    }

    public function test_preferences_enforce_language_revision_and_disabled_ai_never_calls_provider(): void
    {
        $initial = $this->chatRequest($this->office, 'preferences')->assertOk()->json('data');
        $input = ['revision' => $initial['revision'], 'translation_enabled' => true, 'target_language' => 'en'];
        $this->chatRequest($this->office, 'preferences-save', array_replace($input, ['target_language' => 'zz']), true)->assertUnprocessable();
        $saved = $this->chatRequest($this->office, 'preferences-save', $input, true)->assertOk()->assertJsonPath('data.target_language', 'en')->json('data');
        $this->chatRequest($this->office, 'preferences-save', $input, true)->assertConflict();
        $this->chatRequest($this->office, 'preferences-save', array_replace($input, ['revision' => $saved['revision'], 'translation_enabled' => false]), true)->assertOk()->assertJsonPath('data.translation_enabled', false);
        $this->chatRequest($this->office, 'ai', ['operation' => 'rewrite', 'text' => 'Synthetic text'], true)->assertStatus(503);
        $policy = app(ChatAccess::class);
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => $this->tenant->id, 'target_id' => $this->admin->id]);
        try {
            $this->assertTrue($policy->allows($this->admin, $this->tenant, 'view'));
            foreach (['send', 'upload', 'poll', 'announce', 'moderate'] as $function) {
                $this->assertFalse($policy->allows($this->admin, $this->tenant, $function));
            }
        } finally { request()->attributes->remove('clubunio_impersonation'); }
    }

    public function test_verified_tenant_domain_preserves_the_host_bound_context(): void
    {
        config(['clubunio-domains.hosts' => ['web-chat-bound.invalid' => $this->tenant->slug]]);
        $this->contexts[$this->office->id]['host'] = 'web-chat-bound.invalid';
        $this->actingAs($this->office, 'clubunio')->withSession(['clubunio_context.'.$this->office->id => $this->contexts[$this->office->id]]);
        $data = ['context' => $this->contexts[$this->office->id]];
        $this->getJson('https://web-chat-bound.invalid/chat-data/list?'.http_build_query($data))->assertOk();
        $this->postJson('https://web-chat-bound.invalid/chat-data/direct', $data + ['account_id' => $this->admin->id])->assertOk();
    }

    public function test_sender_outbox_replay_mute_revocation_and_rollback_use_synthetic_bindings_only(): void
    {
        $chat = $this->direct();
        $this->syntheticBinding($this->office);
        $recipient = $this->syntheticBinding($this->admin);
        config(['clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => false]);
        $outbox = fn () => $this->db->table('clubunio.mobile_push_outbox')->where('tenant_id', $this->tenant->id);
        $input = ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic private text never in outbox'];
        $message = $this->chatRequest($this->office, 'send', $input, true)->assertOk()->json('data');
        $this->chatRequest($this->office, 'send', $input, true)->assertOk()->assertJsonPath('data.id', $message['id']);
        $this->assertSame(1, $outbox()->count());
        $row = $outbox()->first();
        $this->assertSame($recipient, $row->binding_id);
        $this->assertSame('pending', $row->state);
        $this->assertStringNotContainsString($input['text'], json_encode($row, JSON_THROW_ON_ERROR));
        $this->chatRequest($this->office, 'send', array_replace($input, ['text' => 'Conflict']), true)->assertConflict();
        $this->assertSame(1, $outbox()->count());
        $rollbackClient = (string) Str::uuid();
        $this->db->beginTransaction();
        try {
            $this->chatRequest($this->office, 'send', array_replace($input, ['client_id' => $rollbackClient]), true)->assertOk();
            $this->assertSame(2, $outbox()->count());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame(1, $outbox()->count());
        $this->assertFalse($this->db->table('clubunio.chat_messages')->where('tenant_id', $this->tenant->id)->where('client_id', $rollbackClient)->exists());
        $this->chatRequest($this->admin, 'mute', ['chat_id' => $chat, 'muted' => true], true)->assertOk();
        $this->chatRequest($this->office, 'send', array_replace($input, ['client_id' => (string) Str::uuid()]), true)->assertOk();
        $this->assertSame(1, $outbox()->count());
        $this->chatRequest($this->admin, 'mute', ['chat_id' => $chat, 'muted' => false], true)->assertOk();
        $this->db->table('clubunio.mobile_push_bindings')->where('id', $recipient)->update(['revoked_at' => now()->toIso8601String()]);
        $this->chatRequest($this->office, 'send', array_replace($input, ['client_id' => (string) Str::uuid()]), true)->assertOk();
        $this->assertSame(1, $outbox()->count());
    }
}
