<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingSlot;
use App\ClubUnio\NavigationStructure;
use App\ClubUnio\Pages\SeasonPlanning;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\SeasonPlanningService;
use App\ClubUnio\Services\TeamAssignmentService;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SeasonPlanningTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $gender;

    private int $member;

    private int $function;

    private array $options;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'plan-'.Str::random(16), 'name' => 'Planning fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'office']);
        $this->gender = $this->insert('genders', ['name' => 'Fixture', 'code' => 'fixture']);
        $this->function = $this->insert('staff_functions', ['name' => 'Trainer', 'code' => 'trainer']);
        $this->member = $this->insert('members', ['first_name' => 'Player', 'last_name' => 'Fixture', 'birth_date' => '2015-01-01', 'gender_id' => $this->gender]);
        foreach (['period', 'weekday', 'field', 'room', 'side'] as $category) {
            $this->options[$category] = $this->insert('training_options', ['name' => $category, 'category' => $category, 'code' => 'fixture']);
        }
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function service(): SeasonPlanningService
    {
        return app(SeasonPlanningService::class);
    }

    private function rows(string $table): int
    {
        return $this->db->table('clubora.'.$table)->where('tenant_id', $this->tenant->id)->count();
    }

    private function document(): array
    {
        $doc = $this->service()->seed($this->actor, $this->tenant, null);
        $doc['season'] = ['name' => '2030/2031', 'starts_on' => '2030-07-01', 'ends_on' => '2031-06-30'];
        $team = (string) Str::uuid();
        $price = (string) Str::uuid();
        $rule = (string) Str::uuid();
        $doc['teams'][] = ['key' => $team, 'team_id' => null, 'name' => 'Fixture juniors', 'laundry_allowance' => '35', 'rule_key' => $rule, 'capacity' => 18];
        $doc['prices'][] = ['key' => $price, 'name' => 'Juniors 2030', 'amount' => '150'];
        $doc['rules'][] = ['key' => $rule, 'price_key' => $price, 'gender_id' => $this->gender, 'year_from' => 2014, 'year_to' => 2017];
        foreach (['player', 'staff', 'lead'] as $kind) {
            $doc['assignments'][] = ['key' => (string) Str::uuid(), 'kind' => $kind, 'team_key' => $team, 'member_id' => $this->member, 'function_id' => $kind === 'staff' ? $this->function : null];
        }
        $doc['slots'][] = ['key' => (string) Str::uuid(), 'team_key' => $team, 'starts_at' => '18:00', 'ends_at' => '19:30',
            'all_fields' => false, 'without_main' => false, 'synthetic_only' => false, 'website_visible' => true];
        foreach ($this->options as $kind => $id) {
            $doc['slots'][0][$kind.'_id'] = $id;
        }

        return $doc;
    }

    private function draft(?array $doc = null): object
    {
        return $this->service()->save($this->actor, $this->tenant, null, null, $doc ?? $this->document());
    }

    public function test_draft_is_encrypted_and_creates_no_domain_records_then_completes_once(): void
    {
        $row = $this->draft();
        foreach (['seasons', 'teams', 'team_seasons', 'contribution_rules', 'contribution_prices', 'team_players', 'team_staff', 'technical_leads', 'training_slots'] as $table) {
            $this->assertSame(0, $this->rows($table), $table);
        }
        $cipher = $this->service()->table($this->tenant)->where('id', $row->id)->value('document_encrypted');
        $this->assertStringNotContainsString('Fixture juniors', $cipher);
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->assertSame([], $review['errors']);
        $this->assertSame([], $review['warnings']);
        $id = $this->service()->complete($this->actor, $this->tenant, $row->id, $row->revision, $review['token'], false);
        $this->assertSame($id, $this->service()->complete($this->actor, $this->tenant, $row->id, $row->revision, $review['token'], false));
        foreach (['seasons', 'teams', 'team_seasons', 'contribution_rules', 'contribution_prices', 'team_players', 'team_staff', 'technical_leads', 'training_slots'] as $table) {
            $this->assertSame(1, $this->rows($table), $table);
        }
        $this->assertSame('completed', $this->service()->get($this->actor, $this->tenant, $row->id)->state);
        $this->assertSame(9, $this->rows('season_plan_results'));
        $this->assertSame('18:00:00', TrainingSlot::where('tenant_id', $this->tenant->id)->sole()->starts_at);
        $this->assertSame(0, $this->rows('invoices'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_partial_draft_can_resume_but_old_revision_cannot_overwrite_it(): void
    {
        $doc = $this->service()->seed($this->actor, $this->tenant, null);
        $doc['season'] = ['name' => null];
        $row = $this->draft($doc);
        $doc['season']['name'] = 'Resumed';
        $next = $this->service()->save($this->actor, $this->tenant, $row->id, $row->revision, $doc);
        $this->assertSame(2, $next->revision);
        try {
            $this->service()->save($this->actor, $this->tenant, $row->id, $row->revision, $row->document);
            $this->fail('Expected version conflict');
        } catch (ValidationException) {
            $this->assertSame('Resumed', $this->service()->get($this->actor, $this->tenant, $row->id)->name);
        }
    }

    public function test_updated_references_invalidate_review_and_do_not_create_partial_data(): void
    {
        $row = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['birth_date' => '2016-01-01']);
        try {
            $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
            $this->fail('Expected stale review');
        } catch (ValidationException) {
            $this->assertSame(0, $this->rows('seasons'));
        }
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
        $this->assertSame(1, $this->rows('seasons'));
    }

    public function test_late_failure_rolls_back_every_domain_write_and_keeps_draft(): void
    {
        $row = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->app->instance(ContributionTrainingService::class, new class extends ContributionTrainingService
        {
            public function save(Account $actor, Tenant $tenant, string $model, ?int $id, array $input)
            {
                if ($model === TrainingSlot::class) {
                    throw new \RuntimeException('Injected late failure');
                }

                return parent::save($actor, $tenant, $model, $id, $input);
            }
        });
        try {
            $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected late failure', $e->getMessage());
        }
        foreach (['seasons', 'teams', 'team_seasons', 'contribution_rules', 'contribution_prices', 'team_players', 'team_staff', 'technical_leads', 'season_plan_results'] as $table) {
            $this->assertSame(0, $this->rows($table), $table);
        }
        $this->assertSame('draft', $this->service()->get($this->actor, $this->tenant, $row->id)->state);
    }

    public function test_revoked_role_blocks_saved_draft_and_completion(): void
    {
        $row = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        try {
            $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
        } finally {
            $this->assertSame(0, $this->rows('seasons'));
        }
    }

    public function test_foreign_tenant_draft_is_not_accessible(): void
    {
        $row = $this->draft();
        $other = Tenant::create(['slug' => 'other-'.Str::random(15), 'name' => 'Other']);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $other->id, 'account_id' => $this->actor->id, 'role' => 'office']);
        try {
            $this->service()->get($this->actor, $other, $row->id);
            $this->fail('Expected not found');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_outdated_or_foreign_members_and_duplicate_assignments_block_review(): void
    {
        $doc = $this->document();
        $doc['assignments'][] = array_replace($doc['assignments'][0], ['key' => (string) Str::uuid()]);
        $row = $this->draft($doc);
        $this->assertContains('Eine Zuordnung ist doppelt enthalten.', $this->service()->review($this->actor, $this->tenant, $row->id)['errors']);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['exit_date' => now()->subDay()->toDateString()]);
        $this->assertNotEmpty($this->service()->review($this->actor, $this->tenant, $row->id)['errors']);
    }

    public function test_capacity_and_training_collisions_require_explicit_warning_acceptance(): void
    {
        $doc = $this->document();
        $doc['assignments'] = [];
        $doc['slots'][] = array_replace($doc['slots'][0], ['key' => (string) Str::uuid()]);
        $row = $this->draft($doc);
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->assertSame([], $review['errors']);
        $this->assertCount(3, $review['warnings']);
        try {
            $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], false);
            $this->fail('Expected acknowledgement');
        } catch (ValidationException) {
            $this->assertSame(0, $this->rows('seasons'));
        }
        $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
        $this->assertSame(2, $this->rows('training_slots'));
    }

    public function test_source_is_preserved_and_new_seed_advances_ranges(): void
    {
        $row = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $season = $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], false);
        $before = TeamPlayer::where('tenant_id', $this->tenant->id)->sole()->getAttributes();
        $doc = $this->service()->seed($this->actor, $this->tenant, $season);
        $this->assertSame('2031/2032', $doc['season']['name']);
        $this->assertSame(2015, $doc['rules'][0]['year_from']);
        $this->assertSame(3, count($doc['assignments']));
        $this->assertNotEmpty($doc['teams'][0]['rule_key']);
        $draft = $this->service()->save($this->actor, $this->tenant, null, null, $doc, $season);
        $review = $this->service()->review($this->actor, $this->tenant, $draft->id);
        $this->assertSame([], $review['errors']);
        $this->service()->complete($this->actor, $this->tenant, $draft->id, 1, $review['token'], true);
        $this->assertSame($before, TeamPlayer::where('id', $before['id'])->first()->getAttributes());
        $this->assertSame(1, $this->rows('teams'));
        $this->assertSame(2, $this->rows('team_seasons'));
    }

    public function test_foreign_fields_are_rejected_and_discard_keeps_history_without_domain_writes(): void
    {
        $doc = $this->document();
        $doc['teams'][0]['tenant_id'] = $this->tenant->id;
        try {
            $this->draft($doc);
            $this->fail('Expected whitelist');
        } catch (ValidationException) {
        }
        $row = $this->draft();
        $this->service()->discard($this->actor, $this->tenant, $row->id, 1);
        $this->assertSame('discarded', $this->service()->get($this->actor, $this->tenant, $row->id)->state);
        $this->assertSame(0, $this->rows('seasons'));
        $this->assertSame(2, $this->rows('object_changes'));
    }

    public function test_web_wizard_autosaves_resumes_and_renders_all_steps(): void
    {
        $row = $this->draft();
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)->assertSee('2030/2031');
        $page->set('document.season.name', 'Web season')->assertHasNoErrors()->assertSet('revision', 2);
        $this->assertSame('Web season', $this->service()->get($this->actor, $this->tenant, $row->id)->name);
        $page->call('go', 4)->call('openPlan', $row->id)->assertSet('step', 4)->assertHasNoErrors();
        foreach (range(2, 7) as $step) {
            $page->call('go', $step)->assertSuccessful()->assertHasNoErrors();
        }
        $page->call('finish')->assertHasNoErrors()->assertSet('state', 'completed');
        $this->assertSame(1, $this->rows('seasons'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_web_pool_assignment_suggestions_merge_and_remove_are_draft_only(): void
    {
        $doc = $this->document();
        $doc['assignments'] = [];
        $row = $this->draft($doc);
        $team = $doc['teams'][0]['key'];
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)
            ->call('moveMember', 'player', $this->member, null, $team)->assertHasNoErrors()
            ->call('moveMember', 'player', $this->member, null, $team)->assertHasNoErrors();
        $this->assertCount(1, $page->get('document')['assignments']);
        $page->call('addRow', 'teams')->assertHasNoErrors();
        $other = $page->get('document')['teams'][1]['key'];
        $page->call('mergeTeam', $team, $other)->assertHasNoErrors();
        $this->assertSame($other, $page->get('document')['assignments'][0]['team_key']);
        $page->call('removeRow', 'teams', $other)->assertHasNoErrors();
        $this->assertSame([], $page->get('document')['assignments']);
        $this->assertSame([], $page->get('document')['slots']);
        $this->assertSame(0, $this->rows('seasons'));
        $page->set('kind', 'staff')->set('onlyMatching', true)->call('go', 5)->assertSee('Fixture Player')->assertHasNoErrors();
    }

    public function test_read_only_profile_cannot_save_and_completion_requires_every_create_permission(): void
    {
        $row = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $permissions = ['seasons.view', 'teams.view', 'members.view', 'contributions.view', 'training_slots.view'];
        $profile = $this->insert('access_profiles', ['name' => 'Planner fixture', 'permissions' => json_encode($permissions), 'active' => true]);
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'profile', 'access_profile_id' => $profile]);
        $this->assertSame($row->id, $this->service()->get($this->actor, $this->tenant, $row->id)->id);
        try {
            $this->service()->save($this->actor, $this->tenant, $row->id, 1, $row->document);
            $this->fail('Read-only write allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $permissions = [...$permissions, 'seasons.create', 'seasons.transfer', 'teams.create', 'contributions.create'];
        $this->db->table('clubora.access_profiles')->where('id', $profile)->update(['permissions' => json_encode($permissions)]);
        try {
            $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
            $this->fail('Missing training permission allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, $this->rows('seasons'));
    }

    public function test_suggestions_only_add_matching_unassigned_players_and_stale_completion_is_blocked(): void
    {
        $doc = $this->document();
        $doc['assignments'] = [];
        $row = $this->draft($doc);
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->insert('members', ['first_name' => 'Older', 'birth_date' => '1980-01-01', 'gender_id' => $this->gender]);
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)
            ->call('suggest', $doc['teams'][0]['key'])->assertHasNoErrors();
        $this->assertCount(1, $page->get('document')['assignments']);
        $page->call('suggest', $doc['teams'][0]['key'])->assertHasNoErrors();
        $this->assertCount(1, $page->get('document')['assignments']);
        try {
            $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
            $this->fail('Old revision allowed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, $this->rows('seasons'));
    }

    public function test_hundred_player_completion_runs_business_services_and_rolls_back_fixture(): void
    {
        $members = [];
        for ($i = 0; $i < 100; $i++) {
            $members[] = ['tenant_id' => $this->tenant->id, 'first_name' => 'Load fixture', 'last_name' => 'Synthetic', 'birth_date' => '2015-01-01', 'gender_id' => $this->gender];
        }
        $this->db->table('clubora.members')->insert($members);
        $doc = $this->document();
        foreach ($this->db->table('clubora.members')->where('tenant_id', $this->tenant->id)->where('id', '<>', $this->member)->orderBy('id')->pluck('id') as $id) {
            $doc['assignments'][] = ['key' => (string) Str::uuid(), 'kind' => 'player', 'team_key' => $doc['teams'][0]['key'], 'member_id' => $id, 'function_id' => null];
        }
        $row = $this->draft($doc);
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $this->assertSame([], $review['errors']);
        $started = microtime(true);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], true);
        $queries = count($this->db->getQueryLog());
        $this->db->disableQueryLog();
        fwrite(STDOUT, sprintf("\nSynthetic completion: 101 players, %.2fs, %d DB statements; outer fixture rollback.\n", microtime(true) - $started, $queries));
        $this->assertSame(101, $this->rows('team_players'));
        $this->assertSame(1, $this->rows('seasons'));
        $this->assertSame(109, $this->rows('season_plan_results'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_assignment_batch_rejects_duplicates_foreign_references_and_revoked_rights(): void
    {
        $draft = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $draft->id);
        $this->service()->complete($this->actor, $this->tenant, $draft->id, 1, $review['token'], false);
        $assignment = TeamPlayer::where('tenant_id', $this->tenant->id)->sole();
        $data = ['team_season_id' => $assignment->team_season_id, 'member_id' => $this->member, 'active' => true];
        $service = app(TeamAssignmentService::class);
        foreach ([[$data], [array_replace($data, ['member_id' => -1])], [$data + ['tenant_id' => $this->tenant->id]]] as $inputs) {
            try {
                $service->createMany($this->actor, $this->tenant, TeamPlayer::class, $inputs);
                $this->fail('Invalid batch accepted');
            } catch (ValidationException) {
                $this->assertSame(1, $this->rows('team_players'));
            }
        }
        $first = $this->insert('members', ['first_name' => 'Batch A']);
        $second = $this->insert('members', ['first_name' => 'Batch B']);
        TeamPlayer::created(function () {
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        });
        try {
            $service->createMany($this->actor, $this->tenant, TeamPlayer::class,
                [array_replace($data, ['member_id' => $first]), array_replace($data, ['member_id' => $second])]);
            $this->fail('Revoked rights accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(1, $this->rows('team_players'));
    }

    public function test_existing_overlapping_training_is_warned_and_bound_into_review(): void
    {
        $row = $this->draft();
        $review = $this->service()->review($this->actor, $this->tenant, $row->id);
        $season = $this->service()->complete($this->actor, $this->tenant, $row->id, 1, $review['token'], false);
        $doc = $this->service()->seed($this->actor, $this->tenant, $season);
        $doc['season']['starts_on'] = '2030-08-01';
        $draft = $this->draft($doc);
        $review = $this->service()->review($this->actor, $this->tenant, $draft->id);
        $this->assertSame([], $review['errors']);
        $this->assertContains('Trainingszeit überschneidet sich mit einer bestehenden Belegung einer zeitlich überlappenden Saison; Belegung und Platzaufteilung prüfen.', $review['warnings']);
        $this->db->table('clubora.training_slots')->where('tenant_id', $this->tenant->id)->update(['starts_at' => '19:00:00']);
        try {
            $this->service()->complete($this->actor, $this->tenant, $draft->id, 1, $review['token'], true);
            $this->fail('Changed occupation accepted');
        } catch (ValidationException) {
            $this->assertSame(1, $this->rows('seasons'));
        }
    }

    public function test_member_pool_labels_and_search_include_companies_and_unnamed_records(): void
    {
        $this->insert('members', ['company' => 'QA Partner']);
        $unnamed = $this->insert('members', ['first_name' => null, 'last_name' => null]);
        $row = $this->draft();
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)->call('go', 4)
            ->assertSee('QA Partner')->assertSee('Mitglied #'.$unnamed)->assertHasNoErrors();
        $page->set('search', 'QA Partner')->assertSee('Mitgliederpool (1)')->assertSee('QA Partner')->assertDontSee('Mitglied #'.$unnamed);
        $page->set('search', 'Mitglied #'.$unnamed)->assertSee('Mitgliederpool (1)')->assertSee('Mitglied #'.$unnamed)->assertDontSee('QA Partner');
        $this->assertSame(0, $this->rows('seasons'));
    }

    public function test_wizard_is_placed_in_sport_navigation_not_in_settings(): void
    {
        $item = NavigationItem::make('Saisonplanung')->url(SeasonPlanning::getNavigationUrl());
        $parts = NavigationStructure::partition(Filament::getPanel('clubunio'),
            [NavigationGroup::make('Sport')->items([$item])]);
        $sport = collect($parts['navigation'])->first(fn ($group) => $group->getLabel() === 'Sport');
        $this->assertNotNull($sport);
        $this->assertTrue(collect($sport->getItems())->contains(fn ($entry) => $entry->getUrl() === SeasonPlanning::getNavigationUrl()));
        $this->assertSame([], $parts['settings']);
    }

    public function test_member_moves_between_teams_resume_without_duplicate_or_domain_writes(): void
    {
        $doc = $this->document();
        $doc['assignments'] = [];
        $other = array_replace($doc['teams'][0], ['key' => (string) Str::uuid(), 'name' => 'Second fixture']);
        $doc['teams'][] = $other;
        $row = $this->draft($doc);
        $first = $doc['teams'][0]['key'];
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)->call('go', 4);

        foreach (['player', 'staff', 'lead'] as $kind) {
            $page->set('kind', $kind)->set('functionId', $this->function)
                ->call('moveMember', $kind, $this->member, null, $first)
                ->call('moveMember', $kind, $this->member, $first, $other['key'])
                ->call('moveMember', $kind, $this->member, null, $other['key'])
                ->assertHasNoErrors();
            $saved = $this->service()->get($this->actor, $this->tenant, $row->id);
            $links = collect($saved->document['assignments'])->where('kind', $kind)->values();
            $this->assertCount(1, $links);
            $this->assertSame($other['key'], $links[0]['team_key']);
            $this->assertSame($kind === 'staff' ? $this->function : null, $links[0]['function_id']);
        }

        $page->call('openPlan', $row->id)->assertHasNoErrors();
        $this->assertCount(3, $page->get('document')['assignments']);
        foreach (['seasons', 'team_seasons', 'team_players', 'team_staff', 'technical_leads'] as $table) {
            $this->assertSame(0, $this->rows($table), $table);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_filled_training_slot_autosaves_all_values_and_resumes_without_completion(): void
    {
        $doc = $this->document();
        $doc['slots'] = [];
        $row = $this->draft($doc);
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)
            ->call('go', 6)->call('addRow', 'slots')->assertHasNoErrors();
        $slot = $page->get('document')['slots'][0];
        $slot = array_replace($slot, [
            'team_key' => $doc['teams'][0]['key'], 'starts_at' => '17:45', 'ends_at' => '19:15',
            'hall' => 'QA Halle', 'all_fields' => true, 'without_main' => true,
            'synthetic_only' => false, 'website_visible' => false,
        ]);
        foreach ($this->options as $category => $id) {
            $slot[$category.'_id'] = $id;
        }
        foreach ($slot as $column => $value) {
            if ($column !== 'key') {
                $page->set('document.slots.0.'.$column, $value)->assertHasNoErrors();
            }
        }
        $page->assertSet('document.slots.0.starts_at', '17:45')
            ->assertSet('document.slots.0.ends_at', '19:15');
        $slot['starts_at'] = '17:45:00';
        $slot['ends_at'] = '19:15:00';
        $page->call('go', 4)->call('openPlan', $row->id)->call('go', 6)
            ->assertSet('document.slots.0', $slot)->assertSet('state', 'draft')->assertHasNoErrors();
        $this->assertSame($slot, $this->service()->get($this->actor, $this->tenant, $row->id)->document['slots'][0]);
        foreach (['seasons', 'training_slots', 'season_plan_results'] as $table) {
            $this->assertSame(0, $this->rows($table), $table);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_new_draft_uses_inline_confirmation_and_preserves_previous_draft(): void
    {
        $existing = $this->draft();
        $page = Livewire::test(SeasonPlanning::class)->call('openPlan', $existing->id)
            ->assertSee('Neuen Entwurf anlegen?')->assertSee('Entwurf anlegen')
            ->assertDontSee('wire:confirm="Neue Planung erstellen?', false);
        $page->call('newPlan')->assertHasNoErrors()->assertSet('state', 'draft');
        $this->assertNotSame($existing->id, $page->get('planId'));
        $this->assertSame($existing->document, $this->service()->get($this->actor, $this->tenant, $existing->id)->document);
        $this->assertSame(2, $this->rows('season_plans'));
        $this->assertSame(0, $this->rows('seasons'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_web_discard_uses_inline_confirmation_and_keeps_draft_history(): void
    {
        $row = $this->draft();
        Livewire::test(SeasonPlanning::class)->call('openPlan', $row->id)
            ->assertSee('Diesen Entwurf verwerfen?')->assertSee('Entwurf verwerfen')
            ->assertDontSee('wire:confirm="Entwurf verwerfen?', false)
            ->call('discard')->assertSet('state', 'discarded')->assertHasNoErrors()
            ->assertSee('Verworfener Entwurf.');
        $saved = $this->service()->get($this->actor, $this->tenant, $row->id);
        $this->assertSame($row->document, $saved->document);
        $this->assertSame('discarded', $saved->state);
        $this->assertSame(0, $this->rows('seasons'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
