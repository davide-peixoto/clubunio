<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Competition, Tenant};
use App\ClubUnio\Services\{ActiveTrainingPlan, PitchPlanningNotificationService, PitchPlanningProjection, PitchPlanningService, SfvCompetitionService, SignageService};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL DDL in session-local tables; all synthetic catalog writes roll back. */
class PitchPlanningDomainTest extends TestCase
{
    private $db;
    private $grammar;
    private Tenant $tenant;
    private Account $actor;
    private PitchPlanningService $service;
    private int $field;
    private int $field2;
    private int $team;
    private int $period;
    private int $slot;
    private int $competition;
    private array $events = [];
    private const TABLES = ['pitch_areas', 'pitch_training_exceptions', 'pitch_requests', 'pitch_blocks', 'pitch_competition_settings', 'pitch_competition_teams'];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt in to synthetic PostgreSQL rollback tests.');
        Http::preventStrayRequests();
        Mail::fake();
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name TO 'clubunio-pitch-admin-override-test'");
        $suffix = bin2hex(random_bytes(8));
        $this->tenant = Tenant::create(['slug' => 'pitch-'.$suffix, 'name' => 'Pitch test']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'pitch-'.$suffix, 'display_name' => 'Pitch test', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $season = $this->insert('seasons', ['name' => 'Pitch season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $team = $this->insert('teams', ['name' => 'Pitch team']);
        $this->team = $this->insert('team_seasons', ['season_id' => $season, 'team_id' => $team, 'label' => 'Pitch team']);
        $this->field = $this->insert('training_options', ['category' => 'field', 'code' => 'H', 'name' => 'Main pitch']);
        $this->field2 = $this->insert('training_options', ['category' => 'field', 'code' => 'K', 'name' => 'Other pitch']);
        $this->period = $this->insert('training_options', ['category' => 'period', 'code' => 'PITCH', 'name' => 'Pitch period']);
        $weekday = $this->insert('training_options', ['category' => 'weekday', 'code' => '4', 'name' => 'Wednesday']);
        $status = $this->insert('facility_statuses', ['code' => 'PITCH', 'name' => 'Open', 'blocks_training' => false]);
        foreach ([$this->field, $this->field2] as $field) $this->insert('field_availabilities', ['field_id' => $field, 'status_id' => $status]);
        $this->slot = $this->insert('training_slots', ['team_season_id' => $this->team, 'period_id' => $this->period, 'weekday_id' => $weekday,
            'starts_at' => '18:00', 'ends_at' => '19:30', 'field_id' => $this->field, 'all_fields' => true]);
        $this->competition = $this->insert('competitions', ['plays_on' => '2026-11-05', 'starts_at' => '15:00', 'field_id' => $this->field, 'host_name' => 'A', 'guest_name' => 'B']);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => ActiveTrainingPlan::KEY, 'label' => 'Period', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $this->period)]);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Season', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $season)]);

        // Temporary FK parents contain only our synthetic fixtures. No live schema DDL.
        foreach (['tenants', 'accounts', 'training_options', 'training_slots', 'team_seasons', 'competitions'] as $table) {
            $this->db->statement("CREATE TEMP TABLE $table (LIKE clubora.$table INCLUDING ALL) ON COMMIT DROP");
            $column = in_array($table, ['tenants', 'accounts'], true) ? 'id' : 'tenant_id';
            $value = $table === 'accounts' ? $this->actor->id : $this->tenant->id;
            $this->db->statement("INSERT INTO pg_temp.$table SELECT * FROM clubora.$table WHERE $column = ?", [$value]);
        }
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_001500_pitch_planning.php'))->up());
        foreach ($ddl as $query) {
            $sql = str_replace('clubora.', 'pg_temp.', $query['query']);
            $sql = str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $sql);
            $this->db->statement($sql);
        }
        $tables = self::TABLES;
        $this->db->setQueryGrammar(new class($this->db, $tables) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function __construct($connection, private array $tables) { parent::__construct($connection); }
            public function wrapTable($table, $prefix = null) {
                if (in_array(str_replace('clubora.', '', $table), $this->tables, true)) $table = str_replace('clubora.', 'pg_temp.', $table);
                return parent::wrapTable($table, $prefix);
            }
        });
        $projection = new class extends PitchPlanningProjection {
            public function ready(): bool { return true; }
        };
        $this->service = new PitchPlanningService($projection);
        $this->app->instance(PitchPlanningProjection::class, $projection);
        $this->app->instance(PitchPlanningService::class, $this->service);
        $this->mock(PitchPlanningNotificationService::class)->shouldReceive('record')->andReturnUsing(function ($tenant, $event) {
            $this->assertGreaterThan(1, $this->db->transactionLevel());
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) $this->db->rollBack();
            if ($this->grammar) $this->db->setQueryGrammar($this->grammar);
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['team_season_id' => $this->team, 'opponent' => 'Visitors', 'field_id' => $this->field,
            'area_id' => null, 'date' => '2026-11-06', 'starts_at' => '18:00', 'duration_minutes' => 120];
    }

    private function area(float $x, float $width): array
    {
        return $this->service->saveArea($this->actor, $this->tenant, ['field_id' => $this->field,
            'name_de' => 'Teil', 'name_en' => 'Area', 'x' => $x, 'y' => 0, 'width' => $width, 'height' => 1]);
    }

    private function fails(int $status, callable $action): void
    {
        try { $action(); $this->fail('Expected HTTP '.$status); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function test_projection_weekly_base_competition_duration_and_tenant_isolation(): void
    {
        $data = $this->service->calendar($this->actor, $this->tenant, ['from' => '2026-11-04', 'to' => '2026-11-05']);
        $this->assertCount(2, $data['entries']);
        $this->assertSame(90, $data['entries'][0]['duration_minutes']);
        $this->assertSame(120, $data['entries'][1]['duration_minutes']);
        $this->assertSame([], $data['entries'][1]['team_season_ids']);
        $other = Tenant::create(['slug' => 'pitch-other-'.bin2hex(random_bytes(5)), 'name' => 'Other']);
        $this->fails(403, fn () => $this->service->calendar($this->actor, $other, ['from' => '2026-11-04', 'to' => '2026-11-05']));
    }

    public function test_partial_areas_overlap_whole_fields_and_touching_edges(): void
    {
        $a = $this->area(0, .5);
        $b = $this->area(.5, .5);
        $c = $this->area(.25, .5);
        $this->service->createManual($this->actor, $this->tenant, $this->input(['area_id' => $a['id']]));
        $this->service->createManual($this->actor, $this->tenant, $this->input(['area_id' => $b['id']]));
        $this->fails(409, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input(['area_id' => $c['id']])));
        $this->fails(409, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input()));
        $this->service->createManual($this->actor, $this->tenant, $this->input(['starts_at' => '20:00']));
        $this->assertSame(3, $this->db->table('pg_temp.pitch_requests')->count());
        $this->assertSame(['created', 'created', 'created'], array_column($this->events, 'action'));
    }

    public function test_approval_rechecks_conflicts_and_revision(): void
    {
        $one = $this->service->requestFriendly($this->actor, $this->tenant, $this->input());
        $two = $this->service->requestFriendly($this->actor, $this->tenant, $this->input());
        $this->assertSame('pending', $one['status']);
        $approved = $this->service->decide($this->actor, $this->tenant, $one['source_id'], ['revision' => $one['revision'], 'decision' => 'approved']);
        $this->assertSame('approved', $approved['status']);
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $two['source_id'], ['revision' => $two['revision'], 'decision' => 'approved']));
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $one['source_id'], ['revision' => $one['revision'], 'decision' => 'rejected']));
        $this->assertSame(['requested', 'requested', 'approved'], array_column($this->events, 'action'));
    }

    public function test_admin_must_explicitly_override_only_current_field_status_and_audit_the_choice(): void
    {
        $request = $this->service->requestFriendly($this->actor, $this->tenant, $this->input());
        $closed = $this->insert('facility_statuses', ['code' => 'CLOSED-OVERRIDE', 'name' => 'Closed now', 'blocks_training' => true]);
        $this->db->table('clubora.field_availabilities')->where('tenant_id', $this->tenant->id)->where('field_id', $this->field)->update(['status_id' => $closed]);
        $this->assertTrue($this->service->options($this->actor, $this->tenant)['capabilities']['override_field_status']);
        $review = ['revision' => $request['revision'], 'decision' => 'approved'];
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review + ['override_field_status' => false]));
        $this->fails(422, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], ['revision' => $request['revision'], 'decision' => 'rejected', 'override_field_status' => true]));
        $approved = $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review + ['override_field_status' => true]);
        $this->assertSame('approved', $approved['status']);
        $this->assertTrue($approved['override_field_status']);
        $this->assertTrue(end($this->events)['after']['override_field_status']);
        $event = json_decode($this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'pitch_planning')->orderByDesc('id')->value('after_value'), true);
        $this->assertTrue($event['after']['override_field_status']);
        $this->assertSame($closed, $this->db->table('clubora.field_availabilities')->where('tenant_id', $this->tenant->id)->where('field_id', $this->field)->value('status_id'));
    }

    public function test_field_status_override_cannot_ignore_occupancy_blocks_or_stale_revision(): void
    {
        $occupied = $this->service->createManual($this->actor, $this->tenant, $this->input());
        $request = $this->service->requestFriendly($this->actor, $this->tenant, $this->input());
        $closed = $this->insert('facility_statuses', ['code' => 'CLOSED-COLLISION', 'name' => 'Closed', 'blocks_training' => true]);
        $this->db->table('clubora.field_availabilities')->where('tenant_id', $this->tenant->id)->where('field_id', $this->field)->update(['status_id' => $closed]);
        $review = ['revision' => $request['revision'], 'decision' => 'approved', 'override_field_status' => true];
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        $this->service->cancel($this->actor, $this->tenant, $occupied['key'], ['revision' => $occupied['revision']]);
        $block = $this->service->saveBlock($this->actor, $this->tenant, $this->input(['reason' => 'Synthetic timed closure']));
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        $this->service->cancel($this->actor, $this->tenant, $block['key'], ['revision' => $block['revision']]);
        $this->fails(409, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], array_replace($review, ['revision' => 'stale'])));
        $this->assertSame('pending', $this->db->table('pg_temp.pitch_requests')->where('id', $request['source_id'])->value('status'));
        $this->assertNotContains('approved', array_column($this->events, 'action'));
    }

    public function test_status_override_requires_fresh_admin_update_right_and_active_tenant_field(): void
    {
        $request = $this->service->requestFriendly($this->actor, $this->tenant, $this->input());
        $review = ['revision' => $request['revision'], 'decision' => 'approved', 'override_field_status' => true];
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'office']);
        $this->assertTrue($this->service->options($this->actor, $this->tenant)['capabilities']['approve']);
        $this->assertFalse($this->service->options($this->actor, $this->tenant)['capabilities']['override_field_status']);
        $this->fails(403, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'admin']);
        $this->db->table('clubora.training_options')->where('id', $this->field)->update(['active' => false]);
        $this->fails(404, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        $this->db->table('clubora.training_options')->where('id', $this->field)->update(['active' => true, 'category' => 'period']);
        $this->fails(404, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        $this->db->table('clubora.training_options')->where('id', $this->field)->update(['category' => 'field']);
        $permissions = new class {
            public function allows($actor, $tenant, $module, $operation): bool { return $operation === 'view'; }
        };
        $this->app->instance(\App\ClubUnio\Services\ModulePermissionService::class, $permissions);
        $this->assertFalse($this->service->options($this->actor, $this->tenant)['capabilities']['override_field_status']);
        $this->fails(403, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        foreach (['trainer', 'technical_lead'] as $role) {
            $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => $role]);
            $this->assertFalse($this->service->options($this->actor, $this->tenant)['capabilities']['override_field_status']);
            $this->fails(403, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], $review));
        }
        $this->assertSame('pending', $this->db->table('pg_temp.pitch_requests')->where('id', $request['source_id'])->value('status'));
    }

    public function test_training_move_is_single_exception_visible_outside_original_range_and_cancellable(): void
    {
        $key = 'training:'.$this->slot.':2026-11-04';
        $base = $this->service->resolve($this->tenant, $key);
        $after = $this->service->move($this->actor, $this->tenant, $key, $this->input(['revision' => $base['revision'], 'date' => '2026-11-06', 'starts_at' => '10:00']));
        $this->assertSame($key, $after['key']);
        $this->assertSame('18:00:00', $this->db->table('clubora.training_slots')->where('id', $this->slot)->value('starts_at'));
        $original = $this->service->calendar($this->actor, $this->tenant, ['from' => '2026-11-04', 'to' => '2026-11-04']);
        $moved = $this->service->calendar($this->actor, $this->tenant, ['from' => '2026-11-06', 'to' => '2026-11-06']);
        $this->assertCount(0, $original['entries']);
        $this->assertCount(1, $moved['entries']);
        $this->fails(409, fn () => $this->service->move($this->actor, $this->tenant, $key, $this->input(['revision' => $base['revision']])));
        $this->service->cancel($this->actor, $this->tenant, $key, ['revision' => $after['revision']]);
        $this->assertCount(0, $this->service->calendar($this->actor, $this->tenant, ['from' => '2026-11-06', 'to' => '2026-11-06'])['entries']);
    }

    public function test_midnight_and_multiday_blocks_use_absolute_intervals(): void
    {
        $block = $this->service->saveBlock($this->actor, $this->tenant, $this->input(['date' => '2026-11-06', 'starts_at' => '23:00', 'duration_minutes' => 3000, 'reason' => 'Maintenance']));
        $this->fails(409, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input(['date' => '2026-11-08', 'starts_at' => '12:00'])));
        $data = $this->service->calendar($this->actor, $this->tenant, ['from' => '2026-11-08', 'to' => '2026-11-08']);
        $this->assertSame($block['key'], $data['entries'][0]['key']);
        $this->service->cancel($this->actor, $this->tenant, $block['key'], ['revision' => $block['revision']]);
        $this->service->createManual($this->actor, $this->tenant, $this->input(['date' => '2026-11-08', 'starts_at' => '23:30']));
        $this->fails(409, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input(['date' => '2026-11-09', 'starts_at' => '00:30'])));
    }

    public function test_unknown_status_blocks_and_area_from_wrong_field_is_rejected(): void
    {
        $area = $this->area(0, .5);
        $this->fails(404, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input(['field_id' => $this->field2, 'area_id' => $area['id']])));
        $this->db->table('clubora.field_availabilities')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
        $this->fails(409, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input()));
    }

    public function test_occupied_geometry_cannot_be_reshaped_and_competition_source_is_updated(): void
    {
        $area = $this->area(0, .5);
        $this->service->createManual($this->actor, $this->tenant, $this->input(['area_id' => $area['id']]));
        $this->fails(409, fn () => $this->service->saveArea($this->actor, $this->tenant, array_replace($area, ['width' => .75])));
        $before = $this->service->resolve($this->tenant, 'competition:'.$this->competition);
        $configured = $this->service->configureCompetition($this->actor, $this->tenant, $this->competition, ['revision' => $before['revision'], 'team_season_ids' => [$this->team]]);
        $after = $this->service->move($this->actor, $this->tenant, $before['key'], $this->input(['revision' => $configured['revision'], 'date' => '2026-11-07', 'duration_minutes' => 150]));
        $this->assertSame(150, $after['duration_minutes']);
        $this->assertSame([$this->team], $after['team_season_ids']);
        $this->assertSame('2026-11-07', Competition::findOrFail($this->competition)->plays_on->toDateString());
    }

    public function test_read_only_profile_and_simulation_cannot_mutate(): void
    {
        $profile = $this->insert('access_profiles', ['name' => 'Read only', 'active' => true, 'permissions' => json_encode(['competitions.view'])]);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'profile', 'access_profile_id' => $profile]);
        $this->assertTrue($this->service->canView($this->actor, $this->tenant));
        $this->assertFalse($this->service->options($this->actor, $this->tenant)['capabilities']['approve']);
        $this->fails(403, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input()));
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'admin', 'access_profile_id' => null]);
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => $this->tenant->id, 'target_id' => $this->actor->id]);
        $this->fails(403, fn () => $this->service->requestFriendly($this->actor, $this->tenant, $this->input()));
    }

    public function test_trainer_and_technical_lead_can_only_request_own_teams_and_cannot_approve(): void
    {
        $member = $this->insert('members', ['first_name' => 'Pitch', 'last_name' => 'Trainer', 'birth_date' => '1980-01-01']);
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'pitch-test']);
        $function = $this->insert('staff_functions', ['code' => 'TRAINER', 'name' => 'Trainer']);
        $staff = $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $member, 'staff_function_id' => $function]);
        $this->insert('technical_leads', ['team_season_id' => $this->team, 'member_id' => $member]);
        foreach (['trainer', 'technical_lead'] as $role) {
            $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => $role, 'portal_enabled' => true]);
            $request = $this->service->requestFriendly($this->actor, $this->tenant, $this->input());
            $this->assertSame('pending', $request['status']);
            $this->fails(403, fn () => $this->service->decide($this->actor, $this->tenant, $request['source_id'], ['revision' => $request['revision'], 'decision' => 'approved']));
            $this->fails(403, fn () => $this->service->move($this->actor, $this->tenant, $request['key'], $this->input(['revision' => $request['revision']])));
            $this->fails(403, fn () => $this->service->createManual($this->actor, $this->tenant, $this->input()));
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'trainer']);
        $this->db->table('clubora.team_staff')->where('id', $staff)->update(['active' => false]);
        $this->fails(403, fn () => $this->service->requestFriendly($this->actor, $this->tenant, $this->input()));
    }

    public function test_signage_shows_relocated_occurrence_and_preserves_legacy_without_pitch_schema(): void
    {
        $key = 'training:'.$this->slot.':2026-11-04';
        $base = $this->service->resolve($this->tenant, $key);
        $this->service->move($this->actor, $this->tenant, $key, $this->input(['revision' => $base['revision'], 'date' => '2026-11-06', 'starts_at' => '10:00', 'field_id' => $this->field2]));
        $signage = app(SignageService::class);
        $data = $signage->preview($this->actor, $this->tenant, ['date' => '2026-11-06', 'period_id' => $this->period]);
        $this->assertCount(1, $data['trainings']);
        $this->assertSame($this->field2, (int) $data['trainings']->first()->field_id);
        $this->assertSame('10:00', $data['trainings']->first()->starts_at);
        $this->app->instance(PitchPlanningProjection::class, new class extends PitchPlanningProjection { public function ready(): bool { return false; } });
        $this->assertCount(1, $signage->preview($this->actor, $this->tenant, ['date' => '2026-11-04', 'period_id' => $this->period])['trainings']);
    }

    public function test_sfv_authoritative_field_update_flags_conflicts_maps_numeric_teams_and_clears_foreign_area(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 12:00:00', 'Europe/Zurich'));
        $teamId = $this->db->table('clubora.team_seasons')->where('id', $this->team)->value('team_id');
        $this->db->table('clubora.teams')->where('id', $teamId)->update(['sfv_team_id' => 919]);
        $area = $this->area(0, .5);
        $key = 'competition:'.$this->competition;
        $base = $this->service->resolve($this->tenant, $key);
        $this->service->move($this->actor, $this->tenant, $key, $this->input(['revision' => $base['revision'], 'date' => '2026-11-05', 'starts_at' => '15:00', 'area_id' => $area['id']]));
        $this->service->saveBlock($this->actor, $this->tenant, $this->input(['date' => '2026-11-07', 'starts_at' => '15:00', 'field_id' => $this->field2, 'reason' => 'Closed']));
        $match = $this->insert('sfv_schedule', ['match_id' => 919191, 'season_id' => 2026, 'home' => true, 'title' => 'A - B', 'host_name' => 'A', 'guest_name' => 'B',
            'team_a_id' => 919, 'team_b_id' => 920, 'starts_at' => '2026-11-07 14:00:00+00', 'location' => 'Tannenbrunn - 2, Sissach', 'state' => 'Angesetzt']);
        $sfv = app(SfvCompetitionService::class);
        $preview = $sfv->preview($this->actor, $this->tenant, $match, $this->competition);
        $impact = app(\App\ClubUnio\Services\SfvRescheduleImpact::class);
        $preview['impact'] = $impact->preview($this->actor, $this->tenant, $preview);
        $this->assertSame('block', $preview['impact']['conflicts'][0]['type']);
        try {
            $sfv->applyBatch($this->actor, $this->tenant, [$preview], requireSingleReview: true);
            $this->fail('The web batch bypassed mandatory single review');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(trans('sfv_reschedule.single_required'), $exception->errors()['match'][0]);
        }
        try {
            $sfv->apply($this->actor, $this->tenant, $preview, reschedule: true);
            $this->fail('A conflicted reschedule was approved');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(trans('sfv_reschedule.blocked'), $exception->errors()['match'][0]);
        }
        $this->assertSame($area['id'], $this->service->resolve($this->tenant, $key)['area_id']);
        $sfv->apply($this->actor, $this->tenant, $preview);
        $entry = $this->service->resolve($this->tenant, $key);
        $this->assertSame($this->field2, $entry['field_id']);
        $this->assertNull($entry['area_id']);
        $this->assertSame([$this->team], $entry['team_season_ids']);
        $this->assertSame('approved', $entry['status']);
        $event = end($this->events);
        $this->assertSame('sfv_moved', $event['action']);
        $this->assertSame('block', $event['after']['conflicts'][0]['type']);
        $this->db->table('clubora.sfv_schedule')->where('id', $match)->update(['state' => 'Abgesagt', 'revision' => 2]);
        $sfv->apply($this->actor, $this->tenant, $sfv->previewWithdrawal($this->actor, $this->tenant, $match));
        $this->assertSame('cancelled', $this->service->resolve($this->tenant, $key)['status']);
        $calendar = $this->service->calendar($this->actor, $this->tenant, ['from' => '2026-11-07', 'to' => '2026-11-07']);
        $this->assertNotContains($key, array_column($calendar['entries'], 'key'));
        $this->travelBack();
    }

    public function test_database_composite_area_constraint_rejects_wrong_field_even_without_service(): void
    {
        $area = $this->area(0, .5);
        try {
            $this->db->transaction(fn () => $this->insert('pitch_requests', $this->input(['field_id' => $this->field2, 'area_id' => $area['id']]) + ['requester_account_id' => $this->actor->id]));
            $this->fail('Composite area boundary missing');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame('23503', $error->getCode());
        }
        $this->assertSame(0, $this->db->table('pg_temp.pitch_requests')->count());
    }

    public function test_reviewed_sfv_move_updates_the_linked_team_event_and_records_both_notifications(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 12:00:00', 'Europe/Zurich'));
        \App\ClubUnio\Services\TeamPlanningService::seedOptions($this->tenant->id);
        $this->db->table('clubora.competitions')->where('id', $this->competition)
            ->update(['association_match_id' => 818181]);
        $match = $this->insert('sfv_schedule', ['match_id' => 818181, 'season_id' => 2026, 'home' => true,
            'title' => 'A - B', 'host_name' => 'A', 'guest_name' => 'B',
            'starts_at' => '2026-11-07 14:00:00+00', 'location' => 'Tannenbrunn - 2, Sissach', 'state' => 'Neu angesetzt']);
        $event = $this->insert('team_events', ['team_season_id' => $this->team, 'sfv_match_id' => 818181,
            'title' => 'A - B', 'kind' => 'MEISTERSCHAFT', 'mode' => 'ABMELDEN', 'description' => 'Fixture description',
            'starts_at' => '2026-11-05 14:00:00+00', 'ends_at' => '2026-11-05 15:30:00+00',
            'deadline_at' => '2026-11-04 14:00:00+00']);
        $sfv = app(SfvCompetitionService::class);
        $preview = $sfv->preview($this->actor, $this->tenant, $match);
        $impact = app(\App\ClubUnio\Services\SfvRescheduleImpact::class);
        $preview['impact'] = $impact->preview($this->actor, $this->tenant, $preview);
        $this->assertSame([], $preview['impact']['conflicts']);
        $this->assertSame('2026-11-07T15:00', $preview['impact']['events'][0]['input']['starts_at']);

        $this->db->table('clubora.team_events')->where('id', $event)->update(['revision' => 2]);
        try {
            $sfv->apply($this->actor, $this->tenant, $preview, reschedule: true);
            $this->fail('Stale reschedule was approved');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(trans('sfv_reschedule.stale'), $exception->errors()['match'][0]);
        }
        $preview['impact'] = $impact->preview($this->actor, $this->tenant, $preview);
        $sfv->apply($this->actor, $this->tenant, $preview, reschedule: true);
        $updated = $this->db->table('clubora.team_events')->where('id', $event)->firstOrFail();
        $this->assertSame('2026-11-07 16:30', \Carbon\CarbonImmutable::parse($updated->ends_at)->tz('Europe/Zurich')->format('Y-m-d H:i'));
        $this->assertSame('Fixture description', $updated->description);
        $this->assertSame('team.changed', $this->db->table('clubora.team_planning_messages')->where('event_id', $event)->value('event_key'));
        $this->assertSame('sfv_moved', end($this->events)['action']);
        $this->travelBack();
    }

    public function test_noop_move_does_not_change_revision_or_notify(): void
    {
        $entry = $this->service->createManual($this->actor, $this->tenant, $this->input());
        $after = $this->service->move($this->actor, $this->tenant, $entry['key'], $this->input(['revision' => $entry['revision']]));
        $this->assertSame($entry['revision'], $after['revision']);
        $this->assertSame(['created'], array_column($this->events, 'action'));
    }
}
