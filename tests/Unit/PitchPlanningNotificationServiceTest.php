<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\MobilePushEnvelope;
use App\ClubUnio\Mobile\MobilePushOutbox;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\PitchPlanningNotificationService;
use App\ClubUnio\Services\PitchPlanningProjection;
use App\ClubUnio\Services\PitchPlanningService;
use App\ClubUnio\Services\TestMailGuard;
use App\ClubUnio\Services\TestOutboxDispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** In-memory source/queue integration. Never connects to the configured ClubUnio database. */
class PitchPlanningNotificationServiceTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => false,
            'clubunio-integrations.test_mail_enabled' => false, 'clubunio-integrations.pitch_planning_test_mail_enabled' => false,
            'clubunio-languages.enabled' => ['de' => 'Deutsch', 'en' => 'English'], 'clubunio-languages.default' => 'de']);
        DB::purge('clubunio');
        $this->db = DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach ([
            'tenants' => 'id integer primary key, name text, default_locale text',
            'tenant_settings' => 'tenant_id integer, area text, key text, enabled boolean, encrypted_value text',
            'accounts' => 'id integer primary key, username text, preferred_locale text, active boolean, api_only boolean, display_name text',
            'tenant_accounts' => 'tenant_id integer, account_id integer, role text, portal_enabled boolean, unique(tenant_id,account_id)',
            'members' => 'id integer primary key, tenant_id integer, archived_at text, exit_date text, status_id integer',
            'member_statuses' => 'id integer primary key, tenant_id integer, ends_membership boolean',
            'team_seasons' => 'id integer primary key, tenant_id integer, label text',
            'training_options' => 'id integer primary key, tenant_id integer, category text, name text',
            'lookup_translations' => 'id integer primary key, tenant_id integer, source_table text, source_id text, source_name text, locale text, label text',
            'team_staff' => 'tenant_id integer, team_season_id integer, member_id integer, active boolean, staff_function_id integer',
            'account_member_links' => 'tenant_id integer, account_id integer, member_id integer, kind text, state text',
            'message_templates' => 'id integer primary key autoincrement, tenant_id integer, event_key text, channel text, locale text, title text, body text, active boolean, revision integer, created_at text, updated_at text, unique(tenant_id,event_key,channel,locale)',
            'message_template_revisions' => 'id integer primary key autoincrement, tenant_id integer, template_id integer, revision integer, title text, body text, active boolean, actor_id integer, created_at text',
            'pitch_planning_events' => 'id integer primary key autoincrement, tenant_id integer, event_key text, fingerprint text, action text, actor_id integer, requester_account_id integer, team_season_ids text, source_key text, source_date text, payload text, notifications_recorded_at text, payload_encrypted text, delivery_state text, created_at text, finished_at text, error_code text, unique(tenant_id,event_key)',
            'notifications' => 'id integer primary key autoincrement, tenant_id integer, account_id integer, object_type text, object_id integer, title text, body text, created_at text, read_at text',
            'mobile_inbox' => 'id integer primary key autoincrement, tenant_id integer, account_id integer, kind text, object_id integer, event_key text, created_at text, read_at text, unique(tenant_id,account_id,event_key)',
            'mobile_sessions' => 'id text primary key, account_id integer, revoked_at text, expires_at text',
            'mobile_push_devices' => 'id text primary key, revision integer, revoked_at text, fixed_tenant_id integer',
            'mobile_push_bindings' => 'id text primary key, device_id text, account_id integer, session_id text, revision integer, revoked_at text, created_at text',
            'mobile_push_outbox' => "id text primary key, binding_id text, account_id integer, device_id text, tenant_id integer, kind text, object_id integer, event_key text, device_revision integer, binding_revision integer, created_at text, state text default 'pending', unique(binding_id,tenant_id,event_key)",
            'synthetic_source' => 'id integer primary key, revision integer',
        ] as $table => $columns) {
            $this->db->statement("CREATE TABLE clubora.$table ($columns)");
        }
        $this->db->table('clubora.tenants')->insert(['id' => 1, 'name' => 'Synthetic Club', 'default_locale' => 'de']);
        $this->tenant = Tenant::findOrFail(1);
        $this->db->table('clubora.training_options')->insert([
            ['id' => 1, 'tenant_id' => 1, 'category' => 'field', 'name' => 'Hauptplatz'],
            ['id' => 2, 'tenant_id' => 1, 'category' => 'field', 'name' => 'Kunstrasen'],
        ]);
        $this->db->table('clubora.lookup_translations')->insert([
            ['id' => 1, 'tenant_id' => 1, 'source_table' => 'training_options', 'source_id' => '1', 'source_name' => 'Hauptplatz', 'locale' => 'en', 'label' => 'Main pitch'],
            ['id' => 2, 'tenant_id' => 1, 'source_table' => 'training_options', 'source_id' => '2', 'source_name' => 'Kunstrasen', 'locale' => 'en', 'label' => 'Artificial pitch'],
        ]);
        $this->db->table('clubora.team_seasons')->insert([
            ['id' => 11, 'tenant_id' => 1, 'label' => 'Team A'], ['id' => 12, 'tenant_id' => 1, 'label' => 'Team B'],
            ['id' => 21, 'tenant_id' => 2, 'label' => 'Foreign team'],
        ]);
        // General mobile-entry policy has separate tests; exercise real target policy and real enqueue here.
        $this->partialMock(MemberAccessService::class, fn ($mock) => $mock->shouldReceive('canEnter')->andReturn(true));
        app(MessageTemplateService::class)->seed($this->tenant);
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        DB::purge('clubunio');
        parent::tearDown();
    }

    private function account(int $id, string $role = 'trainer', string $locale = 'en', int $tenant = 1, array $overrides = []): Account
    {
        $this->db->table('clubora.accounts')->insert(array_replace(['id' => $id, 'username' => "person$id@example.invalid",
            'preferred_locale' => $locale, 'active' => true, 'api_only' => false, 'display_name' => "Person $id"], $overrides));
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant, 'account_id' => $id, 'role' => $role, 'portal_enabled' => true]);
        $this->db->table('clubora.mobile_sessions')->insert(['id' => "session-$id", 'account_id' => $id, 'expires_at' => now()->addDay()->toIso8601String()]);
        $this->db->table('clubora.mobile_push_devices')->insert(['id' => "device-$id", 'revision' => 1]);
        $this->db->table('clubora.mobile_push_bindings')->insert(['id' => "binding-$id", 'device_id' => "device-$id", 'account_id' => $id,
            'session_id' => "session-$id", 'revision' => 1, 'created_at' => now()->subMinute()->toIso8601String()]);

        return Account::findOrFail($id);
    }

    private function staff(int $account, int $team, array $member = [], array $assignment = [], array $link = []): void
    {
        if (! $this->db->table('clubora.members')->where('id', $account)->exists()) {
            $this->db->table('clubora.members')->insert(array_replace(['id' => $account, 'tenant_id' => 1], $member));
        }
        $this->db->table('clubora.team_staff')->insert(array_replace(['tenant_id' => 1, 'team_season_id' => $team, 'member_id' => $account, 'active' => true, 'staff_function_id' => 99], $assignment));
        $this->db->table('clubora.account_member_links')->insert(array_replace(['tenant_id' => 1, 'account_id' => $account, 'member_id' => $account, 'kind' => 'self', 'state' => 'approved'], $link));
    }

    private function event(string $action = 'moved'): array
    {
        return ['event_key' => 'request:7:revision:2:'.$action, 'action' => $action, 'actor_id' => 1,
            'requester_account_id' => 3, 'team_season_ids' => [12, 11, 12], 'before' => null,
            'after' => ['key' => 'request:7', 'source' => 'request', 'source_id' => 7, 'date' => '2026-11-05',
                'starts_at' => '18:00', 'ends_at' => '20:00', 'duration_minutes' => 120, 'field_id' => 2,
                'area_id' => null, 'status' => 'approved', 'revision' => 'revision-2']];
    }

    private function record(array $event): void
    {
        $this->db->transaction(fn () => app(PitchPlanningNotificationService::class)->record($this->tenant, $event));
    }

    private function ids(string $table): array
    {
        return $this->db->table('clubora.'.$table)->orderBy('account_id')->pluck('account_id')->all();
    }

    public function test_only_new_requests_email_active_admin_office_in_recipient_language(): void
    {
        $this->account(1, 'admin', 'de');
        $this->account(2, 'office');
        $this->account(3);
        $this->account(4, 'admin', 'en', 2);
        $this->account(5, 'admin', 'en', 1, ['active' => false]);
        $this->account(6, 'office', 'en', 1, ['api_only' => true]);
        $this->account(7, 'office', 'en', 1, ['username' => 'PERSON2@example.invalid']);
        $this->record($this->event('requested'));
        $this->assertSame([1, 2, 7], $this->ids('notifications'));
        $this->assertSame([1, 2, 7], $this->ids('mobile_inbox'));
        $this->assertSame([1, 2, 7], $this->ids('mobile_push_outbox'));
        $row = $this->db->table('clubora.pitch_planning_events')->first();
        $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $payload['deliveries']);
        $this->assertSame(['de-CH', 'en-GB'], array_column($payload['deliveries'], 'locale'));
        $this->assertSame(['person1@example.invalid', 'person2@example.invalid'], array_column($payload['deliveries'], 'intended_to'));
        $this->assertSame('ready', $row->delivery_state);
        $this->assertSame('Neue Testspielanfrage', $this->db->table('clubora.notifications')->where('account_id', 1)->value('title'));
        $this->assertSame('New friendly match request', $this->db->table('clubora.notifications')->where('account_id', 2)->value('title'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_moves_include_all_staff_functions_of_both_teams_once_but_not_parents_or_inactive_links(): void
    {
        foreach (range(1, 10) as $id) {
            $this->account($id);
        }
        $this->staff(1, 11);
        $this->staff(1, 12);
        $this->staff(2, 12);
        $this->staff(3, 11, [], ['active' => false]);
        $this->staff(4, 11, [], [], ['kind' => 'parent']);
        $this->staff(5, 11, ['archived_at' => now()->toIso8601String()]);
        $this->staff(6, 11, ['exit_date' => now()->subDay()->toDateString()]);
        $this->staff(7, 11, [], [], ['state' => 'pending']);
        $this->staff(8, 21);
        $this->staff(9, 11, [], ['tenant_id' => 2]);
        $this->staff(10, 12, ['status_id' => 1]);
        $this->db->table('clubora.member_statuses')->insert(['id' => 1, 'tenant_id' => 1, 'ends_membership' => true]);
        foreach (['moved', 'sfv_moved'] as $action) {
            $this->record($this->event($action));
        }
        $this->assertSame([1, 1, 2, 2], $this->ids('notifications'));
        $this->assertSame([1, 1, 2, 2], $this->ids('mobile_push_outbox'));
        $this->assertSame(0, $this->db->table('clubora.pitch_planning_events')->whereNotNull('payload_encrypted')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_decisions_notify_only_the_requester_and_never_email(): void
    {
        $this->account(1, 'admin');
        $this->account(2, 'office');
        $this->account(3);
        $this->staff(2, 11);
        foreach (['approved', 'rejected'] as $action) {
            $this->record($this->event($action));
        }
        $this->assertSame([3, 3], $this->ids('notifications'));
        $this->assertSame([3, 3], $this->ids('mobile_push_outbox'));
        $this->assertSame(0, $this->db->table('clubora.pitch_planning_events')->whereNotNull('payload_encrypted')->count());
        Mail::assertNothingSent();
    }

    public function test_retries_are_idempotent_even_after_reading_or_ui_language_changes(): void
    {
        $this->account(1, 'admin');
        $event = $this->event('requested');
        $this->record($event);
        $this->db->table('clubora.notifications')->update(['read_at' => now()->toIso8601String()]);
        $event['after']['title'] = 'Another UI language';
        $event['team_season_ids'] = [11, 12];
        $this->record($event);
        foreach (['pitch_planning_events', 'notifications', 'mobile_inbox', 'mobile_push_outbox'] as $table) {
            $this->assertSame(1, $this->db->table('clubora.'.$table)->count());
        }
        $this->assertNotNull($this->db->table('clubora.notifications')->value('read_at'));
    }

    public function test_source_rollback_removes_all_notification_and_outbox_records(): void
    {
        $this->account(1, 'admin');
        $this->db->beginTransaction();
        $this->db->table('clubora.synthetic_source')->insert(['id' => 7, 'revision' => 2]);
        app(PitchPlanningNotificationService::class)->record($this->tenant, $this->event('requested'));
        $this->assertSame(1, $this->db->table('clubora.mobile_push_outbox')->count());
        $this->db->rollBack();
        foreach (['synthetic_source', 'pitch_planning_events', 'notifications', 'mobile_inbox', 'mobile_push_outbox'] as $table) {
            $this->assertSame(0, $this->db->table('clubora.'.$table)->count());
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_hook_rejects_calls_without_a_source_transaction(): void
    {
        $this->expectException(\LogicException::class);
        app(PitchPlanningNotificationService::class)->record($this->tenant, $this->event());
    }

    public function test_conflicting_reuse_of_event_identity_does_not_change_delivery_snapshot(): void
    {
        $this->account(1, 'admin');
        $event = $this->event('requested');
        $this->record($event);
        $original = $this->db->table('clubora.pitch_planning_events')->value('payload_encrypted');
        $event['after']['date'] = '2026-11-06';
        try {
            $this->record($event);
            $this->fail('Conflicting replay accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('reused', $e->getMessage());
        }
        $this->assertSame($original, $this->db->table('clubora.pitch_planning_events')->value('payload_encrypted'));
        $this->assertSame(1, $this->db->table('clubora.mobile_push_outbox')->count());
    }

    public function test_delivery_authorization_rechecks_staff_assignment_and_preserves_original_recipient_boundary(): void
    {
        $first = $this->account(1);
        $second = $this->account(2);
        $this->staff(1, 11);
        $this->record($this->event());
        $id = (int) $this->db->table('clubora.pitch_planning_events')->value('id');
        $this->assertSame(['date' => '2026-11-05', 'key' => 'request:7'], app(PitchPlanningNotificationService::class)->target($first, $this->tenant, $id));
        $this->assertTrue(app(MobilePushOutbox::class)->targetAllowed($first, $this->tenant, 'pitch_planning', $id));
        $this->staff(2, 11);
        $this->assertFalse(app(MobilePushOutbox::class)->targetAllowed($second, $this->tenant, 'pitch_planning', $id));
        $this->db->table('clubora.team_staff')->where('member_id', 1)->update(['active' => false]);
        $this->assertFalse(app(MobilePushOutbox::class)->targetAllowed($first, $this->tenant, 'pitch_planning', $id));
        $this->assertSame('pitch_planning', (new MobilePushEnvelope(1, 1, 'pitch_planning', $id, '00000000-0000-4000-8000-000000000001'))->data()['kind']);
    }

    public function test_push_opt_out_keeps_in_app_and_email_snapshot_but_does_not_queue_or_send(): void
    {
        $this->account(1, 'admin');
        config(['clubunio-mobile-push.enabled' => false]);
        $this->record($this->event('requested'));
        $this->assertSame([1], $this->ids('notifications'));
        $this->assertSame([1], $this->ids('mobile_inbox'));
        $this->assertSame(0, $this->db->table('clubora.mobile_push_outbox')->count());
        $this->assertFalse(app(MobilePushOutbox::class)->deliverOne());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_disabled_template_is_respected_without_cross_language_fallback(): void
    {
        $this->account(1, 'admin');
        $this->db->table('clubora.message_templates')->where('event_key', 'pitch.friendly.requested')
            ->where('locale', 'en-GB')->update(['active' => false]);
        $this->record($this->event('requested'));
        $this->assertSame(0, $this->db->table('clubora.notifications')->count());
        $this->assertSame(0, $this->db->table('clubora.mobile_push_outbox')->count());
        $this->assertNull($this->db->table('clubora.pitch_planning_events')->value('payload_encrypted'));
    }

    public function test_template_failure_rolls_back_source_and_partially_prepared_fanout(): void
    {
        $this->account(1, 'admin', 'de');
        $this->account(2, 'office');
        $this->db->table('clubora.message_templates')->where('event_key', 'pitch.friendly.requested')->where('channel', 'push')
            ->where('locale', 'en-GB')->update(['body' => '{{ unrecognized }}']);
        try {
            $this->db->transaction(function () {
                $this->db->table('clubora.synthetic_source')->insert(['id' => 7, 'revision' => 2]);
                app(PitchPlanningNotificationService::class)->record($this->tenant, $this->event('requested'));
            });
            $this->fail('Invalid template accepted');
        } catch (ValidationException) {
            foreach (['synthetic_source', 'pitch_planning_events', 'notifications', 'mobile_inbox', 'mobile_push_outbox'] as $table) {
                $this->assertSame(0, $this->db->table('clubora.'.$table)->count());
            }
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_email_and_push_template_switches_are_independent(): void
    {
        $this->account(1, 'admin');
        $this->db->table('clubora.message_templates')->where('event_key', 'pitch.friendly.requested')->where('channel', 'email')->update(['active' => false]);
        $this->record($this->event('requested'));
        $this->assertSame([1], $this->ids('mobile_push_outbox'));
        $this->assertNull($this->db->table('clubora.pitch_planning_events')->value('payload_encrypted'));
    }

    public function test_revoked_expired_new_or_foreign_devices_are_not_queued(): void
    {
        foreach (range(1, 5) as $id) {
            $this->account($id, 'admin');
        }
        $this->db->table('clubora.mobile_push_bindings')->where('account_id', 1)->update(['revoked_at' => now()->toIso8601String()]);
        $this->db->table('clubora.mobile_sessions')->where('account_id', 2)->update(['expires_at' => now()->subMinute()->toIso8601String()]);
        $this->db->table('clubora.mobile_push_devices')->where('id', 'device-3')->update(['fixed_tenant_id' => 2]);
        $this->db->table('clubora.mobile_push_bindings')->where('account_id', 4)->update(['created_at' => now()->addMinute()->toIso8601String()]);
        $this->record($this->event('requested'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids('notifications'));
        $this->assertSame([5], $this->ids('mobile_push_outbox'));
    }

    public function test_request_target_loses_access_when_office_role_is_revoked(): void
    {
        $actor = $this->account(1, 'office');
        $this->record($this->event('requested'));
        $id = (int) $this->db->table('clubora.pitch_planning_events')->value('id');
        $this->assertTrue(app(MobilePushOutbox::class)->targetAllowed($actor, $this->tenant, 'pitch_planning', $id));
        $this->db->table('clubora.tenant_accounts')->update(['role' => 'trainer']);
        $this->assertFalse(app(MobilePushOutbox::class)->targetAllowed($actor, $this->tenant, 'pitch_planning', $id));
        $foreign = new Tenant;
        $foreign->forceFill(['id' => 2, 'name' => 'Foreign']);
        $this->assertFalse(app(MobilePushOutbox::class)->targetAllowed($actor, $foreign, 'pitch_planning', $id));
    }

    public function test_move_shows_previous_and_new_field_date_and_time_in_each_recipient_language(): void
    {
        $this->account(1, 'trainer', 'de');
        $this->account(2, 'trainer', 'en');
        $this->staff(1, 11);
        $this->staff(2, 12);
        $event = $this->event();
        $event['before'] = array_replace($event['after'], ['field_id' => 1, 'date' => '2026-11-04', 'starts_at' => '17:00', 'ends_at' => '19:00', 'revision' => 'revision-1']);
        app()->setLocale('de');
        $this->record($event);
        $german = $this->db->table('clubora.notifications')->where('account_id', 1)->value('body');
        $english = $this->db->table('clubora.notifications')->where('account_id', 2)->value('body');
        $this->assertStringContainsString('Bisher: Hauptplatz, 2026-11-04, 17:00-19:00', $german);
        $this->assertStringContainsString('Neu: Kunstrasen, 2026-11-05, 18:00-20:00', $german);
        $this->assertStringContainsString('Previously: Main pitch, 2026-11-04, 17:00-19:00', $english);
        $this->assertStringContainsString('Now: Artificial pitch, 2026-11-05, 18:00-20:00', $english);
    }

    public function test_explicit_profile_staff_is_a_recipient_but_profile_role_alone_is_not(): void
    {
        $this->account(1, 'profile');
        $this->account(2, 'profile');
        $this->account(3, 'profile');
        $this->staff(1, 11);
        $this->staff(3, 11);
        $this->db->table('clubora.tenant_accounts')->where('account_id', 3)->update(['portal_enabled' => false]);
        $this->record($this->event());
        $this->assertSame([1], $this->ids('notifications'));
        $this->assertSame([1], $this->ids('mobile_inbox'));
        $this->assertSame([1], $this->ids('mobile_push_outbox'));
    }

    private function coreFixture(): PitchPlanningService
    {
        $this->db->statement('ALTER TABLE clubora.training_options ADD COLUMN active boolean default 1');
        $this->db->statement('ALTER TABLE clubora.team_seasons ADD COLUMN active boolean default 1');
        $this->db->statement('ALTER TABLE clubora.team_seasons ADD COLUMN team_id integer default 1');
        $this->db->statement('CREATE TABLE clubora.teams (id integer primary key, tenant_id integer, active boolean)');
        $this->db->table('clubora.teams')->insert(['id' => 1, 'tenant_id' => 1, 'active' => true]);
        $this->db->statement('CREATE TABLE clubora.pitch_requests (id integer primary key autoincrement, tenant_id integer, team_season_id integer,
            opponent text, notes text, field_id integer, area_id integer, date text, starts_at text, duration_minutes integer, requester_account_id integer,
            status text, revision integer, decided_by integer, created_at text, updated_at text)');
        $this->db->statement('CREATE TABLE clubora.object_changes (id integer primary key autoincrement, tenant_id integer, actor_id integer, object_type text, object_id integer, before_value text, after_value text)');
        $this->partialMock(PitchPlanningProjection::class, function ($mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('entries')->andReturn([]);
            $mock->shouldReceive('fields')->andReturn([['id' => 2, 'available' => true, 'areas' => []]]);
        });

        return app(PitchPlanningService::class);
    }

    public function test_real_core_request_and_decision_hook_records_transactional_notifications(): void
    {
        $actor = $this->account(1, 'admin');
        $this->account(2);
        $this->staff(1, 11);
        $this->staff(2, 11);
        $service = $this->coreFixture();
        $saved = $service->requestFriendly($actor, $this->tenant, ['team_season_id' => 11, 'opponent' => 'Synthetic opponent',
            'field_id' => 2, 'area_id' => null, 'date' => '2026-11-05', 'starts_at' => '18:00', 'duration_minutes' => 120]);
        $this->assertSame('pending', $saved['status']);
        $this->assertSame(1, $this->db->table('clubora.pitch_requests')->count());
        $this->assertSame('requested', $this->db->table('clubora.pitch_planning_events')->value('action'));
        $this->assertSame([1], $this->ids('mobile_push_outbox'));
        $saved = $service->move($actor, $this->tenant, $saved['key'], ['revision' => $saved['revision'],
            'field_id' => 2, 'area_id' => null, 'date' => '2026-11-06', 'starts_at' => '19:00', 'duration_minutes' => 120]);
        $this->assertSame('2026-11-06', $this->db->table('clubora.pitch_requests')->value('date'));
        $this->assertSame([1, 1, 2], $this->ids('mobile_push_outbox'));
        $this->assertStringContainsString('2026-11-05, 18:00-20:00', $this->db->table('clubora.notifications')->where('account_id', 2)->value('body'));
        $this->assertStringContainsString('2026-11-06, 19:00-21:00', $this->db->table('clubora.notifications')->where('account_id', 2)->value('body'));
        $service->decide($actor, $this->tenant, $saved['source_id'], ['revision' => $saved['revision'], 'decision' => 'rejected']);
        $this->assertSame('rejected', $this->db->table('clubora.pitch_requests')->value('status'));
        $this->assertSame([1, 1, 1, 2], $this->ids('mobile_push_outbox'));
        $this->assertSame(1, $this->db->table('clubora.pitch_planning_events')->whereNotNull('payload_encrypted')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_real_core_request_rolls_back_when_notification_template_is_invalid(): void
    {
        $actor = $this->account(1, 'admin');
        $service = $this->coreFixture();
        $this->db->table('clubora.message_templates')->where('event_key', 'pitch.friendly.requested')->update(['body' => '{{ invalid }}']);
        try {
            $service->requestFriendly($actor, $this->tenant, ['team_season_id' => 11, 'opponent' => 'Synthetic opponent',
                'field_id' => 2, 'area_id' => null, 'date' => '2026-11-05', 'starts_at' => '18:00', 'duration_minutes' => 120]);
            $this->fail('Invalid template accepted');
        } catch (ValidationException) {
            foreach (['pitch_requests', 'object_changes', 'pitch_planning_events', 'notifications', 'mobile_inbox', 'mobile_push_outbox'] as $table) {
                $this->assertSame(0, $this->db->table('clubora.'.$table)->count());
            }
        }
    }

    private function arrayMailTransport(): ArrayTransport
    {
        config(['clubunio-integrations.test_mail_enabled' => true,
            'clubunio-integrations.pitch_planning_test_mail_enabled' => true,
            'clubunio-integrations.from' => 'synthetic@example.invalid',
            'mail.mailers.clubunio_test' => ['transport' => 'array']]);
        Mail::swap(new MailManager($this->app));

        return Mail::mailer('clubunio_test')->getSymfonyTransport();
    }

    public function test_worker_sends_committed_localized_snapshots_only_to_guarded_test_recipient_once(): void
    {
        $this->db->table('clubora.tenant_settings')->insert([
            ['tenant_id' => 1, 'area' => 'mail', 'key' => 'MAIL_REPLY_TO', 'enabled' => true, 'encrypted_value' => Crypt::encryptString('club@example.invalid')],
            ['tenant_id' => 2, 'area' => 'mail', 'key' => 'MAIL_REPLY_TO', 'enabled' => true, 'encrypted_value' => Crypt::encryptString('other@example.invalid')],
        ]);
        $this->account(1, 'admin', 'de');
        $this->account(2, 'office');
        $this->record($this->event('requested'));
        $transport = $this->arrayMailTransport();
        Event::listen(MessageSending::class, function () {
            $this->assertSame(0, $this->db->transactionLevel(), 'Provider work must happen after commit');
            $this->assertSame('sending', $this->db->table('clubora.pitch_planning_events')->value('delivery_state'));
        });
        $service = app(PitchPlanningNotificationService::class);
        $this->assertSame(['accepted' => 1, 'failed' => 0], $service->dispatch(1));
        $this->assertSame(['accepted' => 0, 'failed' => 0], $service->dispatch(1));
        $this->assertCount(2, $transport->messages());
        foreach ($transport->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            $this->assertSame([TestMailGuard::RECIPIENT], array_map(fn ($address) => $address->getAddress(), $sent->getEnvelope()->getRecipients()));
            $this->assertSame(TestMailGuard::RECIPIENT, $message->getTo()[0]->getAddress());
            $this->assertSame('club@example.invalid', $message->getReplyTo()[0]->getAddress());
            $this->assertSame([], $message->getCc());
            $this->assertSame([], $message->getBcc());
            $this->assertStringStartsWith('[ClubUnio TEST] ', $message->getSubject());
        }
        $this->assertStringContainsString('Neue Testspielanfrage', $transport->messages()[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('New friendly match request', $transport->messages()[1]->getOriginalMessage()->getSubject());
        $this->assertSame('accepted', $this->db->table('clubora.pitch_planning_events')->value('delivery_state'));
        Http::assertNothingSent();
    }

    public function test_dispatcher_and_command_cannot_bypass_either_flag_or_environment(): void
    {
        $this->account(1, 'admin');
        $this->record($this->event('requested'));
        $transport = $this->arrayMailTransport();
        foreach ([[false, true], [true, false], [false, false]] as [$general, $pitch]) {
            config(['clubunio-integrations.test_mail_enabled' => $general, 'clubunio-integrations.pitch_planning_test_mail_enabled' => $pitch]);
            $this->assertSame(['accepted' => 0, 'failed' => 0], app(TestOutboxDispatcher::class)->dispatch('pitch_planning_events'));
            $this->artisan('clubunio:deliver-pitch-mail', ['--dispatch' => true, '--tenant' => 1])->assertExitCode(1);
        }
        config(['clubunio-integrations.test_mail_enabled' => true, 'clubunio-integrations.pitch_planning_test_mail_enabled' => true]);
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->assertSame(['accepted' => 0, 'failed' => 0], app(TestOutboxDispatcher::class)->dispatch('pitch_planning_events'));
            $this->artisan('clubunio:deliver-pitch-mail', ['--dispatch' => true, '--tenant' => 1])->assertExitCode(1);
        } finally {
            $this->app['env'] = $environment;
        }
        $this->assertSame('ready', $this->db->table('clubora.pitch_planning_events')->value('delivery_state'));
        $this->assertCount(0, $transport->messages());
    }

    public function test_command_requires_explicit_dispatch_and_valid_bounded_scope(): void
    {
        $this->account(1, 'admin');
        $this->record($this->event('requested'));
        $transport = $this->arrayMailTransport();
        $this->artisan('clubunio:deliver-pitch-mail', ['--tenant' => 1])->assertExitCode(1);
        foreach ([[], ['--tenant' => 0], ['--tenant' => 1, '--event' => 0], ['--tenant' => 1, '--limit' => 51]] as $arguments) {
            $this->artisan('clubunio:deliver-pitch-mail', ['--dispatch' => true] + $arguments)->assertExitCode(2);
        }
        $this->assertCount(0, $transport->messages());
        $this->artisan('clubunio:deliver-pitch-mail', ['--dispatch' => true, '--tenant' => 1, '--limit' => 1])->assertExitCode(0);
        $this->assertCount(1, $transport->messages());
    }

    public function test_worker_scopes_held_requests_and_never_selects_other_actions_or_incomplete_events(): void
    {
        $this->account(1, 'admin');
        foreach (range(1, 5) as $number) {
            $event = $this->event('requested');
            $event['event_key'] .= ':'.$number;
            $this->record($event);
        }
        $rows = $this->db->table('clubora.pitch_planning_events');
        (clone $rows)->where('id', 1)->update(['tenant_id' => 2]);
        (clone $rows)->where('id', 2)->update(['action' => 'approved']);
        (clone $rows)->where('id', 3)->update(['notifications_recorded_at' => null]);
        (clone $rows)->where('id', 4)->update(['delivery_state' => 'held']);
        $transport = $this->arrayMailTransport();
        $service = app(PitchPlanningNotificationService::class);
        $this->assertSame(['accepted' => 0, 'failed' => 0], $service->dispatch(1, 20, 1));
        $this->assertSame(['accepted' => 1, 'failed' => 0], $service->dispatch(1, 1));
        $this->assertSame('accepted', (clone $rows)->where('id', 4)->value('delivery_state'));
        $this->assertSame('ready', (clone $rows)->where('id', 5)->value('delivery_state'));
        $this->assertSame(['accepted' => 1, 'failed' => 0], $service->dispatch(1, 20, 5));
        $this->assertSame(['accepted' => 0, 'failed' => 0], $service->dispatch(1));
        $this->assertCount(2, $transport->messages());
    }

    public function test_worker_rejects_uncommitted_source_work(): void
    {
        $this->account(1, 'admin');
        $transport = $this->arrayMailTransport();
        $this->db->beginTransaction();
        app(PitchPlanningNotificationService::class)->record($this->tenant, $this->event('requested'));
        try {
            app(PitchPlanningNotificationService::class)->dispatch(1);
            $this->fail('Worker accepted an open source transaction');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('committed transaction', $error->getMessage());
        } finally {
            $this->db->rollBack();
        }
        $this->assertCount(0, $transport->messages());
        $this->assertSame(0, $this->db->table('clubora.pitch_planning_events')->count());
    }

    public function test_ambiguous_partial_send_requires_attention_and_is_never_replayed(): void
    {
        $this->account(1, 'admin');
        $this->account(2, 'office');
        $this->record($this->event('requested'));
        config(['clubunio-integrations.test_mail_enabled' => true, 'clubunio-integrations.pitch_planning_test_mail_enabled' => true]);
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('send')->once()->ordered()->andReturn(new \stdClass);
        $mailer->shouldReceive('send')->once()->ordered()->andThrow(new \RuntimeException('Synthetic SMTP outcome unknown'));
        Mail::shouldReceive('mailer')->twice()->with('clubunio_test')->andReturn($mailer);
        $this->artisan('clubunio:deliver-pitch-mail', ['--dispatch' => true, '--tenant' => 1])->assertExitCode(1);
        $this->assertSame('attention', $this->db->table('clubora.pitch_planning_events')->value('delivery_state'));
        $this->assertSame('SMTP_SEND_FAILED', $this->db->table('clubora.pitch_planning_events')->value('error_code'));
        $this->assertSame(['accepted' => 0, 'failed' => 0], app(PitchPlanningNotificationService::class)->dispatch(1));
        $this->record($this->event('requested'));
        $this->assertSame('attention', $this->db->table('clubora.pitch_planning_events')->value('delivery_state'));
    }

    public function test_interrupted_sending_and_invalid_cipher_are_never_automatically_replayed(): void
    {
        $this->account(1, 'admin');
        $event = $this->event('requested');
        $this->record($event);
        $event['event_key'] .= ':second';
        $this->record($event);
        $this->db->table('clubora.pitch_planning_events')->where('id', 1)->update(['delivery_state' => 'sending']);
        $this->db->table('clubora.pitch_planning_events')->where('id', 2)->update(['payload_encrypted' => 'invalid']);
        $transport = $this->arrayMailTransport();
        $this->assertSame(['accepted' => 0, 'failed' => 1], app(PitchPlanningNotificationService::class)->dispatch(1));
        $this->assertSame(['sending', 'attention'], $this->db->table('clubora.pitch_planning_events')->orderBy('id')->pluck('delivery_state')->all());
        $this->assertSame(['accepted' => 0, 'failed' => 0], app(PitchPlanningNotificationService::class)->dispatch(1));
        $this->assertCount(0, $transport->messages());
    }

    public function test_rejected_transport_result_requires_review_without_replay(): void
    {
        $this->account(1, 'admin');
        $this->record($this->event('requested'));
        config(['clubunio-integrations.test_mail_enabled' => true, 'clubunio-integrations.pitch_planning_test_mail_enabled' => true]);
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('send')->once()->andReturnNull();
        Mail::shouldReceive('mailer')->once()->with('clubunio_test')->andReturn($mailer);
        $service = app(PitchPlanningNotificationService::class);
        $this->assertSame(['accepted' => 0, 'failed' => 1], $service->dispatch(1));
        $this->assertSame('attention', $this->db->table('clubora.pitch_planning_events')->value('delivery_state'));
        $this->assertSame(['accepted' => 0, 'failed' => 0], $service->dispatch(1));
    }

    public function test_created_and_cancelled_notify_staff_plus_requester_once_without_email(): void
    {
        $this->account(1, 'admin');
        $this->account(2, 'trainer', 'de');
        $this->account(3);
        $this->account(4);
        $this->staff(2, 11);
        $this->staff(3, 11);
        $this->staff(3, 12);
        $this->staff(4, 12, [], ['active' => false]);
        foreach (['created', 'cancelled'] as $action) {
            $event = $this->event($action);
            $event['after']['status'] = $action === 'created' ? 'approved' : 'cancelled';
            $this->record($event);
            $this->record($event);
        }
        $this->assertSame([2, 2, 3, 3], $this->ids('notifications'));
        $this->assertSame([2, 2, 3, 3], $this->ids('mobile_inbox'));
        $this->assertSame([2, 2, 3, 3], $this->ids('mobile_push_outbox'));
        $this->assertSame(0, $this->db->table('clubora.pitch_planning_events')->whereNotNull('payload_encrypted')->count());
        $this->assertSame(['held', 'held'], $this->db->table('clubora.pitch_planning_events')->orderBy('id')->pluck('delivery_state')->all());
        $this->assertSame(['Neue Platzbelegung', 'Platzbelegung abgesagt'], $this->db->table('clubora.notifications')->where('account_id', 2)->orderBy('id')->pluck('title')->all());
        $this->assertSame(['New pitch booking', 'Pitch booking cancelled'], $this->db->table('clubora.notifications')->where('account_id', 3)->orderBy('id')->pluck('title')->all());
        $transport = $this->arrayMailTransport();
        $this->assertSame(['accepted' => 0, 'failed' => 0], app(PitchPlanningNotificationService::class)->dispatch(1));
        $this->assertCount(0, $transport->messages());
        Http::assertNothingSent();
    }

    public function test_created_requires_team_ids_but_cancelled_can_notify_requester_without_staff(): void
    {
        $actor = $this->account(3);
        $event = $this->event('created');
        $event['team_season_ids'] = [];
        $this->record($event);
        $this->assertSame([], $this->ids('notifications'));
        $event = $this->event('cancelled');
        $event['team_season_ids'] = [];
        $event['after']['status'] = 'cancelled';
        $this->record($event);
        $this->assertSame([3], $this->ids('notifications'));
        $this->assertSame([3], $this->ids('mobile_push_outbox'));
        $id = (int) $this->db->table('clubora.pitch_planning_events')->where('action', 'cancelled')->value('id');
        $this->assertTrue(app(MobilePushOutbox::class)->targetAllowed($actor, $this->tenant, 'pitch_planning', $id));
        $this->db->table('clubora.accounts')->where('id', 3)->update(['active' => false]);
        $this->assertFalse(app(MobilePushOutbox::class)->targetAllowed($actor, $this->tenant, 'pitch_planning', $id));
    }

    public function test_status_fanout_never_escapes_active_tenant_scope_through_requester_union(): void
    {
        $this->account(2);
        $this->staff(2, 11);
        $this->account(3, 'trainer', 'en', 2);
        $this->account(4, 'trainer', 'en', 1, ['active' => false]);
        $this->account(5, 'trainer', 'en', 1, ['api_only' => true]);
        foreach ([3, 4, 5] as $requester) {
            $event = $this->event('cancelled');
            $event['event_key'] .= ':'.$requester;
            $event['requester_account_id'] = $requester;
            $this->record($event);
        }
        $this->assertSame([2, 2, 2], $this->ids('notifications'));
        $this->assertSame([2, 2, 2], $this->ids('mobile_push_outbox'));
    }

    public function test_real_core_manual_creation_and_cancellation_notify_staff_and_requester(): void
    {
        $actor = $this->account(1, 'admin');
        $this->account(2);
        $this->staff(1, 11);
        $this->staff(2, 11);
        $service = $this->coreFixture();
        $saved = $service->createManual($actor, $this->tenant, ['team_season_id' => 11, 'opponent' => 'Synthetic manual',
            'field_id' => 2, 'area_id' => null, 'date' => '2026-11-05', 'starts_at' => '18:00', 'duration_minutes' => 120]);
        $this->assertSame('approved', $saved['status']);
        $this->assertSame([1, 2], $this->ids('mobile_push_outbox'));
        $cancelled = $service->cancel($actor, $this->tenant, $saved['key'], ['revision' => $saved['revision']]);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('cancelled', $this->db->table('clubora.pitch_requests')->value('status'));
        $this->assertSame([1, 1, 2, 2], $this->ids('mobile_push_outbox'));
        $this->assertSame(['created', 'cancelled'], $this->db->table('clubora.pitch_planning_events')->orderBy('id')->pluck('action')->all());
        try {
            $service->cancel($actor, $this->tenant, $cancelled['key'], ['revision' => $cancelled['revision']]);
            $this->fail('Repeated cancellation accepted');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(2, $this->db->table('clubora.pitch_planning_events')->count());
        $this->assertSame(0, $this->db->table('clubora.pitch_planning_events')->whereNotNull('payload_encrypted')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
