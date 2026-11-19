<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Actual migration DDL on temporary tables only; no permanent fixtures or schema writes. */
class PitchPlanningNotificationMigrationTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to temporary PostgreSQL migration tests.');
        }
        Http::preventStrayRequests();
        Mail::fake();
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        foreach (['tenants', 'accounts', 'tenant_accounts', 'mobile_sessions', 'team_events'] as $table) {
            $this->db->statement("CREATE TEMP TABLE $table (LIKE clubunio.$table INCLUDING ALL) ON COMMIT DROP");
        }
        $this->temporaryDdl('2026_11_04_000500_mobile_push_devices.php');
        $this->temporaryDdl('2026_11_04_001600_pitch_planning_notifications.php');
        $this->db->table('pg_temp.tenants')->insert([
            ['id' => 91001, 'slug' => 'synthetic-pitch-notice-one', 'name' => 'Synthetic one'],
            ['id' => 91002, 'slug' => 'synthetic-pitch-notice-two', 'name' => 'Synthetic two'],
        ]);
    }

    private function temporaryDdl(string $migration): void
    {
        // Pretend also suppresses template seeding reads/writes. Only the captured DDL is executed.
        $queries = $this->db->pretend(fn () => (require database_path('clubunio/'.$migration))->up());
        foreach ($queries as $query) {
            if (! str_contains($query['query'], 'CREATE TABLE clubunio.')) {
                continue;
            }
            $sql = str_replace(['clubunio.', 'CREATE TABLE '], ['pg_temp.', 'CREATE TEMP TABLE '], $query['query']);
            $this->assertStringNotContainsString('clubunio.', $sql);
            $this->db->unprepared($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function row(array $changes = []): array
    {
        return array_replace(['tenant_id' => 91001, 'event_key' => 'request:1:requested', 'fingerprint' => str_repeat('a', 64),
            'action' => 'requested', 'team_season_ids' => '[]', 'source_key' => 'request:1', 'source_date' => '2026-11-05',
            'payload' => '{}'], $changes);
    }

    private function rejects(string $state, array $row): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->table('pg_temp.pitch_planning_events')->insert($row);
            $this->fail('Expected a PostgreSQL constraint rejection');
        } catch (QueryException $error) {
            $this->assertSame($state, (string) $error->getCode());
        } finally {
            $this->db->rollBack();
        }
    }

    public function test_notification_migration_enforces_event_identity_email_action_and_tenant_fk(): void
    {
        $this->db->table('pg_temp.pitch_planning_events')->insert($this->row());
        $this->rejects('23505', $this->row());
        $this->rejects('23503', $this->row(['tenant_id' => 91999]));
        $this->rejects('23514', $this->row(['event_key' => 'invalid-action', 'action' => 'unknown']));
        $this->rejects('23514', $this->row(['event_key' => 'move-email', 'action' => 'moved', 'payload_encrypted' => 'synthetic']));
        $this->rejects('23514', $this->row(['event_key' => 'invalid-state', 'delivery_state' => 'retry']));
        $this->db->table('pg_temp.pitch_planning_events')->insert($this->row(['tenant_id' => 91002, 'payload_encrypted' => 'synthetic']));
        foreach (['created', 'cancelled'] as $action) {
            $this->db->table('pg_temp.pitch_planning_events')->insert($this->row(['event_key' => $action, 'action' => $action]));
            $this->rejects('23514', $this->row(['event_key' => $action.'-email', 'action' => $action, 'payload_encrypted' => 'synthetic']));
        }
        $this->assertSame(4, $this->db->table('pg_temp.pitch_planning_events')->count());
        $this->assertSame('held', $this->db->table('pg_temp.pitch_planning_events')->value('delivery_state'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_push_kind_extension_retains_existing_queue_foreign_keys_and_unique_constraint(): void
    {
        $constraints = $this->db->select("SELECT contype, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'pg_temp.mobile_push_outbox'::regclass");
        $definitions = array_column($constraints, 'definition');
        $all = implode("\n", $definitions);
        $this->assertStringContainsString('pitch_planning', $all);
        $this->assertStringContainsString('admin_notice', $all);
        $this->assertStringContainsString('planning', $all);
        $this->assertStringContainsString('UNIQUE (binding_id, tenant_id, event_key)', $all);
        $this->assertCount(2, array_filter($constraints, fn ($constraint) => $constraint->contype === 'f'));
        $this->assertSame(0, $this->db->table('pg_temp.mobile_push_outbox')->count());
    }
}
