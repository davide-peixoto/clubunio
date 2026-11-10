<?php
namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileMiddleware, MobilePushRoutes, MobilePushOutbox};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Crypt, Http, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobilePushDevicesTest extends TestCase
{
    private $db;
    private $grammar;
    private Tenant $tenant;
    private Account $first, $second;
    private string $firstToken, $secondToken, $installation;
    private ?string $credentialFile = null;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        $this->db = DB::connection('clubunio');
        self::assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        self::assertSame('pgsql', $this->db->getDriverName());
        $this->db->beginTransaction(); Http::preventStrayRequests();
        $this->withServerVariables(['HTTP_HOST' => 'synthetic.example', 'SERVER_NAME' => 'synthetic.example', 'HTTPS' => 'on', 'SERVER_PORT' => 443]);
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile-push.enabled' => true, 'cache.default' => 'array',
            'app.url' => 'https://synthetic.example', 'clubunio-mobile-push.apps' => ['ch.clubunio.synthetic' => [
                'platform' => 'android', 'origin' => 'https://synthetic.example', 'fixed_tenant_id' => null]]]);
        Route::prefix('api/mobile/v1')->middleware(MobileMiddleware::class)->group(fn () => MobilePushRoutes::registerAccount());
        Route::prefix('api/mobile/v1/clubs/{tenant}')->middleware(MobileMiddleware::class)->group(fn () => MobilePushRoutes::registerTenant());
        $this->tenant = Tenant::create(['slug' => 'push-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic push']);
        $this->first = $this->account(); $this->second = $this->account();
        foreach (['accounts', 'tenants', 'tenant_accounts', 'team_events'] as $name) {
            $this->db->statement('CREATE TEMP TABLE push_'.$name.' (LIKE clubunio.'.$name.' INCLUDING ALL) ON COMMIT DROP');
        }
        $this->db->statement('INSERT INTO pg_temp.push_accounts SELECT * FROM clubunio.accounts WHERE id IN (?,?)', [$this->first->id, $this->second->id]);
        $this->db->statement('INSERT INTO pg_temp.push_tenants SELECT * FROM clubunio.tenants WHERE id=?', [$this->tenant->id]);
        $this->db->statement('INSERT INTO pg_temp.push_tenant_accounts SELECT * FROM clubunio.tenant_accounts WHERE tenant_id=?', [$this->tenant->id]);
        $this->db->statement('CREATE TEMP TABLE mobile_sessions (LIKE clubunio.mobile_sessions INCLUDING ALL) ON COMMIT DROP');
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_000500_mobile_push_devices.php'))->up());
        $sql = str_replace(['CREATE TABLE clubunio.mobile_push_', 'clubunio.mobile_push_', 'clubunio.mobile_sessions'],
            ['CREATE TEMP TABLE mobile_push_', 'pg_temp.mobile_push_', 'pg_temp.mobile_sessions'], $ddl[0]['query']);
        foreach (['accounts', 'tenants', 'tenant_accounts', 'team_events'] as $name) $sql = str_replace('REFERENCES clubunio.'.$name.'(', 'REFERENCES pg_temp.push_'.$name.'(', $sql);
        $this->db->unprepared($sql);
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) {
                $result = parent::wrapTable($table, $prefix);
                foreach (['mobile_sessions', 'mobile_push_devices', 'mobile_push_bindings', 'mobile_push_outbox', 'mobile_push_commands'] as $name) {
                    $result = str_replace('"clubunio"."'.$name.'"', '"pg_temp"."'.$name.'"', $result);
                }
                return $result;
            }
        });
        $this->firstToken = $this->token($this->first); $this->secondToken = $this->token($this->second);
        $this->installation = (string) Str::uuid();
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) { while ($this->db->transactionLevel()) $this->db->rollBack(); if ($this->grammar) $this->db->setQueryGrammar($this->grammar); }
        if ($this->credentialFile) unlink($this->credentialFile);
        parent::tearDown();
    }
    private function account(): Account
    {
        $a = new Account; $a->forceFill(['username' => 'synthetic-push-'.bin2hex(random_bytes(6)), 'display_name' => 'Synthetic',
            'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $a->id, 'role' => 'admin', 'portal_enabled' => true]);
        return $a;
    }
    private function token(Account $a): string
    {
        return $this->postJson('/api/mobile/v1/session', ['username' => $a->username, 'password' => 'fixture', 'device_name' => 'Synthetic push'])->assertOk()->json('data.token');
    }
    private function data(array $changes = []): array
    {
        return array_replace(['installation_id' => $this->installation, 'installation_secret' => str_repeat('a', 64), 'app_id' => 'ch.clubunio.synthetic',
            'platform' => 'android', 'token' => str_repeat('synthetic-token', 4), 'revision' => 0], $changes);
    }
    public function test_two_accounts_bind_one_install_but_logout_only_revokes_own_binding(): void
    {
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk()->assertJsonPath('data.revision', 1);
        $this->withToken($this->secondToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1]))->assertOk();
        self::assertSame(2, $this->db->table('clubunio.mobile_push_bindings')->count());
        $this->withToken($this->firstToken)->deleteJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk();
        self::assertNotNull($this->db->table('clubunio.mobile_push_bindings')->where('account_id', $this->first->id)->value('revoked_at'));
        self::assertNull($this->db->table('clubunio.mobile_push_bindings')->where('account_id', $this->second->id)->value('revoked_at'));
        Http::assertNothingSent();
    }
    public function test_install_proof_token_revision_and_registry_boundaries_are_enforced(): void
    {
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk();
        $this->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1, 'installation_secret' => str_repeat('b', 64)]))->assertForbidden();
        $this->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1, 'app_id' => 'foreign.app']))->assertForbidden();
        $this->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1, 'token' => str_repeat('rotated-token', 4)]))->assertOk()->assertJsonPath('data.revision', 2);
        $this->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1]))->assertConflict();
        $device = $this->db->table('clubunio.mobile_push_devices')->first();
        self::assertNotSame(str_repeat('rotated-token', 4), $device->token_cipher);
        self::assertSame(str_repeat('rotated-token', 4), Crypt::decryptString($device->token_cipher));
        Http::assertNothingSent();
    }
    public function test_session_account_composite_fk_rejects_cross_account_binding(): void
    {
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk();
        $this->db->beginTransaction();
        try {
            $this->db->table('clubunio.mobile_push_bindings')->insert(['id' => (string) Str::uuid(), 'device_id' => $this->installation,
                'account_id' => $this->second->id, 'session_id' => explode('.', $this->firstToken)[0]]);
            self::fail('Cross-account session binding accepted');
        } catch (\Illuminate\Database\QueryException $e) { self::assertSame('23503', $e->getCode()); }
        finally { $this->db->rollBack(); }
    }
    public function test_closed_feature_does_not_write_or_send(): void
    {
        config(['clubunio-mobile-push.enabled' => false]);
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertStatus(503);
        self::assertSame(0, $this->db->table('clubunio.mobile_push_devices')->count());
        self::assertFalse(app(MobilePushOutbox::class)->deliverOne()); Http::assertNothingSent();
    }
    private function queue(): void
    {
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk();
        $chat = app(\App\ClubUnio\Mobile\ChatService::class)->ensure($this->tenant, 'announcements');
        $outbox = app(MobilePushOutbox::class);
        self::assertSame(1, $outbox->enqueue($this->first, $this->tenant, 'announcement', (int) $chat->id, 'announcement:synthetic'));
        self::assertSame(0, $outbox->enqueue($this->first, $this->tenant, 'announcement', (int) $chat->id, 'announcement:synthetic'));
        config(['clubunio-mobile-push.delivery_enabled' => true]);
    }
    public function test_rotation_invalidates_queued_deliveries_before_http(): void
    {
        $this->queue();
        $this->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1, 'token' => str_repeat('rotated-token', 4)]))->assertOk();
        self::assertTrue(app(MobilePushOutbox::class)->deliverOne());
        self::assertSame('authorization_changed', $this->db->table('clubunio.mobile_push_outbox')->value('outcome_code'));
        Http::assertNothingSent();
    }
    public function test_logout_session_and_membership_revocation_block_delivery(): void
    {
        $this->queue();
        $this->db->table('clubunio.mobile_sessions')->where('id', explode('.', $this->firstToken)[0])->update(['revoked_at' => now()->toIso8601String()]);
        self::assertTrue(app(MobilePushOutbox::class)->deliverOne());
        self::assertSame('discarded', $this->db->table('clubunio.mobile_push_outbox')->value('state'));
        Http::assertNothingSent();
    }
    public function test_uncertain_claim_is_attention_and_never_requeued(): void
    {
        $this->queue();
        $this->db->table('clubunio.mobile_push_outbox')->update(['state' => 'sending', 'lease_until' => now()->subMinute()->toIso8601String()]);
        self::assertFalse(app(MobilePushOutbox::class)->deliverOne());
        self::assertSame('attention', $this->db->table('clubunio.mobile_push_outbox')->value('state'));
        self::assertFalse(app(MobilePushOutbox::class)->deliverOne()); Http::assertNothingSent();
    }
    public function test_custom_registry_cannot_claim_central_origin(): void
    {
        config(['clubunio-mobile-push.apps.ch.clubunio.synthetic' => null]);
        config(['clubunio-mobile-push.apps' => ['ch.clubunio.synthetic' => ['platform' => 'android',
            'origin' => 'https://synthetic.example', 'fixed_tenant_id' => (int) $this->tenant->id]]]);
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertForbidden();
        self::assertSame(0, $this->db->table('clubunio.mobile_push_devices')->count()); Http::assertNothingSent();
    }
    private function squadFixture(): int
    {
        $insert = fn ($table, $values) => $this->db->table('clubunio.'.$table)->insertGetId($values + ['tenant_id' => $this->tenant->id]);
        $season = $insert('seasons', ['name' => 'Synthetic push season']);
        $insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic', 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString((string) $season)]);
        $team = $insert('team_seasons', ['team_id' => $insert('teams', ['name' => 'Synthetic push team']), 'season_id' => $season, 'label' => 'Synthetic']);
        $child = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'PRIVATE CHILD', 'birth_date' => now()->subYears(10)->toDateString()]);
        $insert('team_players', ['team_season_id' => $team, 'member_id' => $child]);
        $insert('account_member_links', ['account_id' => $this->second->id, 'member_id' => $child, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->second->id)->update(['role' => 'member']);
        $event = $insert('team_events', ['team_season_id' => $team, 'title' => 'PRIVATE EVENT', 'kind' => 'TRAINING', 'mode' => 'ANMELDEN',
            'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String()]);
        $insert('team_participations', ['event_id' => $event, 'member_id' => $child, 'status' => 'DABEI']);
        $this->db->statement('INSERT INTO pg_temp.push_team_events SELECT * FROM clubunio.team_events WHERE id=?', [$event]);
        $this->withToken($this->secondToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/php/extras/ssl/openssl.cnf')) $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        openssl_pkey_export(openssl_pkey_new($options), $pem, null, $options);
        $this->credentialFile = tempnam(sys_get_temp_dir(), 'synthetic-push-');
        file_put_contents($this->credentialFile, json_encode(['type' => 'service_account', 'project_id' => 'synthetic-project', 'private_key' => $pem,
            'client_email' => 'synthetic@synthetic-project.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(['clubunio-mobile-push.delivery_enabled' => true, 'clubunio-mobile-push.apps' => ['ch.clubunio.synthetic' => [
            'origin' => 'https://synthetic.example', 'platform' => 'android', 'fixed_tenant_id' => null,
            'project_id' => 'synthetic-project', 'credentials_file' => $this->credentialFile]]]);
        $this->withHeader('X-ClubUnio-Mobile-Features', 'personal-push-v1');
        return $event;
    }
    public function test_squad_uses_personal_rights_displayed_snapshot_and_uuid_replay(): void
    {
        $event = $this->squadFixture();
        $url = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event.'/squad/push';
        $this->withToken($this->firstToken);
        $snapshot = $this->getJson($url.'-preview')->assertOk()->assertJsonPath('data.recipient_count', 1)->assertDontSee('PRIVATE CHILD')->json('data');
        $payload = ['command_key' => (string) Str::uuid(), 'revision' => $snapshot['revision'], 'recipient_fingerprint' => $snapshot['recipient_fingerprint']];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.notificationCount', 1)->assertJsonPath('data.deliveryState', 'queued');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.notificationCount', 1);
        self::assertSame(1, $this->db->table('clubunio.mobile_push_outbox')->count());
        $this->postJson($url, array_replace($payload, ['revision' => str_repeat('f', 64)]))->assertConflict();
        $this->withToken($this->secondToken)->postJson($url, $payload)->assertNotFound(); Http::assertNothingSent();
    }
    public function test_squad_stale_participation_and_old_client_are_closed(): void
    {
        $event = $this->squadFixture();
        $url = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event.'/squad/push';
        $this->withToken($this->firstToken);
        $snapshot = $this->getJson($url.'-preview')->assertOk()->json('data');
        $this->db->table('clubunio.team_participations')->where('tenant_id', $this->tenant->id)->where('event_id', $event)->update(['status' => 'ABWESEND', 'revision' => 2]);
        $payload = ['command_key' => (string) Str::uuid(), 'revision' => $snapshot['revision'], 'recipient_fingerprint' => $snapshot['recipient_fingerprint']];
        $this->postJson($url, $payload)->assertConflict();
        $this->withHeader('X-ClubUnio-Mobile-Features', 'svs-planning-v1')->postJson($url, $payload)->assertStatus(503);
        self::assertSame(0, $this->db->table('clubunio.mobile_push_commands')->count()); Http::assertNothingSent();
    }
    public function test_status_recovers_binding_revision_without_returning_tokens_or_other_accounts(): void
    {
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data())->assertOk();
        $this->postJson('https://synthetic.example/api/mobile/v1/push/devices/status', $this->data())
            ->assertOk()->assertExactJson(['data' => ['revision' => 1, 'bound' => true]])->assertDontSee('token');
        $this->withToken($this->secondToken)->postJson('https://synthetic.example/api/mobile/v1/push/devices/status', $this->data())
            ->assertOk()->assertExactJson(['data' => ['revision' => 1, 'bound' => false]]);
        Http::assertNothingSent();
    }
    public function test_real_bus_notification_source_is_idempotent_private_and_rechecks_visibility(): void
    {
        $this->squadFixture();
        $this->withToken($this->firstToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 1]))->assertOk();
        $bus = $this->db->table('clubunio.buses')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'PRIVATE BUS']);
        $this->db->table('clubunio.bus_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => 'ANGEFRAGT', 'name' => 'Synthetic']);
        $team = $this->db->table('clubunio.team_seasons')->where('tenant_id', $this->tenant->id)->value('id');
        $reservation = $this->db->table('clubunio.bus_reservations')->insertGetId(['tenant_id' => $this->tenant->id, 'bus_id' => $bus,
            'team_season_id' => $team, 'status' => 'ANGEFRAGT', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()]);
        $notice = $this->db->table('clubunio.notifications')->insertGetId(['tenant_id' => $this->tenant->id, 'account_id' => $this->first->id,
            'object_type' => 'bus_reservation', 'object_id' => $reservation, 'title' => 'PRIVATE TITLE', 'body' => 'PRIVATE BODY',
            'created_at' => $this->db->raw('clock_timestamp()::timestamp')]);
        $source = app(\App\ClubUnio\Mobile\MobilePushSources::class);
        self::assertSame(1, $source->existingNotifications()); self::assertSame(0, $source->existingNotifications());
        $row = $this->db->table('clubunio.mobile_push_outbox')->first();
        self::assertSame('notification:'.$notice, $row->event_key); self::assertSame('bus', $row->kind);
        self::assertStringNotContainsString('PRIVATE', json_encode($row));
        $base = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id;
        $this->postJson($base.'/push/resolve', ['kind' => 'bus', 'object_id' => $reservation])->assertOk()->assertExactJson(['data' => ['kind' => 'bus', 'object_id' => $reservation]]);
        $this->getJson($base.'/inbox')->assertOk()->assertDontSee('PRIVATE TITLE')->assertJsonPath('data.0.kind', 'bus');
        $this->db->table('clubunio.bus_reservations')->where('id', $reservation)->update(['active' => false]);
        self::assertTrue(app(MobilePushOutbox::class)->deliverOne());
        self::assertSame('discarded', $this->db->table('clubunio.mobile_push_outbox')->value('state'));
        $this->getJson($base.'/inbox')->assertOk()->assertJsonCount(0, 'data'); Http::assertNothingSent();
    }
    public function test_missing_typed_targets_never_fall_back_to_chat_or_foreign_records(): void
    {
        $this->withToken($this->firstToken);
        $base = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/push/resolve';
        foreach (['bus', 'member', 'member_change', 'expense', 'document_receipt', 'document_file', 'document_archive'] as $kind) {
            $this->postJson($base, ['kind' => $kind, 'object_id' => PHP_INT_MAX])->assertNotFound();
        }
        $this->postJson($base, ['kind' => 'https://attacker.example', 'object_id' => 1])->assertUnprocessable(); Http::assertNothingSent();
    }
    private function notices(int $count): array
    {
        $chat = app(\App\ClubUnio\Mobile\ChatService::class)->ensure($this->tenant, 'announcements'); $ids = [];
        for ($i = 0; $i < $count; $i++) $ids[] = $this->db->table('clubunio.mobile_inbox')->insertGetId(['tenant_id' => $this->tenant->id,
            'account_id' => $this->first->id, 'kind' => 'announcement', 'object_id' => $chat->id, 'event_key' => 'synthetic:'.Str::uuid(), 'created_at' => now()->toIso8601String()]);
        return $ids;
    }
    public function test_original_notifications_pagination_watermark_and_late_notice_survive_read_all(): void
    {
        $ids = $this->notices(3); $this->withToken($this->firstToken);
        $base = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/notifications';
        $page = $this->getJson($base.'?start=0&recperpage=2')->assertOk()->assertJsonCount(2, 'data.notifications')
            ->assertJsonPath('data.totalRecordCount', 3)->assertJsonPath('data.unreadCount', 3)->assertJsonPath('data.notifications.0.NotificationID', $ids[2])->json('data');
        $late = $this->notices(1)[0];
        $this->postJson($base.'/read-all', ['through_id' => $page['watermark']])->assertOk()->assertJsonPath('data.unreadCount', 1);
        $this->getJson($base.'?unreadOnly=1')->assertOk()->assertJsonCount(1, 'data.notifications')->assertJsonPath('data.notifications.0.NotificationID', $late);
        $this->postJson($base.'/'.$late.'/read')->assertOk()->assertJsonPath('data.unreadCount', 0);
        $this->getJson($base.'/unread-count')->assertOk()->assertJsonPath('data.unreadCount', 0); Http::assertNothingSent();
    }
    public function test_notifications_are_readable_without_push_and_cross_account_reads_are_blocked(): void
    {
        $id = $this->notices(1)[0]; config(['clubunio-mobile-push.enabled' => false]);
        $base = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/notifications';
        $this->withToken($this->firstToken)->getJson($base)->assertOk()->assertJsonPath('data.unreadCount', 1);
        $this->withToken($this->secondToken)->getJson($base)->assertOk()->assertJsonPath('data.unreadCount', 0);
        $this->postJson($base.'/'.$id.'/read')->assertNotFound();
        self::assertNull($this->db->table('clubunio.mobile_inbox')->where('id', $id)->value('read_at')); Http::assertNothingSent();
    }
    public function test_genuine_owned_source_text_is_visible_only_after_current_object_authorization(): void
    {
        $event = $this->squadFixture();
        $this->db->table('clubunio.notifications')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->first->id,
            'object_type' => 'team_event', 'object_id' => $event, 'title' => 'PRIVATE REAL TITLE', 'body' => 'PRIVATE REAL BODY']);
        $base = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/notifications';
        $this->withToken($this->firstToken)->getJson($base)->assertOk()->assertJsonPath('data.notifications.0.title', 'PRIVATE REAL TITLE')
            ->assertJsonPath('data.notifications.0.body', 'PRIVATE REAL BODY');
        $this->withToken($this->secondToken)->getJson($base)->assertOk()->assertDontSee('PRIVATE REAL');
        $this->db->table('clubunio.team_seasons')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
        $this->withToken($this->firstToken)->getJson($base)->assertOk()->assertJsonCount(0, 'data.notifications')->assertDontSee('PRIVATE REAL');
        Http::assertNothingSent();
    }
    public function test_queued_squad_is_discarded_if_publication_or_recipients_change(): void
    {
        $event = $this->squadFixture();
        $url = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event.'/squad/push';
        $this->withToken($this->firstToken);
        $snapshot = $this->getJson($url.'-preview')->assertOk()->json('data');
        $this->postJson($url, ['command_key' => (string) Str::uuid(), 'revision' => $snapshot['revision'],
            'recipient_fingerprint' => $snapshot['recipient_fingerprint']])->assertOk()->assertJsonPath('data.notificationCount', 1);
        $this->db->table('clubunio.team_participations')->where('tenant_id', $this->tenant->id)->where('event_id', $event)->update(['status' => 'ABWESEND', 'revision' => 2]);
        self::assertTrue(app(MobilePushOutbox::class)->deliverOne());
        self::assertSame('discarded', $this->db->table('clubunio.mobile_push_outbox')->value('state')); Http::assertNothingSent();
    }
    public function test_worker_fcm_unregistered_revokes_install_and_requires_real_token_rotation(): void
    {
        $event = $this->squadFixture();
        $url = 'https://synthetic.example/api/mobile/v1/clubs/'.$this->tenant->id.'/planning/events/'.$event.'/squad/push';
        $this->withToken($this->firstToken);
        $snapshot = $this->getJson($url.'-preview')->assertOk()->json('data');
        $this->postJson($url, ['command_key' => (string) Str::uuid(), 'revision' => $snapshot['revision'],
            'recipient_fingerprint' => $snapshot['recipient_fingerprint']])->assertOk();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404)]);
        self::assertTrue(app(MobilePushOutbox::class)->deliverOne());
        self::assertSame('token_unregistered', $this->db->table('clubunio.mobile_push_outbox')->value('outcome_code'));
        self::assertNotNull($this->db->table('clubunio.mobile_push_devices')->value('revoked_at'));
        $this->withToken($this->secondToken)->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 2]))->assertConflict();
        $this->putJson('https://synthetic.example/api/mobile/v1/push/devices', $this->data(['revision' => 2, 'token' => str_repeat('genuine-rotated-token', 3)]))->assertOk()->assertJsonPath('data.revision', 3);
        Http::assertSentCount(2);
    }
}
