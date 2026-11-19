<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Competition, Tenant};
use App\ClubUnio\Services\{PitchCompetitionTeamMapping, PitchPlanningNotificationService, PitchPlanningProjection};
use Illuminate\Support\Facades\{DB, Http, Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** In-memory by default; the PostgreSQL subclass runs the same contract on temporary tables. */
class PitchCompetitionTeamMappingTest extends TestCase
{
    protected $db;
    protected $grammar;
    private Tenant $tenant;
    private Account $actor;
    private PitchCompetitionTeamMapping $mapping;

    protected function createDatabase(): void
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('clubunio');
        $this->db = DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        foreach ([
            'tenants' => 'id integer primary key, slug text, name text',
            'accounts' => 'id integer primary key, username text, display_name text, password text, active boolean default true, api_only boolean default false',
            'tenant_accounts' => 'tenant_id integer, account_id integer, role text, access_profile_id integer, portal_enabled boolean default false',
            'competitions' => "id integer primary key, tenant_id integer, active boolean default true, plays_on text, starts_at text, field_id integer, host_room_id integer, guest_room_id integer, host_name text, guest_name text, association_match_id integer, source text default 'manual', revision integer default 1",
            'sfv_schedule' => 'id integer primary key, tenant_id integer, match_id integer, season_id integer, team_a_id integer, team_b_id integer, starts_at text, title text, home boolean, revision integer default 1',
            'teams' => 'id integer primary key, tenant_id integer, name text, sfv_team_id integer, active boolean default true',
            'seasons' => 'id integer primary key, tenant_id integer, name text, starts_on text, ends_on text, active boolean default true',
            'team_seasons' => 'id integer primary key, tenant_id integer, team_id integer, season_id integer, label text, active boolean default true',
            'pitch_competition_teams' => 'tenant_id integer, competition_id integer, team_season_id integer, primary key(tenant_id,competition_id,team_season_id)',
            'pitch_competition_settings' => 'id integer primary key, tenant_id integer, competition_id integer, area_id integer, duration_minutes integer default 120, withdrawn boolean default false, revision integer default 1, unique(tenant_id,competition_id)',
            'object_changes' => 'id integer primary key, tenant_id integer, actor_id integer, object_type text, object_id integer, before_value text, after_value text',
            'training_options' => 'id integer primary key, tenant_id integer, category text, code text, name text',
            'members' => 'id integer primary key, tenant_id integer, first_name text, last_name text, archived_at text, exit_date text, status_id integer',
            'member_statuses' => 'id integer primary key, tenant_id integer, ends_membership boolean',
            'team_staff' => 'id integer primary key, tenant_id integer, team_season_id integer, member_id integer, active boolean default true',
            'account_member_links' => 'id integer primary key, tenant_id integer, account_id integer, member_id integer, kind text, state text, source text',
        ] as $table => $columns) $this->db->statement("CREATE TABLE clubunio.$table ($columns)");
        $this->db->beginTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->createDatabase();
        $this->row('tenants', ['id' => 1, 'slug' => 'mapping-one', 'name' => 'Synthetic one']);
        $this->row('tenants', ['id' => 2, 'slug' => 'mapping-two', 'name' => 'Synthetic two']);
        $this->row('accounts', ['id' => 1, 'username' => 'mapping-admin', 'display_name' => 'Synthetic admin', 'password' => 'unused', 'active' => true, 'api_only' => false]);
        $this->row('tenant_accounts', ['account_id' => 1, 'role' => 'admin']);
        $this->row('seasons', ['id' => 1, 'name' => 'Historical season', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $this->row('teams', ['id' => 1, 'name' => 'Unrelated local label', 'sfv_team_id' => 919]);
        $this->row('team_seasons', ['id' => 1, 'team_id' => 1, 'season_id' => 1, 'label' => 'Historical assignment']);
        $this->row('training_options', ['id' => 1, 'category' => 'field', 'code' => 'H', 'name' => 'Main field']);
        $this->row('training_options', ['id' => 2, 'category' => 'room', 'code' => 'R', 'name' => 'Room']);
        $this->competition();
        $this->source();
        $this->tenant = Tenant::findOrFail(1);
        $this->actor = Account::findOrFail(1);
        $projection = new class extends PitchPlanningProjection { public function ready(): bool { return true; } };
        $this->app->instance(PitchPlanningProjection::class, $projection);
        $this->mapping = new PitchCompetitionTeamMapping($projection);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            while ($this->db->transactionLevel()) $this->db->rollBack();
            if ($this->grammar) $this->db->setQueryGrammar($this->grammar);
        }
        DB::purge('clubunio');
        parent::tearDown();
    }

    private function row(string $table, array $data): void
    {
        if (! in_array($table, ['tenants', 'accounts'], true)) $data += ['tenant_id' => 1];
        $this->db->table('clubunio.'.$table)->insert($data);
    }

    private function competition(array $data = []): void
    {
        $this->row('competitions', $data + ['id' => 1, 'plays_on' => '2026-06-30', 'starts_at' => '18:00', 'field_id' => 1,
            'host_room_id' => 2, 'guest_room_id' => 2, 'host_name' => 'Source label', 'guest_name' => 'Opponent', 'association_match_id' => 123]);
    }

    private function source(array $data = []): void
    {
        $this->row('sfv_schedule', $data + ['id' => 1, 'match_id' => 123, 'season_id' => 9000, 'team_a_id' => 919, 'team_b_id' => 920,
            'starts_at' => '2026-06-29 22:30:00+00', 'title' => 'Different title', 'home' => true]);
    }

    private function preview(): array
    {
        return $this->mapping->preview($this->actor, $this->tenant, '2026-06-01', '2026-06-30');
    }

    private function apply(?array $report = null, bool $confirmed = true): array
    {
        $report ??= $this->preview();
        return $this->mapping->apply($this->actor, $this->tenant, $report['from'], $report['to'], $report['fingerprint'], $confirmed);
    }

    private function fails(int $status, callable $action): void
    {
        try { $action(); $this->fail('Expected HTTP '.$status); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function test_preview_is_read_only_and_uses_numeric_ids_zurich_date_and_inclusive_season_boundary(): void
    {
        $this->db->flushQueryLog();
        $this->db->enableQueryLog();
        $report = $this->preview();
        foreach ($this->db->getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        $this->assertSame(['ready' => 1, 'skipped' => 0, 'error' => 0], $report['summary']);
        $this->assertSame([1], $report['entries'][0]['team_season_ids']);
        $this->assertSame(9000, $report['entries'][0]['source_season_id']);
        $this->assertSame($report, $this->preview());
        $this->assertSame(0, $this->db->table('clubunio.pitch_competition_teams')->count());
    }

    public function test_confirmed_apply_is_additive_audited_and_repeat_preview_is_idempotent(): void
    {
        $before = $this->db->table('clubunio.competitions')->get()->toJson();
        $source = $this->db->table('clubunio.sfv_schedule')->get()->toJson();
        $report = $this->preview();
        $this->assertSame(1, $this->apply($report)['applied']);
        $this->assertSame($before, $this->db->table('clubunio.competitions')->get()->toJson());
        $this->assertSame($source, $this->db->table('clubunio.sfv_schedule')->get()->toJson());
        $this->assertSame(1, $this->db->table('clubunio.object_changes')->count());
        $audit = json_decode($this->db->table('clubunio.object_changes')->value('after_value'), true);
        $this->assertSame($report['fingerprint'], $audit['fingerprint']);
        $this->assertSame('historical_sfv_mapping', $audit['action']);
        $this->assertSame(0, $this->apply()['applied']);
        $this->assertSame(1, $this->db->table('clubunio.object_changes')->count());
        $this->fails(409, fn () => $this->apply($report));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_existing_manual_and_explicitly_empty_mappings_are_never_overwritten(): void
    {
        $this->row('pitch_competition_teams', ['competition_id' => 1, 'team_season_id' => 1]);
        $this->competition(['id' => 2, 'association_match_id' => 456]);
        $this->row('pitch_competition_settings', ['competition_id' => 2, 'duration_minutes' => 95, 'revision' => 7]);
        $this->assertSame(['existing_mapping', 'local_configuration'], array_column($this->preview()['entries'], 'reason'));
        $this->assertSame(0, $this->apply()['applied']);
        $this->assertSame(95, (int) $this->db->table('clubunio.pitch_competition_settings')->value('duration_minutes'));
        $this->assertSame(7, (int) $this->db->table('clubunio.pitch_competition_settings')->value('revision'));
    }

    public function test_ambiguous_assignment_blocks_the_entire_batch_without_partial_writes(): void
    {
        $this->competition(['id' => 2, 'association_match_id' => 456]);
        $this->source(['id' => 2, 'match_id' => 456, 'team_a_id' => 921]);
        $this->row('teams', ['id' => 2, 'name' => 'Other local team', 'sfv_team_id' => 921]);
        foreach ([2, 3] as $id) $this->row('team_seasons', ['id' => $id, 'team_id' => 2, 'season_id' => 1, 'label' => 'Duplicate']);
        $report = $this->preview();
        $this->assertSame(['ready' => 1, 'skipped' => 0, 'error' => 1], $report['summary']);
        $this->assertSame('ambiguous_team', $report['entries'][1]['reason']);
        $this->fails(422, fn () => $this->apply($report));
        $this->assertSame(0, $this->db->table('clubunio.pitch_competition_teams')->count());
        $this->assertSame(0, $this->db->table('clubunio.object_changes')->count());
    }

    public function test_overlapping_seasons_and_duplicate_sfv_ids_are_ambiguous(): void
    {
        $this->row('seasons', ['id' => 2, 'name' => 'Overlap', 'starts_on' => '2026-06-30', 'ends_on' => '2027-06-30']);
        $this->row('teams', ['id' => 2, 'name' => 'Same numeric identity', 'sfv_team_id' => 919]);
        $this->row('team_seasons', ['id' => 2, 'team_id' => 2, 'season_id' => 2, 'label' => 'Overlap']);
        $this->assertSame('ambiguous_team', $this->preview()['entries'][0]['reason']);
        $this->fails(422, fn () => $this->apply());
    }

    public function test_both_local_sides_map_but_missing_one_assignment_blocks_partial_mapping(): void
    {
        $this->row('teams', ['id' => 2, 'name' => 'Local opponent', 'sfv_team_id' => 920]);
        $this->assertSame('missing_team_season', $this->preview()['entries'][0]['reason']);
        $this->fails(422, fn () => $this->apply());
        $this->row('team_seasons', ['id' => 2, 'team_id' => 2, 'season_id' => 1, 'label' => 'Opponent assignment']);
        $this->assertSame([1, 2], $this->preview()['entries'][0]['team_season_ids']);
        $this->assertSame(1, $this->apply()['applied']);
        $this->assertSame(2, $this->db->table('clubunio.pitch_competition_teams')->count());
    }

    public function test_no_names_fallback_no_cross_tenant_matches_and_no_source_fetch(): void
    {
        $this->db->table('clubunio.teams')->update(['sfv_team_id' => null, 'name' => 'Source label']);
        $this->row('teams', ['id' => 2, 'tenant_id' => 2, 'name' => 'Source label', 'sfv_team_id' => 919]);
        $this->row('seasons', ['id' => 2, 'tenant_id' => 2, 'name' => 'Foreign', 'starts_on' => '2025-01-01', 'ends_on' => '2027-01-01']);
        $this->row('team_seasons', ['id' => 2, 'tenant_id' => 2, 'team_id' => 2, 'season_id' => 2, 'label' => 'Foreign']);
        $this->assertSame('no_local_team', $this->preview()['entries'][0]['reason']);
        $this->db->table('clubunio.sfv_schedule')->update(['tenant_id' => 2]);
        $this->assertSame('missing_source', $this->preview()['entries'][0]['reason']);
        $this->assertSame(0, $this->apply()['applied']);
    }

    public function test_invalid_source_date_or_team_ids_never_guess(): void
    {
        foreach ([['starts_at' => null], ['starts_at' => '2026-06-30 22:30:00+00']] as $change) {
            $this->db->table('clubunio.sfv_schedule')->update($change);
            $this->assertSame('source_date_mismatch', $this->preview()['entries'][0]['reason']);
        }
        $this->db->table('clubunio.sfv_schedule')->update(['starts_at' => '2026-06-30 12:00:00+00']);
        foreach ([null, 0, 919] as $id) {
            $this->db->table('clubunio.sfv_schedule')->update(['team_b_id' => $id]);
            $this->assertSame('invalid_source_teams', $this->preview()['entries'][0]['reason']);
        }
    }

    public function test_inactive_or_undated_seasons_and_inactive_teams_are_not_candidates(): void
    {
        foreach (['teams', 'team_seasons', 'seasons'] as $table) {
            $this->db->table('clubunio.'.$table)->update(['active' => false]);
            $this->assertSame('missing_team_season', $this->preview()['entries'][0]['reason']);
            $this->db->table('clubunio.'.$table)->update(['active' => true]);
        }
        $this->db->table('clubunio.seasons')->update(['ends_on' => null]);
        $this->assertSame('missing_team_season', $this->preview()['entries'][0]['reason']);
    }

    public function test_duplicate_competition_link_even_archived_outside_range_is_an_error(): void
    {
        $this->competition(['id' => 2, 'active' => false, 'plays_on' => '2020-01-01']);
        $this->assertSame('ambiguous_match', $this->preview()['entries'][0]['reason']);
        $this->fails(422, fn () => $this->apply());
    }

    public function test_confirmation_and_fresh_fingerprint_are_required(): void
    {
        $report = $this->preview();
        $this->fails(422, fn () => $this->apply($report, false));
        $this->db->table('clubunio.sfv_schedule')->update(['revision' => 2]);
        $this->fails(409, fn () => $this->apply($report));
        $report = $this->preview();
        $this->db->table('clubunio.seasons')->update(['ends_on' => '2026-07-01']);
        $this->fails(409, fn () => $this->apply($report));
        $report = $this->preview();
        $this->row('pitch_competition_teams', ['competition_id' => 1, 'team_season_id' => 1]);
        $this->fails(409, fn () => $this->apply($report));
        $this->assertSame(0, $this->db->table('clubunio.object_changes')->count());
    }

    public function test_authorization_rechecks_admin_active_account_tenant_and_simulation(): void
    {
        $report = $this->preview();
        foreach (['office', 'trainer', 'profile'] as $role) {
            $this->db->table('clubunio.tenant_accounts')->update(['role' => $role, 'access_profile_id' => $role === 'profile' ? 1 : null]);
            $this->fails(403, fn () => $this->apply($report));
            $this->fails(403, fn () => $this->preview());
        }
        $this->db->table('clubunio.tenant_accounts')->update(['role' => 'admin', 'access_profile_id' => null]);
        $this->db->table('clubunio.accounts')->update(['active' => false]);
        $this->fails(403, fn () => $this->apply($report));
        $this->db->table('clubunio.accounts')->update(['active' => true, 'api_only' => true]);
        $this->fails(403, fn () => $this->apply($report));
        $this->db->table('clubunio.accounts')->update(['api_only' => false]);
        $this->fails(403, fn () => $this->mapping->preview($this->actor, Tenant::findOrFail(2), $report['from'], $report['to']));
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => 1, 'target_id' => 1]);
        $this->fails(403, fn () => $this->apply($report));
    }

    public function test_rollback_includes_links_revision_and_audit_when_a_late_write_fails(): void
    {
        $this->db->beginTransaction();
        $this->apply();
        $this->assertSame(1, $this->db->table('clubunio.pitch_competition_teams')->count());
        $this->db->rollBack();
        foreach (['pitch_competition_teams', 'pitch_competition_settings', 'object_changes'] as $table) {
            $this->assertSame(0, $this->db->table('clubunio.'.$table)->count());
        }
        $this->db->beforeExecuting(function ($query) {
            if (str_starts_with($query, 'insert into') && str_contains($query, 'object_changes')) throw new \RuntimeException('Synthetic audit failure');
        });
        try { $this->apply(); $this->fail('Expected audit failure'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic audit failure', $error->getMessage()); }
        foreach (['pitch_competition_teams', 'pitch_competition_settings', 'object_changes'] as $table) {
            $this->assertSame(0, $this->db->table('clubunio.'.$table)->count());
        }
    }

    public function test_mapped_historical_competition_projects_team_ids_for_staff_notification_recipients(): void
    {
        $this->row('members', ['id' => 1, 'first_name' => 'Synthetic', 'last_name' => 'Staff']);
        $this->row('team_staff', ['team_season_id' => 1, 'member_id' => 1]);
        $this->row('account_member_links', ['account_id' => 1, 'member_id' => 1, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        $notification = app(PitchPlanningNotificationService::class);
        $recipients = new \ReflectionMethod($notification, 'recipients');
        $this->assertSame(0, $recipients->invoke($notification, $this->tenant, ['action' => 'moved', 'team_season_ids' => []])->count());
        $this->apply();
        $entry = app(PitchPlanningProjection::class)->competition(Competition::findOrFail(1),
            $this->db->table('clubunio.pitch_competition_settings')->first(),
            $this->db->table('clubunio.pitch_competition_teams')->pluck('team_season_id')->all());
        $this->assertSame([1], $entry['team_season_ids']);
        $this->assertSame('1:1', $entry['revision']);
        $this->assertSame([1], $recipients->invoke($notification, $this->tenant, ['action' => 'moved', 'team_season_ids' => $entry['team_season_ids']])
            ->pluck('a.id')->map(fn ($id) => (int) $id)->all());
        $this->db->table('clubunio.team_staff')->update(['active' => false]);
        $this->assertSame(0, $recipients->invoke($notification, $this->tenant, ['action' => 'moved', 'team_season_ids' => $entry['team_season_ids']])->count());
    }

    public function test_console_defaults_to_preview_and_requires_exact_fingerprint_for_apply(): void
    {
        $options = ['--tenant' => '1', '--actor' => '1', '--from' => '2026-06-01', '--to' => '2026-06-30', '--locale' => 'en'];
        $this->artisan('clubunio:pitch-team-mapping', $options + ['--json' => true])
            ->expectsOutputToContain('"ready": 1')->assertSuccessful();
        $this->assertSame(0, $this->db->table('clubunio.pitch_competition_teams')->count());
        $this->artisan('clubunio:pitch-team-mapping', $options + ['--apply' => true])->assertExitCode(2);
        $this->artisan('clubunio:pitch-team-mapping', $options + ['--confirm' => $this->preview()['fingerprint']])->assertExitCode(2);
        $this->artisan('clubunio:pitch-team-mapping', $options + ['--apply' => true, '--confirm' => str_repeat('0', 64)])->assertFailed();
        $this->artisan('clubunio:pitch-team-mapping', $options + ['--apply' => true, '--confirm' => $this->preview()['fingerprint']])
            ->expectsOutputToContain('Mapped competitions: 1. No notifications sent.')->assertSuccessful();
    }

    public function test_console_error_report_is_nonzero_and_catalogues_cover_all_enabled_languages(): void
    {
        $this->db->table('clubunio.sfv_schedule')->update(['team_b_id' => null]);
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            $this->artisan('clubunio:pitch-team-mapping', ['--tenant' => '1', '--actor' => '1', '--from' => '2026-06-01', '--to' => '2026-06-30', '--json' => true, '--locale' => $locale])
                ->expectsOutputToContain('invalid_source_teams')->assertFailed();
            foreach (array_keys(require lang_path('en/pitch_mapping.php')) as $key) {
                $this->assertNotSame('pitch_mapping.'.$key, trans('pitch_mapping.'.$key, [], $locale));
            }
        }
    }

    public function test_unlinked_and_inactive_competitions_are_skipped_without_creating_sources(): void
    {
        $this->db->table('clubunio.competitions')->update(['association_match_id' => null]);
        $this->competition(['id' => 2, 'association_match_id' => 456, 'active' => false]);
        $this->assertSame(['no_match_id', 'inactive_competition'], array_column($this->preview()['entries'], 'reason'));
        $this->assertSame(0, $this->apply()['applied']);
        $this->assertSame(1, $this->db->table('clubunio.sfv_schedule')->count());
    }

    public function test_next_season_does_not_replace_the_historical_season_and_scope_is_fingerprinted(): void
    {
        $this->row('seasons', ['id' => 2, 'name' => 'Next season', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->row('team_seasons', ['id' => 2, 'team_id' => 1, 'season_id' => 2, 'label' => 'Next assignment']);
        $report = $this->preview();
        $this->assertSame([1], $report['entries'][0]['team_season_ids']);
        $this->fails(409, fn () => $this->mapping->apply($this->actor, $this->tenant, '2026-06-02', $report['to'], $report['fingerprint'], true));
        $this->assertSame(1, $this->apply($report)['applied']);
    }

    public function test_missing_pitch_schema_and_invalid_date_scope_fail_closed(): void
    {
        $mapping = new PitchCompetitionTeamMapping(new class extends PitchPlanningProjection { public function ready(): bool { return false; } });
        $this->fails(503, fn () => $mapping->preview($this->actor, $this->tenant, '2026-06-01', '2026-06-30'));
        $this->artisan('clubunio:pitch-team-mapping', ['--tenant' => '1', '--actor' => '1', '--from' => '2026-06-30', '--to' => '2026-06-01'])
            ->assertExitCode(2);
        $this->assertSame(0, $this->db->table('clubunio.pitch_competition_teams')->count());
    }
}
