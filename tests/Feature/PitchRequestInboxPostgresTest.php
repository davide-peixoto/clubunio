<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobilePitchDocumentation, MobileReadContext};
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{LookupTranslations, PitchPlanningProjection, PitchPlanningService};
use Carbon\Carbon;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PG queries/constraints, temporary pitch tables and rollback-only synthetic parents. */
class PitchRequestInboxPostgresTest extends TestCase
{
    private $db;
    private $grammar;
    private Tenant $tenant;
    private Tenant $foreign;
    private Account $actor;
    private Account $other;
    private PitchPlanningService $service;
    private int $team;
    private int $foreignTeam;
    private int $field;
    private int $foreignField;
    private const TABLES = ['pitch_areas', 'pitch_training_exceptions', 'pitch_requests', 'pitch_blocks',
        'pitch_competition_settings', 'pitch_competition_teams'];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt in to synthetic PostgreSQL rollback tests.');
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-04 23:30:00', 'UTC'));
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->beginTransaction();
        $suffix = bin2hex(random_bytes(8));
        $this->tenant = Tenant::create(['slug' => 'inbox-'.$suffix, 'name' => 'Inbox fixture']);
        $this->foreign = Tenant::create(['slug' => 'inbox-foreign-'.$suffix, 'name' => 'Foreign fixture']);
        $this->actor = (new Account)->forceFill(['username' => 'inbox-'.$suffix, 'display_name' => 'Inbox actor',
            'password' => bcrypt('fixture'), 'active' => true]);
        $this->actor->save();
        $this->other = (new Account)->forceFill(['username' => 'inbox-other-'.$suffix, 'display_name' => 'Other actor',
            'password' => bcrypt('fixture'), 'active' => true]);
        $this->other->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin', 'portal_enabled' => true]);
        $this->db->table('clubora.mobile_module_options')->insert(['tenant_id' => $this->tenant->id,
            'key' => 'Platzverfugbarkeit', 'name' => 'Pitch', 'active' => true, 'sort_order' => 1]);
        foreach ([$this->tenant, $this->foreign] as $tenant) {
            $season = $this->insert('seasons', ['tenant_id' => $tenant->id, 'name' => '2026/27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
            $team = $this->insert('teams', ['tenant_id' => $tenant->id, 'name' => 'Original team']);
            $team = $this->insert('team_seasons', ['tenant_id' => $tenant->id, 'season_id' => $season, 'team_id' => $team, 'label' => 'Inbox team']);
            $field = $this->insert('training_options', ['tenant_id' => $tenant->id, 'category' => 'field', 'code' => 'INBOX', 'name' => 'Original pitch']);
            $status = $this->insert('facility_statuses', ['tenant_id' => $tenant->id, 'code' => 'INBOX', 'name' => 'Open', 'blocks_training' => false]);
            $this->insert('field_availabilities', ['tenant_id' => $tenant->id, 'field_id' => $field, 'status_id' => $status]);
            $this->insert('tenant_settings', ['tenant_id' => $tenant->id, 'area' => 'general', 'key' => 'SaisonID',
                'label' => 'Season', 'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $season)]);
            if ($tenant->id === $this->tenant->id) { $this->team = $team; $this->field = $field; }
            else { $this->foreignTeam = $team; $this->foreignField = $field; }
        }
        $member = $this->insert('members', ['first_name' => 'Inbox', 'last_name' => 'Staff', 'birth_date' => '1980-01-01']);
        $this->insert('account_member_links', ['account_id' => $this->actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'inbox-test']);
        $function = $this->insert('staff_functions', ['code' => 'TRAINER', 'name' => 'Trainer']);
        $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $member, 'staff_function_id' => $function]);
        $this->insert('technical_leads', ['team_season_id' => $this->team, 'member_id' => $member]);

        foreach (['tenants', 'accounts', 'training_options', 'training_slots', 'team_seasons', 'competitions'] as $table) {
            $this->db->statement("CREATE TEMP TABLE $table (LIKE clubora.$table INCLUDING ALL) ON COMMIT DROP");
            $column = in_array($table, ['tenants', 'accounts'], true) ? 'id' : 'tenant_id';
            $ids = $table === 'accounts' ? [$this->actor->id, $this->other->id] : [$this->tenant->id, $this->foreign->id];
            $this->db->statement("INSERT INTO pg_temp.$table SELECT * FROM clubora.$table WHERE $column IN (?, ?)", $ids);
        }
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_001500_pitch_planning.php'))->up());
        foreach ($ddl as $query) $this->db->statement(str_replace(['clubora.', 'CREATE TABLE '], ['pg_temp.', 'CREATE TEMP TABLE '], $query['query']));
        $this->db->setQueryGrammar(new class($this->db, self::TABLES) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function __construct($connection, private array $tables) { parent::__construct($connection); }
            public function wrapTable($table, $prefix = null) {
                if (in_array(str_replace('clubora.', '', $table), $this->tables, true)) $table = str_replace('clubora.', 'pg_temp.', $table);
                return parent::wrapTable($table, $prefix);
            }
        });
        $projection = new class extends PitchPlanningProjection { public function ready(): bool { return true; } };
        $this->service = new PitchPlanningService($projection);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) $this->db->rollBack();
            if ($this->grammar) $this->db->setQueryGrammar($this->grammar);
            Http::assertNothingSent();
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
        }
        $this->travelBack();
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function booking(array $data = []): int
    {
        return $this->insert('pitch_requests', $data + ['field_id' => $this->field, 'team_season_id' => $this->team,
            'requester_account_id' => $this->actor->id, 'date' => '2026-11-05', 'starts_at' => '10:00',
            'duration_minutes' => 120, 'opponent' => 'Unchanged free text', 'notes' => 'PRIVATE NOTE']);
    }

    private function role(string $role, array $data = []): void
    {
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)
            ->where('account_id', $this->actor->id)->update($data + ['role' => $role]);
    }

    private function inbox(array $filters = []): array
    {
        $request = request();
        $request->setMethod('GET');
        $request->attributes->set('mobile.actor', $this->actor);
        $request->attributes->set('mobile.session', (object) ['id' => 'inbox-test', 'account_id' => $this->actor->id]);
        $request->attributes->remove('mobile.current_season.'.$this->tenant->id.'.'.now('Europe/Zurich')->toDateString());
        app()->forgetInstance(LookupTranslations::class);
        return app(MobileReadContext::class)->during($request, $this->actor,
            fn () => $this->service->requests($this->actor, $this->tenant, $filters));
    }

    private function forbidden(callable $action): void
    {
        try { $action(); $this->fail('Expected HTTP 403.'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function test_personal_trainer_and_tl_only_own_today_and_future_across_dates_and_statuses(): void
    {
        $this->booking(['date' => '2026-11-04', 'starts_at' => '23:30']);
        $today = $this->booking();
        $future = $this->booking(['date' => '2028-01-01', 'status' => 'approved']);
        $rejected = $this->booking(['date' => '2026-12-25', 'status' => 'rejected']);
        $cancelled = $this->booking(['status' => 'cancelled']);
        $this->booking(['requester_account_id' => $this->other->id]);
        $this->booking(['tenant_id' => $this->foreign->id, 'team_season_id' => $this->foreignTeam, 'field_id' => $this->foreignField]);
        foreach (['trainer', 'technical_lead'] as $role) {
            $this->role($role);
            $data = $this->inbox();
            $this->assertSame([$cancelled, $rejected, $future, $today], array_column($data['entries'], 'source_id'));
            $this->assertSame(['view' => true, 'manage' => false, 'approve' => false, 'request' => true], $data['capabilities']);
            $this->assertFalse($data['can_filter_status']);
            $this->assertSame([$this->team], array_column($data['teams'], 'id'));
            $required = (new MobilePitchDocumentation)->specification()['components']['schemas']['RequestInboxEntry']['required'];
            foreach ($data['entries'] as $entry) {
                $this->assertEqualsCanonicalizing($required, array_keys($entry));
                $this->assertSame('request', $entry['source']);
                $this->assertSame('Unchanged free text', $entry['title']);
                $this->assertFalse($entry['editable']);
            }
            foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status) $this->forbidden(fn () => $this->inbox(['status' => $status]));
            $this->forbidden(fn () => $this->service->decide($this->actor, $this->tenant, $today, ['revision' => '1', 'decision' => 'approved']));
            $this->forbidden(fn () => $this->service->move($this->actor, $this->tenant, 'request:'.$today, []));
        }
    }

    public function test_office_and_admin_all_authors_history_and_status_before_pagination(): void
    {
        $ids = [];
        foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status) {
            $ids[$status] = $this->booking(['date' => '2020-01-01', 'status' => $status, 'requester_account_id' => $this->other->id]);
        }
        $this->booking(['tenant_id' => $this->foreign->id, 'team_season_id' => $this->foreignTeam, 'field_id' => $this->foreignField]);
        foreach (['admin', 'office'] as $role) {
            $this->role($role);
            $all = $this->inbox();
            $this->assertSame(array_reverse(array_values($ids)), array_column($all['entries'], 'source_id'));
            $this->assertTrue($all['can_filter_status']);
            foreach ($ids as $status => $id) {
                $data = $this->inbox(['status' => $status, 'per_page' => 1]);
                $this->assertSame([$id], array_column($data['entries'], 'source_id'));
                $this->assertFalse($data['has_more']);
                $this->assertSame(in_array($status, ['pending', 'approved'], true), $data['entries'][0]['editable']);
                $this->assertArrayNotHasKey('requester_account_id', $data['entries'][0]);
                $this->assertArrayNotHasKey('notes', $data['entries'][0]);
            }
        }
        $this->forbidden(fn () => $this->service->requests($this->actor, $this->foreign));
    }

    public function test_pagination_defaults_ties_empty_pages_and_read_only_sql(): void
    {
        $ids = [];
        for ($i = 0; $i < 35; $i++) $ids[] = $this->booking();
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $one = $this->inbox();
        $two = $this->inbox(['page' => 2]);
        $this->assertSame(1, $one['page']);
        $this->assertTrue($one['has_more']);
        $this->assertSame(2, $two['page']);
        $this->assertFalse($two['has_more']);
        $this->assertCount(30, $one['entries']);
        $this->assertCount(5, $two['entries']);
        $this->assertSame(array_reverse($ids), array_column(array_merge($one['entries'], $two['entries']), 'source_id'));
        $this->assertSame($one, $this->inbox());
        $empty = $this->inbox(['page' => 3]);
        $this->assertSame([], $empty['entries']);
        $this->assertFalse($empty['has_more']);
        $this->assertCount(35, $this->inbox(['per_page' => 100])['entries']);
        foreach ($this->db->getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        $this->db->disableQueryLog();
    }

    public function test_archived_fields_areas_and_historical_teams_are_display_only(): void
    {
        $area = $this->insert('pitch_areas', ['field_id' => $this->field, 'name_de' => 'Links', 'name_en' => 'Left',
            'x' => 0, 'y' => 0, 'width' => .5, 'height' => 1]);
        $id = $this->booking(['date' => '2020-01-01', 'area_id' => $area]);
        $this->db->table('clubora.training_options')->where('id', $this->field)->update(['active' => false]);
        try {
            $this->service->requestFriendly($this->actor, $this->tenant, ['field_id' => $this->field, 'area_id' => $area,
                'team_season_id' => $this->team, 'date' => '2026-11-05', 'starts_at' => '12:00', 'opponent' => 'New request']);
            $this->fail('Archived fields cannot be submitted as new-request targets.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame(1, $this->db->table('clubora.pitch_requests')->count());
        $this->db->table('clubora.team_seasons')->where('id', $this->team)->update(['active' => false]);
        foreach (config('clubunio-languages.enabled') as $locale => $label) {
            app()->setLocale($locale);
            $data = $this->inbox();
            $this->assertSame([], $data['teams']);
            $this->assertFalse($data['capabilities']['request']);
            $this->assertSame([$id], array_column($data['entries'], 'source_id'));
            $this->assertSame([$this->field], array_column($data['fields'], 'id'));
            $this->assertFalse($data['fields'][0]['available']);
            $this->assertSame($area, $data['fields'][0]['areas'][0]['id']);
            $this->assertSame($locale === 'en' ? 'Left' : 'Links', $data['fields'][0]['areas'][0]['name']);
            $this->assertSame([], $data['entries'][0]['conflicts']);
            $this->assertSame([$this->team], $data['entries'][0]['team_season_ids']);
            $this->assertSame([], $this->inbox(['page' => 2])['fields']);
            $this->assertSame([], $this->service->options($this->actor, $this->tenant)['fields']);
        }
    }

    public function test_inbox_skips_conflicts_but_decision_still_rechecks_occupancy(): void
    {
        $this->booking(['status' => 'approved', 'requester_account_id' => $this->other->id]);
        $this->insert('pitch_blocks', ['field_id' => $this->field, 'date' => '2026-11-04',
            'starts_at' => '23:00', 'duration_minutes' => 1440, 'reason' => 'Closure']);
        $own = $this->booking();
        $this->role('trainer');
        $data = $this->inbox(['per_page' => 1]);
        $this->assertSame([$own], array_column($data['entries'], 'source_id'));
        $this->assertFalse($data['has_more']);
        $this->assertSame([], $data['entries'][0]['conflicts']);
        $this->role('admin');
        try {
            $this->service->decide($this->actor, $this->tenant, $own, ['revision' => '1', 'decision' => 'approved']);
            $this->fail('Approval must recheck actual occupancy.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('pending', $this->db->table('clubora.pitch_requests')->where('id', $own)->value('status'));
    }

    public function test_role_profile_portal_and_account_revocation_do_not_reuse_old_authority(): void
    {
        $this->booking();
        $this->assertTrue($this->inbox()['can_filter_status']);
        $profile = $this->insert('access_profiles', ['name' => 'Read only', 'active' => true, 'permissions' => json_encode(['competitions.view'])]);
        $this->role('profile', ['access_profile_id' => $profile, 'portal_enabled' => false]);
        $this->assertTrue($this->service->canView($this->actor, $this->tenant));
        $this->forbidden(fn () => $this->inbox());
        $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['active' => false]);
        $this->assertFalse($this->service->canView($this->actor, $this->tenant));
        $this->forbidden(fn () => $this->inbox());
        $this->role('member', ['access_profile_id' => null, 'portal_enabled' => true]);
        $this->forbidden(fn () => $this->inbox());
        foreach (['trainer', 'technical_lead'] as $role) {
            $this->role($role, ['portal_enabled' => true]);
            $this->assertFalse($this->inbox()['can_filter_status']);
            $this->role($role, ['portal_enabled' => false]);
            $this->forbidden(fn () => $this->inbox());
        }
        $this->role('admin');
        $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => false]);
        $this->forbidden(fn () => $this->inbox());
        $this->db->table('clubora.accounts')->where('id', $this->actor->id)->update(['active' => true]);
        $this->db->table('clubora.account_member_links')->where('tenant_id', $this->tenant->id)->delete();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->delete();
        $this->forbidden(fn () => $this->inbox());
    }

    public function test_revoked_team_assignments_remove_creation_options_not_own_inbox_history(): void
    {
        $id = $this->booking();
        foreach (['trainer' => 'team_staff', 'technical_lead' => 'technical_leads'] as $role => $table) {
            $this->role($role);
            $this->assertTrue($this->inbox()['capabilities']['request']);
            $this->db->table('clubora.'.$table)->where('tenant_id', $this->tenant->id)->update(['active' => false]);
            $data = $this->inbox();
            $this->assertSame([], $data['teams']);
            $this->assertFalse($data['capabilities']['request']);
            $this->assertSame([$id], array_column($data['entries'], 'source_id'));
            $this->forbidden(fn () => $this->service->authorizeFriendlyRequest($this->actor, $this->tenant, $this->team));
        }
        $this->db->table('clubora.account_member_links')->where('tenant_id', $this->tenant->id)->update(['state' => 'pending']);
        $this->forbidden(fn () => $this->inbox());
    }

    public function test_query_count_is_bounded_for_one_thirty_and_one_hundred_distinct_dates(): void
    {
        $rows = [];
        for ($i = 0; $i < 100; $i++) $rows[] = ['tenant_id' => $this->tenant->id, 'field_id' => $this->field,
            'team_season_id' => $this->team, 'requester_account_id' => $this->actor->id,
            'date' => Carbon::parse('2026-11-05')->addDays($i * 10)->toDateString(), 'starts_at' => '10:00',
            'duration_minutes' => 120, 'opponent' => 'Synthetic visitors'];
        $this->db->table('clubora.pitch_requests')->insert($rows);
        foreach (['admin', 'trainer', 'technical_lead'] as $role) {
            $this->role($role);
            $counts = [];
            foreach ([1, 30, 100] as $size) {
                $this->db->enableQueryLog();
                $this->db->flushQueryLog();
                $data = $this->inbox(['per_page' => $size]);
                $queries = $this->db->getQueryLog();
                $this->db->disableQueryLog();
                $this->assertCount($size, $data['entries']);
                $this->assertSame($size < 100, $data['has_more']);
                foreach ($data['entries'] as $entry) $this->assertSame([], $entry['conflicts']);
                foreach ($queries as $query) {
                    $this->assertMatchesRegularExpression('/^select /i', $query['query']);
                    $this->assertDoesNotMatchRegularExpression('/pitch_blocks|pitch_training_exceptions|pitch_competition|training_slots/', $query['query']);
                }
                $counts[] = count($queries);
            }
            $this->assertSame([$counts[0], $counts[0], $counts[0]], $counts, $role);
            $this->assertLessThanOrEqual(25, $counts[0], $role);
        }
    }

    public function test_personal_mobile_profile_exclusion_deactivation_and_module_revocation_are_fresh(): void
    {
        $this->booking();
        $profile = $this->insert('access_profiles', ['name' => 'Mobile inbox', 'active' => true,
            'permissions' => json_encode([]), 'mobile_modules' => json_encode(['Platzverfugbarkeit'])]);
        foreach (['trainer', 'technical_lead'] as $role) {
            $this->role($role, ['mobile_profile_id' => $profile]);
            $this->assertCount(1, $this->inbox()['entries']);
            $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['mobile_modules' => json_encode([])]);
            $this->forbidden(fn () => $this->inbox());
            $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['mobile_modules' => json_encode(['Platzverfugbarkeit'])]);
            $this->assertCount(1, $this->inbox()['entries']);
            $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['active' => false]);
            $this->forbidden(fn () => $this->inbox());
            $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['active' => true]);
            $this->assertCount(1, $this->inbox()['entries']);
            $this->db->table('clubora.mobile_module_options')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
            $this->forbidden(fn () => $this->inbox());
            $this->db->table('clubora.mobile_module_options')->where('tenant_id', $this->tenant->id)->update(['active' => true]);
        }
        $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['active' => false]);
        foreach (['admin', 'office'] as $role) {
            $this->role($role);
            $data = $this->inbox();
            $this->assertTrue($data['can_filter_status']);
            $this->assertTrue($data['capabilities']['manage']);
            $this->assertTrue($data['capabilities']['approve']);
        }
    }
}
