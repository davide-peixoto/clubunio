<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, PitchPlanningProjection, PitchPlanningService, SignageService};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Isolated SQLite catalog; no external database, migrations, or provider calls. */
class PitchPlanningTeamOptionsTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private PitchPlanningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('clubunio');
        $this->db = DB::connection('clubunio');
        $this->db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach ([
            'tenants' => 'id integer primary key, slug text, name text',
            'accounts' => 'id integer primary key, active boolean default true',
            'tenant_accounts' => 'tenant_id integer, account_id integer, role text, access_profile_id integer, portal_enabled boolean default true',
            'tenant_settings' => 'id integer primary key, tenant_id integer, area text, key text, encrypted_value text, enabled boolean default true',
            'seasons' => 'id integer primary key, tenant_id integer, legacy_id integer, name text, starts_on text, ends_on text, active boolean default true',
            'teams' => 'id integer primary key, tenant_id integer, name text, active boolean default true',
            'team_seasons' => 'id integer primary key, tenant_id integer, team_id integer, season_id integer, label text, active boolean default true',
            'members' => 'id integer primary key, tenant_id integer, archived_at text, exit_date text, status_id integer',
            'member_statuses' => 'id integer primary key, tenant_id integer, ends_membership boolean',
            'account_member_links' => 'id integer primary key, tenant_id integer, account_id integer, member_id integer, kind text, state text',
            'staff_functions' => 'id integer primary key, tenant_id integer, code text, name text, active boolean default true',
            'team_staff' => 'id integer primary key, tenant_id integer, member_id integer, team_season_id integer, staff_function_id integer, active boolean default true',
            'technical_leads' => 'id integer primary key, tenant_id integer, member_id integer, team_season_id integer, active boolean default true',
            'account_roles' => 'code text, active boolean default true',
            'access_profiles' => 'id integer primary key, tenant_id integer, active boolean default true, permissions text',
            'competitions' => 'id integer primary key, tenant_id integer, active boolean default true, plays_on text, starts_at text, field_id integer, host_name text, guest_name text, revision integer default 1',
            'pitch_competition_settings' => 'id integer primary key, tenant_id integer, competition_id integer',
            'pitch_competition_teams' => 'tenant_id integer, competition_id integer, team_season_id integer',
            'pitch_requests' => 'id integer primary key, tenant_id integer, team_season_id integer, date text, starts_at text, duration_minutes integer, field_id integer, area_id integer, opponent text, status text, revision integer, requester_account_id integer',
            'pitch_blocks' => 'id integer primary key, tenant_id integer, date text',
        ] as $table => $columns) $this->db->statement("CREATE TABLE clubora.$table ($columns)");
        $this->db->beginTransaction();
        $this->row('tenants', ['id' => 1, 'slug' => 'options-one', 'name' => 'Options one']);
        $this->row('tenants', ['id' => 2, 'slug' => 'options-two', 'name' => 'Options two']);
        $this->row('accounts', ['id' => 1]);
        $this->row('tenant_accounts', ['account_id' => 1, 'role' => 'admin']);
        // The configured season deliberately does not cover today's date.
        $this->row('seasons', ['id' => 10, 'legacy_id' => 700, 'name' => '2030/31', 'starts_on' => '2030-07-01', 'ends_on' => '2031-06-30']);
        $this->row('seasons', ['id' => 20, 'name' => '2025/26', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $this->row('seasons', ['id' => 30, 'tenant_id' => 2, 'name' => 'Other club']);
        $this->row('teams', ['id' => 1, 'name' => 'U18']);
        $this->row('teams', ['id' => 2, 'name' => 'U21']);
        $this->row('teams', ['id' => 3, 'name' => 'Inactive', 'active' => false]);
        $this->row('teams', ['id' => 4, 'tenant_id' => 2, 'name' => 'Foreign']);
        foreach ([
            ['id' => 101, 'team_id' => 1, 'season_id' => 10],
            ['id' => 102, 'team_id' => 1, 'season_id' => 20],
            ['id' => 103, 'team_id' => 2, 'season_id' => 10],
            ['id' => 104, 'team_id' => 2, 'season_id' => 10, 'active' => false],
            ['id' => 105, 'team_id' => 3, 'season_id' => 10],
            ['id' => 106, 'tenant_id' => 2, 'team_id' => 4, 'season_id' => 30],
            // Malformed references must not bypass the tenant-scoped lookup.
            ['id' => 107, 'team_id' => 4, 'season_id' => 10],
            ['id' => 108, 'team_id' => 1, 'season_id' => 30],
            ['id' => 109, 'tenant_id' => 2, 'team_id' => 1, 'season_id' => 10],
        ] as $team) $this->row('team_seasons', $team);
        $this->setting('700');
        $this->tenant = Tenant::findOrFail(1);
        $this->actor = Account::findOrFail(1);
        $projection = new class extends PitchPlanningProjection {
            public function ready(): bool { return true; }
            public function fields(Tenant $tenant): array { return []; }
        };
        $this->service = new PitchPlanningService($projection);
    }

    protected function tearDown(): void
    {
        if ($this->db) while ($this->db->transactionLevel()) $this->db->rollBack();
        DB::purge('clubunio');
        parent::tearDown();
    }

    private function row(string $table, array $data): void
    {
        if (! in_array($table, ['tenants', 'accounts', 'account_roles'], true)) $data += ['tenant_id' => 1];
        $this->db->table('clubora.'.$table)->insert($data);
    }

    private function setting(string $value): void
    {
        $this->db->table('clubora.tenant_settings')->updateOrInsert(['tenant_id' => 1, 'area' => 'general', 'key' => 'SaisonID'],
            ['encrypted_value' => Crypt::encryptString($value)]);
        $this->clearSeason();
    }

    private function clearSeason(): void
    {
        request()->attributes->remove('mobile.current_season.1.'.now('Europe/Zurich')->toDateString());
    }

    private function role(string $role, ?int $profile = null): void
    {
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', 1)->update(['role' => $role, 'access_profile_id' => $profile]);
    }

    private function plannerOptions(): array
    {
        return $this->service->options($this->actor, $this->tenant);
    }

    private function portalAccess(bool $allowed = true): void
    {
        // The portal admission query uses PostgreSQL age arithmetic. Isolate only
        // that gate; membership, self links, trainer functions and team scopes are real SQL.
        $this->partialMock(MemberAccessService::class)->shouldReceive('canEnter')->andReturn($allowed);
    }

    private function assignments(): void
    {
        $this->row('members', ['id' => 1]);
        $this->row('account_member_links', ['account_id' => 1, 'member_id' => 1, 'kind' => 'self', 'state' => 'approved']);
        $this->row('staff_functions', ['id' => 1, 'code' => 'TRAINER', 'name' => 'Trainer']);
        foreach ([101, 102, 104, 105, 106, 107, 108, 109] as $id) {
            $this->row('team_staff', ['team_season_id' => $id, 'member_id' => 1, 'staff_function_id' => 1]);
            $this->row('technical_leads', ['team_season_id' => $id, 'member_id' => 1]);
        }
        // Neither a foreign assignment nor a helper function grants the other current team.
        $this->row('team_staff', ['tenant_id' => 2, 'team_season_id' => 103, 'member_id' => 1, 'staff_function_id' => 1]);
        $this->row('technical_leads', ['tenant_id' => 2, 'team_season_id' => 103, 'member_id' => 1]);
        $this->row('staff_functions', ['id' => 2, 'code' => 'HELPER', 'name' => 'Helper']);
        $this->row('team_staff', ['team_season_id' => 103, 'member_id' => 1, 'staff_function_id' => 2]);
    }

    public function test_office_options_use_configured_season_and_preserve_the_api_shape_in_both_languages(): void
    {
        foreach (['admin', 'office'] as $role) {
            $this->role($role);
            foreach (['de', 'en'] as $locale) {
                app()->setLocale($locale);
                foreach ([false, true] as $mobile) {
                    request()->attributes->set('mobile.season_scope', $mobile);
                    $options = $this->plannerOptions();
                    $this->assertSame(['fields', 'teams', 'capabilities'], array_keys($options));
                    $this->assertSame([['id' => 101, 'name' => 'U18 (2030/31)'], ['id' => 103, 'name' => 'U21 (2030/31)']], $options['teams']);
                    $this->assertSame(['view' => true, 'manage' => true, 'approve' => true, 'request' => true], $options['capabilities']);
                }
            }
        }
    }

    public function test_native_setting_and_imported_id_collision_follow_the_central_resolver(): void
    {
        $this->setting('20');
        $this->assertSame([['id' => 102, 'name' => 'U18 (2025/26)']], $this->plannerOptions()['teams']);
        $this->row('seasons', ['id' => 700, 'name' => 'Coincidental native ID']);
        $this->row('team_seasons', ['id' => 110, 'season_id' => 700, 'team_id' => 1]);
        $this->setting('700');
        $this->assertSame([101, 103], array_column($this->plannerOptions()['teams'], 'id'));
    }

    public function test_missing_invalid_foreign_or_inactive_configuration_never_falls_back_to_history(): void
    {
        foreach (['', '0', '-1', 'invalid', '999999', '30', '10'] as $value) {
            $this->setting($value);
            $this->assertSame([], $this->plannerOptions()['teams'], $value);
        }
        $this->setting('700');
        $this->db->table('clubora.seasons')->where('id', 10)->update(['active' => false]);
        $this->assertSame([], $this->plannerOptions()['teams']);
        $this->db->table('clubora.seasons')->where('id', 10)->update(['active' => true]);
        $this->db->table('clubora.tenant_settings')->delete();
        $this->clearSeason();
        $this->assertSame([], $this->plannerOptions()['teams']);
        $this->setting('700');
        $this->db->table('clubora.tenant_settings')->update(['encrypted_value' => 'not-ciphertext']);
        $this->clearSeason();
        $this->assertSame([], $this->plannerOptions()['teams']);
    }

    public function test_ambiguous_settings_and_duplicate_legacy_seasons_stay_closed(): void
    {
        $this->row('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'encrypted_value' => Crypt::encryptString('20')]);
        $this->assertSame([], $this->plannerOptions()['teams']);
        $this->db->table('clubora.tenant_settings')->delete();
        $this->setting('700');
        $this->row('seasons', ['id' => 40, 'legacy_id' => 700, 'name' => 'Duplicate imported ID']);
        $this->assertSame([], $this->plannerOptions()['teams']);
    }

    public function test_trainer_and_lead_only_receive_eligible_current_assigned_teams(): void
    {
        $this->portalAccess();
        $this->assignments();
        foreach (['trainer', 'technical_lead'] as $role) {
            $this->role($role);
            $options = $this->plannerOptions();
            $this->assertSame([['id' => 101, 'name' => 'U18 (2030/31)']], $options['teams']);
            $this->assertSame(['view' => true, 'manage' => false, 'approve' => false, 'request' => true], $options['capabilities']);
        }
    }

    public function test_revoked_assignments_and_non_self_links_do_not_grant_options(): void
    {
        $this->portalAccess();
        $this->assignments();
        foreach (['trainer' => 'team_staff', 'technical_lead' => 'technical_leads'] as $role => $table) {
            $this->role($role);
            $this->db->table('clubora.'.$table)->where('team_season_id', 101)->update(['active' => false]);
            $this->assertSame([], $this->plannerOptions()['teams']);
            $this->db->table('clubora.'.$table)->where('team_season_id', 101)->update(['active' => true]);
            foreach ([['state' => 'pending'], ['kind' => 'parent']] as $change) {
                $this->db->table('clubora.account_member_links')->update($change);
                $this->assertSame([], $this->plannerOptions()['teams']);
                $this->db->table('clubora.account_member_links')->update(['state' => 'approved', 'kind' => 'self']);
            }
        }
        $this->role('trainer');
        $this->db->table('clubora.staff_functions')->where('id', 1)->update(['active' => false]);
        $this->assertSame([], $this->plannerOptions()['teams']);
    }

    public function test_portal_roles_and_read_only_profiles_are_not_upgraded(): void
    {
        $this->portalAccess();
        $this->assignments();
        foreach (['member', 'parent'] as $role) {
            $this->role($role);
            $this->assertSame([], $this->plannerOptions()['teams']);
            $this->assertFalse($this->plannerOptions()['capabilities']['request']);
        }
        $this->row('account_roles', ['code' => 'profile']);
        $this->row('access_profiles', ['id' => 1, 'permissions' => json_encode(['competitions.view'])]);
        $this->role('profile', 1);
        $this->assertSame([], $this->plannerOptions()['teams']);
        $this->assertSame(['view' => true, 'manage' => false, 'approve' => false, 'request' => false], $this->plannerOptions()['capabilities']);
    }

    public function test_missing_membership_and_inactive_account_are_denied(): void
    {
        foreach ([Tenant::findOrFail(2), $this->tenant] as $tenant) {
            if ($tenant->id === $this->tenant->id) $this->db->table('clubora.accounts')->update(['active' => false]);
            try {
                $this->service->options($this->actor, $tenant);
                $this->fail('Expected forbidden options.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function test_impersonation_never_adds_request_or_management_capabilities(): void
    {
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => 1, 'target_id' => 1]);
        $this->assertSame([], $this->plannerOptions()['teams']);
        $this->assertSame(['view' => true, 'manage' => false, 'approve' => false, 'request' => false], $this->plannerOptions()['capabilities']);
    }

    public function test_historical_calendar_entries_and_explicit_team_filter_survive_empty_options(): void
    {
        $this->mock(SignageService::class)->shouldReceive('trainingVariant')->andReturn('all_fields');
        $this->row('competitions', ['id' => 1, 'plays_on' => '2026-06-01', 'starts_at' => '12:00', 'field_id' => 1, 'host_name' => 'U18', 'guest_name' => 'Visitors']);
        $this->row('pitch_competition_teams', ['competition_id' => 1, 'team_season_id' => 102]);
        $this->row('pitch_requests', ['id' => 1, 'team_season_id' => 102, 'date' => '2026-06-01', 'starts_at' => '15:00',
            'duration_minutes' => 90, 'field_id' => 1, 'opponent' => 'Historical visitors', 'status' => 'approved', 'revision' => 1, 'requester_account_id' => 1]);
        $filters = ['from' => '2026-06-01', 'to' => '2026-06-01', 'team_season_id' => 102];
        $calendar = $this->service->calendar($this->actor, $this->tenant, $filters);
        $this->assertSame(['competition:1', 'request:1'], array_column($calendar['entries'], 'key'));
        $this->assertSame([[102], [102]], array_column($calendar['entries'], 'team_season_ids'));
        $this->assertSame([101, 103], array_column($this->plannerOptions()['teams'], 'id'));
        $this->setting('invalid');
        $this->assertSame([], $this->plannerOptions()['teams']);
        $this->assertSame($calendar, $this->service->calendar($this->actor, $this->tenant, $filters));
    }

    public function test_options_are_read_only_bounded_and_do_not_rewrite_names_or_merge_distinct_ids(): void
    {
        $this->row('team_seasons', ['id' => 110, 'team_id' => 1, 'season_id' => 10]);
        $this->db->table('clubora.teams')->where('id', 1)->update(['name' => 'Original / U18']);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $options = $this->plannerOptions();
        $queries = $this->db->getQueryLog();
        foreach ($queries as $query) $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        $this->assertSame([101, 103, 110], array_column($options['teams'], 'id'));
        $this->assertSame('Original / U18 (2030/31)', $options['teams'][0]['name']);
        $this->assertSame($options['teams'][0]['name'], $options['teams'][2]['name']);
        $this->assertSame('Original / U18', $this->db->table('clubora.teams')->where('id', 1)->value('name'));
        foreach (range(200, 219) as $id) $this->row('team_seasons', ['id' => $id, 'team_id' => 1, 'season_id' => 10]);
        $this->clearSeason();
        $this->db->flushQueryLog();
        $this->assertCount(23, $this->plannerOptions()['teams']);
        $this->assertCount(count($queries), $this->db->getQueryLog());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
