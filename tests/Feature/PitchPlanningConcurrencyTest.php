<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{PermissionCatalog, PitchFriendlyRequestService, PitchPlanningNotificationService, PitchPlanningService};
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\{Http, Mail};
use Symfony\Component\Process\Process;
use Tests\Support\PitchConcurrencyDatabase as Fixture;
use Tests\TestCase;

/** Opt-in real two-connection races; only a unique disposable schema is mutated. */
class PitchPlanningConcurrencyTest extends TestCase
{
    private ?PostgresConnection $db = null;
    private ?string $schema = null;
    private ?Process $worker = null;
    private array $fixture;
    private PitchPlanningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_PITCH_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Opt in explicitly to disposable-schema PostgreSQL races.');
        }
        $this->schema = 'pitch_race_'.bin2hex(random_bytes(12));
        $this->db = Fixture::connect($this->schema);
        try {
            Fixture::create($this->db, $this->schema);
            $this->fixture = Fixture::seed($this->db);
            $this->service = app(PitchPlanningService::class);
        } catch (\Throwable $error) {
            Fixture::drop($this->db, $this->schema);
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->worker?->stop(1);
            if ($this->db && $this->schema) {
                $this->assertTrue(Fixture::drop($this->db, $this->schema), 'Disposable schema survived cleanup');
                $this->assertSame(0, (int) $this->db->selectOne('SELECT count(*) AS n FROM pg_stat_activity WHERE application_name=? AND xact_start IS NOT NULL AND pid<>pg_backend_pid()', [$this->schema])->n,
                    'Race worker transaction survived cleanup');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function input(array $extra = []): array
    {
        return $extra + ['team_season_id' => $this->fixture['team'], 'opponent' => 'Synthetic visitors',
            'field_id' => $this->fixture['field'], 'area_id' => null, 'date' => '2026-11-06',
            'starts_at' => '18:00', 'duration_minutes' => 120];
    }

    private function request(array $extra = [], bool $approved = false): array
    {
        return $this->service->{$approved ? 'createManual' : 'requestFriendly'}(
            $this->fixture['actor'], $this->fixture['tenant'], $this->input($extra));
    }

    private function approve(array $entry): array
    {
        return $this->service->decide($this->fixture['actor'], $this->fixture['tenant'], $entry['source_id'],
            ['revision' => $entry['revision'], 'decision' => 'approved']);
    }

    private function startBlocked(array $operation, array $visible): int
    {
        $php = PHP_OS_FAMILY === 'Windows' ? [PHP_BINARY, '-d', 'extension=pdo_pgsql'] : [PHP_BINARY];
        $this->worker = new Process([...$php, base_path('tests/Support/pitch-concurrency-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'CLUBUNIO_TEST_PITCH_CONCURRENCY' => '1', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'],
            json_encode($operation + ['schema' => $this->schema, 'actor' => $this->fixture['actor']->id,
                'tenant' => $this->fixture['tenant']->id], JSON_THROW_ON_ERROR), 25);
        $this->worker->start();
        $deadline = microtime(true) + 10;
        $ready = null;
        do {
            $lines = array_values(array_filter(explode("\n", trim($this->worker->getOutput()))));
            if ($lines) $ready = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            if ($ready) break;
            if (! $this->worker->isRunning()) $this->fail($this->worker->getErrorOutput());
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertNotNull($ready, 'Worker did not reach pre-mutation barrier');
        $this->assertSame($visible, $ready['visible'], 'Uncommitted source/audit/notifications escaped');
        $parentPid = (int) $this->db->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $this->assertNotSame($parentPid, $ready['pid']);
        $this->assertSame('read committed', $ready['isolation']);
        $this->assertSame('read committed', $this->db->selectOne("SELECT current_setting('transaction_isolation') AS isolation")->isolation);
        do {
            $state = $this->db->selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$parentPid, $ready['pid']]);
            if ($state->blocked) break;
            if (! $this->worker->isRunning()) $this->fail('No real lock wait: '.$this->worker->getOutput().$this->worker->getErrorOutput());
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertTrue($state->blocked, 'PostgreSQL did not observe the contender blocked by the writer');
        $this->assertTrue($this->worker->isRunning(), 'Contender completed before lock release');
        return $ready['pid'];
    }

    private function finish(int $status): array
    {
        $this->assertSame(0, $this->worker->wait(), $this->worker->getErrorOutput());
        $lines = array_values(array_filter(explode("\n", trim($this->worker->getOutput()))));
        $result = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($status, $result['status']);
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, $this->db->table('clubora.mobile_push_outbox')->where('state', '<>', 'pending')->count());
        return $result;
    }

    private function profile(array $permissions): int
    {
        return $this->db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $this->fixture['tenant']->id,
            'name' => 'Synthetic profile '.bin2hex(random_bytes(4)), 'active' => true,
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)]);
    }

    private function forbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected current permissions to reject the operation');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_simultaneous_approvals_on_different_requests_only_commit_one_occupancy(): void
    {
        $one = $this->request();
        $two = $this->request();
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $this->approve($one);
        $this->startBlocked(['operation' => 'decide', 'id' => $two['source_id'],
            'data' => ['revision' => $two['revision'], 'decision' => 'approved']], $visible);
        $this->db->commit();
        $this->finish(409);
        $this->assertSame(1, $this->db->table('clubora.pitch_requests')->where('status', 'approved')->count());
        $this->assertSame('pending', $this->db->table('clubora.pitch_requests')->where('id', $two['source_id'])->value('status'));
        $this->assertSame(1, $this->db->table('clubora.pitch_planning_events')->where('action', 'approved')->count());
    }

    public function test_simultaneous_decisions_on_one_revision_reject_the_stale_contender(): void
    {
        $one = $this->request();
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $this->approve($one);
        $this->startBlocked(['operation' => 'decide', 'id' => $one['source_id'],
            'data' => ['revision' => $one['revision'], 'decision' => 'rejected']], $visible);
        $this->db->commit();
        $this->finish(409);
        $this->assertSame(0, $this->db->table('clubora.pitch_planning_events')->where('action', 'rejected')->count());
    }

    public function test_simultaneous_moves_to_one_destination_are_serialized(): void
    {
        $one = $this->request(['starts_at' => '10:00'], true);
        $two = $this->request(['starts_at' => '14:00'], true);
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $this->service->move($this->fixture['actor'], $this->fixture['tenant'], $one['key'], $this->input(['revision' => $one['revision']]));
        $this->startBlocked(['operation' => 'move', 'key' => $two['key'], 'data' => $this->input(['revision' => $two['revision']])], $visible);
        $this->db->commit();
        $this->finish(409);
        $this->assertSame('14:00:00', $this->db->table('clubora.pitch_requests')->where('id', $two['source_id'])->value('starts_at'));
        $this->assertSame(1, $this->db->table('clubora.pitch_planning_events')->where('action', 'moved')->count());
        $this->assertSame($visible['notifications'] + 1, $this->db->table('clubora.notifications')->count());
    }

    public function test_same_revision_move_waits_then_fails_without_a_second_notification(): void
    {
        $one = $this->request(['starts_at' => '10:00'], true);
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $this->service->move($this->fixture['actor'], $this->fixture['tenant'], $one['key'], $this->input(['revision' => $one['revision']]));
        $this->startBlocked(['operation' => 'move', 'key' => $one['key'],
            'data' => $this->input(['revision' => $one['revision'], 'starts_at' => '20:00'])], $visible);
        $this->db->commit();
        $this->finish(409);
        $this->assertSame('18:00:00', $this->db->table('clubora.pitch_requests')->where('id', $one['source_id'])->value('starts_at'));
        $this->assertSame($visible['notifications'] + 1, $this->db->table('clubora.notifications')->count());
    }

    public function test_nonoverlapping_waiting_move_can_commit_after_the_first_move(): void
    {
        $one = $this->request(['starts_at' => '10:00'], true);
        $two = $this->request(['starts_at' => '14:00'], true);
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $this->service->move($this->fixture['actor'], $this->fixture['tenant'], $one['key'], $this->input(['revision' => $one['revision']]));
        $this->startBlocked(['operation' => 'move', 'key' => $two['key'],
            'data' => $this->input(['revision' => $two['revision'], 'starts_at' => '20:00'])], $visible);
        $this->db->commit();
        $this->finish(200);
        $this->assertSame(2, $this->db->table('clubora.pitch_planning_events')->where('action', 'moved')->count());
        $this->assertSame($visible['notifications'] + 2, $this->db->table('clubora.notifications')->count());
    }

    public function test_rollback_releases_waiter_without_leaking_source_audit_or_notifications(): void
    {
        $one = $this->request();
        $two = $this->request();
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $this->approve($one);
        $this->startBlocked(['operation' => 'decide', 'id' => $two['source_id'],
            'data' => ['revision' => $two['revision'], 'decision' => 'approved']], $visible);
        $this->db->rollBack();
        $this->finish(200);
        $this->assertSame('pending', $this->db->table('clubora.pitch_requests')->where('id', $one['source_id'])->value('status'));
        $this->assertSame($visible['pitch_planning_events'] + 1, $this->db->table('clubora.pitch_planning_events')->count());
        $this->assertSame($visible['notifications'] + 1, $this->db->table('clubora.notifications')->count());
        $this->assertSame($visible['object_changes'] + 1, $this->db->table('clubora.object_changes')->count());
    }

    public function test_waiting_writer_rechecks_revoked_actor_after_lock_acquisition(): void
    {
        $one = $this->request();
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        Tenant::whereKey($this->fixture['tenant']->id)->lockForUpdate()->firstOrFail();
        $this->db->table('clubora.accounts')->where('id', $this->fixture['actor']->id)->update(['active' => false]);
        $this->startBlocked(['operation' => 'decide', 'id' => $one['source_id'],
            'data' => ['revision' => $one['revision'], 'decision' => 'approved']], $visible);
        $this->db->commit();
        $this->finish(403);
        $this->assertSame($visible, Fixture::counts($this->db));
    }

    public function test_waiting_writer_cannot_retain_office_authority_through_a_broad_profile(): void
    {
        $one = $this->request();
        $profile = $this->profile(PermissionCatalog::keys());
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        Tenant::whereKey($this->fixture['tenant']->id)->lockForUpdate()->firstOrFail();
        $this->db->table('clubora.tenant_accounts')->update(['role' => 'profile', 'access_profile_id' => $profile]);
        $this->startBlocked(['operation' => 'decide', 'id' => $one['source_id'],
            'data' => ['revision' => $one['revision'], 'decision' => 'approved']], $visible);
        $this->db->commit();
        $this->finish(403);
        $this->assertTrue($this->service->canView($this->fixture['actor'], $this->fixture['tenant']));
        $this->assertSame($visible, Fixture::counts($this->db));
    }

    public function test_exact_role_boundary_for_requests_approval_management_and_status_only_profiles(): void
    {
        $actor = $this->fixture['actor'];
        $tenant = $this->fixture['tenant'];
        $pending = $this->request();
        foreach (['trainer', 'technical_lead', 'member'] as $role) {
            $this->db->table('clubora.tenant_accounts')->update(['role' => $role, 'portal_enabled' => true]);
            $this->assertTrue($this->service->canView($actor, $tenant), $role);
            if ($role === 'member') $this->forbidden(fn () => $this->request());
            else $this->assertSame('pending', $this->request()['status']);
            $this->forbidden(fn () => $this->approve($pending));
            $this->forbidden(fn () => $this->request([], true));
            $this->forbidden(fn () => $this->service->move($actor, $tenant, $pending['key'], $this->input(['revision' => $pending['revision']])));
            $this->forbidden(fn () => $this->service->cancel($actor, $tenant, $pending['key'], ['revision' => $pending['revision']]));
        }
        $before = Fixture::counts($this->db);
        foreach ([PermissionCatalog::keys(), ['competitions.view'], ['field_availability.view', 'field_availability.update']] as $index => $permissions) {
            $this->db->table('clubora.tenant_accounts')->update(['role' => 'profile', 'portal_enabled' => false, 'access_profile_id' => $this->profile($permissions)]);
            $this->assertSame($index !== 2, $this->service->canView($actor, $tenant));
            $this->forbidden(fn () => $this->request());
            $this->forbidden(fn () => $this->approve($pending));
            $this->forbidden(fn () => $this->service->saveBlock($actor, $tenant, $this->input(['reason' => 'Synthetic closure'])));
            $this->forbidden(fn () => $this->service->saveArea($actor, $tenant, []));
            $this->forbidden(fn () => $this->service->configureCompetition($actor, $tenant, 1, []));
        }
        $this->assertSame($before, Fixture::counts($this->db));
        $this->db->table('clubora.tenant_accounts')->update(['role' => 'office', 'access_profile_id' => null]);
        $this->assertSame('approved', $this->approve($pending)['status']);
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => $tenant->id, 'target_id' => $actor->id]);
        $this->forbidden(fn () => $this->request());
    }

    public function test_notification_failure_rolls_back_the_actual_domain_write(): void
    {
        $one = $this->request();
        $before = Fixture::counts($this->db);
        $this->app->instance(PitchPlanningNotificationService::class, new class extends PitchPlanningNotificationService {
            public function record(Tenant $tenant, array $event): void
            {
                parent::record($tenant, $event);
                throw new \RuntimeException('Synthetic fanout failure');
            }
        });
        try {
            $this->approve($one);
            $this->fail('Injected notification failure did not abort');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic fanout failure', $error->getMessage());
        }
        $this->assertSame($before, Fixture::counts($this->db));
        $this->assertSame('pending', $this->db->table('clubora.pitch_requests')->where('id', $one['source_id'])->value('status'));
    }

    public function test_identical_concurrent_request_key_replays_one_committed_request_and_event(): void
    {
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        $one = app(PitchFriendlyRequestService::class)->submit($this->fixture['actor'], $this->fixture['tenant'], $this->input(), 'race-same-key');
        $this->startBlocked(['operation' => 'submit', 'request_id' => 'race-same-key', 'data' => $this->input()], $visible);
        $this->db->commit();
        $two = $this->finish(200)['entry'];
        $this->assertFalse($one['replayed']);
        $this->assertTrue($two['replayed']);
        $this->assertSame($one['data'], $two['data']);
        foreach (['pitch_requests', 'pitch_request_idempotency', 'pitch_planning_events', 'notifications', 'mobile_inbox', 'mobile_push_outbox', 'object_changes'] as $table) {
            $this->assertSame(1, $this->db->table('clubora.'.$table)->count(), $table.' duplicated');
        }
    }

    public function test_concurrent_reused_request_key_with_different_payload_is_a_conflict(): void
    {
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        app(PitchFriendlyRequestService::class)->submit($this->fixture['actor'], $this->fixture['tenant'], $this->input(), 'race-changed-key');
        $this->startBlocked(['operation' => 'submit', 'request_id' => 'race-changed-key',
            'data' => $this->input(['opponent' => 'Different synthetic visitors'])], $visible);
        $this->db->commit();
        $this->finish(409);
        $this->assertSame(1, $this->db->table('clubora.pitch_requests')->count());
        $this->assertSame(1, $this->db->table('clubora.pitch_planning_events')->count());
    }

    public function test_rolled_back_request_key_is_claimable_by_the_waiting_writer(): void
    {
        $visible = Fixture::counts($this->db);
        $this->db->beginTransaction();
        app(PitchFriendlyRequestService::class)->submit($this->fixture['actor'], $this->fixture['tenant'], $this->input(), 'race-rollback-key');
        $this->startBlocked(['operation' => 'submit', 'request_id' => 'race-rollback-key', 'data' => $this->input()], $visible);
        $this->db->rollBack();
        $two = $this->finish(200)['entry'];
        $this->assertFalse($two['replayed']);
        $this->assertSame(1, $this->db->table('clubora.pitch_requests')->count());
        $this->assertSame(1, $this->db->table('clubora.pitch_request_idempotency')->count());
        $this->assertSame(1, $this->db->table('clubora.pitch_planning_events')->count());
    }
}
