<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\FacilityService;
use App\ClubUnio\Services\MessageEventCatalog;
use App\ClubUnio\Services\PitchPlanningNotificationService;
use App\ClubUnio\Services\PitchPlanningProjection;
use App\ClubUnio\Services\PitchPlanningService;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** All source, audit and notification writes target pg_temp; no permanent fixtures. */
class PitchCompetitionEditsTest extends TestCase
{
    private $db;

    private $grammar;

    private Tenant $tenant;

    private Account $actor;

    private Competition $competition;

    private int $field;

    private int $otherField;

    private int $room;

    private int $area;

    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to PostgreSQL TEMP-only tests.');
        }
        Http::preventStrayRequests();
        Mail::fake();
        config(['clubunio-mobile-push.enabled' => false, 'clubunio-mobile-push.delivery_enabled' => false,
            'clubunio-integrations.test_mail_enabled' => false, 'clubunio-integrations.pitch_planning_test_mail_enabled' => false]);
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->beginTransaction();
        foreach (['tenants', 'accounts', 'tenant_accounts', 'competitions', 'teams', 'seasons', 'team_seasons',
            'object_changes', 'training_options', 'training_slots', 'tenant_settings', 'facility_statuses', 'field_availabilities',
            'members', 'member_statuses', 'team_staff', 'account_member_links', 'lookup_translations', 'football_clubs',
            'notifications', 'mobile_inbox', 'mobile_push_outbox', 'message_templates', 'message_template_revisions',
            'account_roles', 'access_profiles'] as $table) {
            $this->db->statement("CREATE TEMP TABLE $table (LIKE clubunio.$table INCLUDING ALL) ON COMMIT DROP");
        }
        foreach (['001500_pitch_planning', '001600_pitch_planning_notifications'] as $name) {
            $queries = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_'.$name.'.php'))->up());
            foreach ($queries as $query) {
                if (! str_contains($query['query'], 'CREATE TABLE clubunio.')) {
                    continue;
                }
                $sql = str_replace(['clubunio.', 'CREATE TABLE '], ['pg_temp.', 'CREATE TEMP TABLE '], $query['query']);
                $this->assertStringNotContainsString('clubunio.', $sql);
                $this->db->unprepared($sql);
            }
        }
        $this->db->setQueryGrammar(new class($this->db) extends PostgresGrammar
        {
            public function wrapTable($table, $prefix = null)
            {
                return parent::wrapTable(str_replace('clubunio.', 'pg_temp.', $table), $prefix);
            }

            public function wrap($value)
            {
                return parent::wrap(is_string($value) ? str_replace('clubunio.', 'pg_temp.', $value) : $value);
            }
        });
        $this->db->beforeExecuting(function ($sql) {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)/i', $sql) && str_contains($sql, 'clubunio')) {
                throw new \LogicException('Permanent writes forbidden in competition edit tests');
            }
        });
        $this->app->instance(PitchPlanningProjection::class, new class extends PitchPlanningProjection
        {
            public function ready(): bool
            {
                return true;
            }
        });
        $this->tenant = Tenant::create(['slug' => 'pitch-edits', 'name' => 'Pitch edits']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'pitch-editor@example.invalid', 'display_name' => 'Editor', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $this->field = $this->insert('training_options', ['category' => 'field', 'code' => 'A', 'name' => 'Field A']);
        $this->otherField = $this->insert('training_options', ['category' => 'field', 'code' => 'B', 'name' => 'Field B']);
        $this->room = $this->insert('training_options', ['category' => 'room', 'code' => 'R', 'name' => 'Room']);
        $status = $this->insert('facility_statuses', ['code' => 'OPEN', 'name' => 'Open', 'blocks_training' => false]);
        foreach ([$this->field, $this->otherField] as $field) {
            $this->insert('field_availabilities', ['field_id' => $field, 'status_id' => $status]);
        }
        $this->competition = Competition::findOrFail($this->insert('competitions', ['plays_on' => '2026-11-05', 'starts_at' => '15:00',
            'field_id' => $this->field, 'host_name' => 'A', 'guest_name' => 'B', 'show_on_signage' => false,
            'association_match_id' => 99123, 'legacy_id' => 123, 'host_room_id' => $this->room, 'guest_room_id' => $this->room]));
        $this->area = $this->insert('pitch_areas', ['field_id' => $this->field, 'name_de' => 'Links', 'name_en' => 'Left', 'x' => 0, 'y' => 0, 'width' => .5, 'height' => 1]);
        $this->insert('pitch_competition_settings', ['competition_id' => $this->competition->id, 'area_id' => $this->area, 'duration_minutes' => 150]);
        $season = $this->insert('seasons', ['name' => 'Season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $team = $this->insert('teams', ['name' => 'Team']);
        $this->team = $this->insert('team_seasons', ['season_id' => $season, 'team_id' => $team, 'label' => 'Team']);
        $this->db->table('clubunio.pitch_competition_teams')->insert(['tenant_id' => $this->tenant->id, 'competition_id' => $this->competition->id, 'team_season_id' => $this->team]);
        foreach (['de' => 'de-CH', 'en' => 'en-GB'] as $language => $locale) {
            foreach (['pitch.booking.moved', 'pitch.booking.created', 'pitch.booking.cancelled'] as $key) {
                $template = MessageEventCatalog::all($language)[$key];
                $this->insert('message_templates', ['event_key' => $key, 'channel' => 'push', 'locale' => $locale,
                    'title' => $template['title'], 'body' => $template['body'], 'active' => true]);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
            $this->db->setQueryGrammar($this->grammar);
            DB::purge('clubunio');
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubunio.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function save(array $changes = []): Competition
    {
        $row = $this->competition->fresh();
        $input = $row->only(['active', 'revision', 'starts_at', 'field_id', 'host_name', 'guest_name', 'show_on_signage']);
        $input['plays_on'] = $row->plays_on->toDateString();

        return app(FacilityService::class)->save($this->actor, $this->tenant, Competition::class, $row->id, array_replace($input, $changes));
    }

    private function block(int $field, string $date = '2026-11-05', string $time = '15:00'): void
    {
        $this->insert('pitch_blocks', ['field_id' => $field, 'date' => $date, 'starts_at' => $time, 'duration_minutes' => 120, 'reason' => 'Blocked']);
    }

    private function fails(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$status);
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function events(): array
    {
        return $this->db->table('clubunio.pitch_planning_events')->orderBy('id')->get()->map(fn ($row) => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))->all();
    }

    public function test_room_text_and_equivalent_time_edits_do_not_check_existing_conflicts_or_notify(): void
    {
        $this->block($this->field);
        $saved = $this->save(['host_room_id' => null, 'remarks' => 'Keep free text', 'starts_at' => '15:00']);
        $this->assertNull($saved->host_room_id);
        $this->assertSame('Keep free text', $saved->remarks);
        $this->save();
        $this->assertSame([], $this->events());
        $this->assertSame(0, $this->db->table('clubunio.object_changes')->where('object_type', 'pitch_planning')->count());
        $this->assertSame($this->area, (int) $this->db->table('clubunio.pitch_competition_settings')->value('area_id'));
    }

    public function test_real_move_clears_old_area_permanently_preserves_source_and_notifies_staff_in_de_en(): void
    {
        foreach (['de', 'en'] as $language) {
            $account = new Account;
            $account->forceFill(['username' => $language.'@example.invalid', 'display_name' => $language, 'password' => bcrypt('fixture'), 'active' => true, 'preferred_locale' => $language])->save();
            $this->insert('tenant_accounts', ['account_id' => $account->id, 'role' => 'trainer', 'portal_enabled' => true]);
            $member = $this->insert('members', ['first_name' => 'Staff', 'last_name' => $language]);
            $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $member]);
            $this->insert('account_member_links', ['account_id' => $account->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        }
        $source = $this->competition->only(['id', 'association_match_id', 'legacy_id', 'host_room_id', 'guest_room_id']);
        $saved = $this->save(['field_id' => $this->otherField]);
        $this->assertSame($source, $saved->only(array_keys($source)));
        $settings = $this->db->table('clubunio.pitch_competition_settings')->first();
        $this->assertNull($settings->area_id);
        $this->assertSame(2, $settings->revision);
        $this->assertSame(150, $settings->duration_minutes);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('moved', $events[0]['action']);
        $this->assertSame($this->field, $events[0]['before']['field_id']);
        $this->assertSame($this->otherField, $events[0]['after']['field_id']);
        $this->assertSame([$this->team], $events[0]['team_season_ids']);
        $titles = $this->db->table('clubunio.notifications')->pluck('title')->all();
        $this->assertContains('Platzbelegung verschoben', $titles);
        $this->assertContains('Pitch booking moved', $titles);
        $this->assertSame(2, $this->db->table('clubunio.mobile_inbox')->count());
        $this->assertSame(0, $this->db->table('clubunio.pitch_planning_events')->whereNotNull('payload_encrypted')->count());
        $this->save(['field_id' => $this->field]);
        $this->assertNull($this->db->table('clubunio.pitch_competition_settings')->value('area_id'));
        $this->assertCount(2, $this->events());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_direct_sfv_linked_moves_date_time_and_reactivation_have_normal_conflict_checks(): void
    {
        $this->block($this->otherField);
        $this->block($this->field, '2026-11-06');
        $this->block($this->field, '2026-11-05', '20:00');
        foreach ([['field_id' => $this->otherField], ['plays_on' => '2026-11-06'], ['starts_at' => '20:00']] as $change) {
            $this->fails(409, fn () => $this->save($change));
        }
        $this->assertSame(1, (int) $this->competition->fresh()->revision);
        $this->assertSame([], $this->events());
        $this->save(['active' => false]);
        $this->block($this->field);
        $this->fails(409, fn () => $this->save(['active' => true]));
        $this->assertFalse($this->competition->fresh()->active);
        $this->assertSame(['cancelled'], array_column($this->events(), 'action'));
    }

    public function test_legacy_missing_field_or_time_allows_unrelated_edits_and_checks_completion(): void
    {
        $this->competition->forceFill(['starts_at' => null, 'field_id' => null])->save();
        $this->save(['remarks' => 'Unknown placement']);
        $this->assertSame([], $this->events());
        $this->save(['field_id' => $this->otherField]);
        $this->assertSame([], $this->events());
        $this->assertNull($this->db->table('clubunio.pitch_competition_settings')->value('area_id'));
        $this->block($this->otherField);
        $this->fails(409, fn () => $this->save(['starts_at' => '15:00']));
        $this->save(['starts_at' => '18:00']);
        $this->assertSame(['created'], array_column($this->events(), 'action'));
        $this->save(['starts_at' => null]);
        $events = $this->events();
        $this->assertSame('cancelled', $events[1]['action']);
        $this->assertSame('', $events[1]['after']['starts_at']);
        $this->assertSame('unplaced', $events[1]['after']['status']);
        $this->save(['host_room_id' => null]);
        $this->assertCount(2, $this->events());
    }

    public function test_source_audit_area_and_fanout_rollback_together_on_notification_failure(): void
    {
        $member = $this->insert('members', ['first_name' => 'Staff', 'last_name' => 'Rollback']);
        $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $member]);
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $this->app->instance(PitchPlanningNotificationService::class, new class extends PitchPlanningNotificationService
        {
            public function record(Tenant $tenant, array $event): void
            {
                parent::record($tenant, $event);
                if (DB::connection('clubunio')->table('clubunio.mobile_inbox')->count() !== 1) {
                    throw new \RuntimeException('Expected transactional inbox fixture');
                }
                throw new \RuntimeException('Synthetic fanout failure');
            }
        });
        try {
            $this->save(['field_id' => $this->otherField]);
            $this->fail('Expected fanout failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic fanout failure', $error->getMessage());
        }
        $this->assertSame($this->field, (int) $this->competition->fresh()->field_id);
        $this->assertSame(1, (int) $this->competition->fresh()->revision);
        $this->assertSame($this->area, (int) $this->db->table('clubunio.pitch_competition_settings')->value('area_id'));
        $this->assertSame(1, $this->db->table('clubunio.pitch_competition_settings')->value('revision'));
        foreach (['object_changes', 'pitch_planning_events', 'notifications', 'mobile_inbox'] as $table) {
            $this->assertSame(0, $this->db->table('clubunio.'.$table)->count());
        }
    }

    public function test_stale_revision_and_fresh_profile_authority_are_enforced_without_revoking_editor_rights(): void
    {
        try {
            $this->save(['revision' => 99, 'field_id' => $this->otherField]);
            $this->fail('Expected stale revision');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('revision', $error->errors());
        }
        $this->db->table('clubunio.account_roles')->insert(['code' => 'profile', 'name' => 'Profile', 'description' => 'Fixture']);
        $profile = $this->insert('access_profiles', ['name' => 'Competition editor', 'permissions' => json_encode(['competitions.view', 'competitions.update'])]);
        $this->db->table('clubunio.tenant_accounts')->update(['role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false]);
        $this->save(['field_id' => $this->otherField]);
        $this->assertCount(1, $this->events());
        $this->fails(403, fn () => $this->save(['active' => false]));
        $frame = ['account' => $this->actor->id, 'tenants' => collect([$this->tenant]), 'permissionCache' => []];
        request()->attributes->set(BackofficeReadContext::class, $frame);
        $this->db->table('clubunio.access_profiles')->where('id', $profile)->update(['permissions' => json_encode(['competitions.view'])]);
        $this->fails(403, fn () => $this->save(['field_id' => $this->field]));
        $this->assertSame($frame, request()->attributes->get(BackofficeReadContext::class));
        $this->db->table('clubunio.tenant_accounts')->update(['role' => 'admin', 'access_profile_id' => null]);
        $this->db->table('clubunio.accounts')->where('id', $this->actor->id)->update(['active' => false]);
        $this->fails(403, fn () => $this->save(['field_id' => $this->field]));
        $this->assertCount(1, $this->events());
    }

    public function test_cross_field_candidate_is_whole_field_before_conflict_check(): void
    {
        $right = $this->insert('pitch_areas', ['field_id' => $this->otherField, 'name_de' => 'Rechts', 'name_en' => 'Right',
            'x' => .5, 'y' => 0, 'width' => .5, 'height' => 1]);
        $this->insert('pitch_blocks', ['field_id' => $this->otherField, 'area_id' => $right, 'date' => '2026-11-05',
            'starts_at' => '15:00', 'duration_minutes' => 120, 'reason' => 'Right half occupied']);
        // Keeping the old left-half geometry would wrongly allow this move.
        $this->fails(409, fn () => $this->save(['field_id' => $this->otherField]));
        $this->assertSame($this->field, (int) $this->competition->fresh()->field_id);
        $this->assertSame($this->area, (int) $this->db->table('clubunio.pitch_competition_settings')->value('area_id'));
        $this->assertSame([], $this->events());
    }

    public function test_direct_creation_uses_saved_identity_and_removing_field_cancels_without_inventing_placement(): void
    {
        $input = ['plays_on' => '2026-11-05', 'starts_at' => '15:00', 'field_id' => $this->field,
            'active' => true, 'show_on_signage' => false, 'host_name' => 'Local', 'guest_name' => 'Visitor'];
        $service = app(FacilityService::class);
        $this->fails(409, fn () => $service->save($this->actor, $this->tenant, Competition::class, null, $input));
        $saved = $service->save($this->actor, $this->tenant, Competition::class, null, array_replace($input, ['field_id' => $this->otherField]));
        $this->assertSame('competition:'.$saved->id, $this->events()[0]['after']['key']);
        $this->assertSame('created', $this->events()[0]['action']);
        $this->save(['field_id' => null]);
        $this->assertSame('cancelled', $this->events()[1]['action']);
        $this->assertNull($this->events()[1]['after']['field_id']);
        $this->save(['remarks' => 'Still no field']);
        $this->assertCount(2, $this->events());
    }

    public function test_missing_planner_schema_preserves_legacy_source_save(): void
    {
        $this->app->instance(PitchPlanningProjection::class, new class extends PitchPlanningProjection
        {
            public function ready(): bool
            {
                return false;
            }
        });
        $saved = $this->save(['field_id' => $this->otherField]);
        $this->assertSame($this->otherField, (int) $saved->field_id);
        $this->assertSame([], $this->events());
    }

    public function test_planner_move_emits_one_event_and_followup_room_save_emits_none(): void
    {
        $service = app(PitchPlanningService::class);
        $entry = $service->resolve($this->tenant, 'competition:'.$this->competition->id);
        $service->move($this->actor, $this->tenant, $entry['key'], ['revision' => $entry['revision'], 'field_id' => $this->otherField,
            'date' => '2026-11-05', 'starts_at' => '15:00', 'duration_minutes' => 150, 'area_id' => null]);
        $this->save(['guest_room_id' => null]);
        $this->assertCount(1, $this->events());
    }
}
