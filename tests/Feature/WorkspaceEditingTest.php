<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ContributionPrice;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\NavigationStructure;
use App\ClubUnio\Pages\SettingsOverview;
use App\ClubUnio\Resources\ContributionPriceResource\Pages\ManagePrices;
use App\ClubUnio\Resources\FamilyResource\Pages\ViewFamily;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\Relations\ContributionRulesRelationManager;
use App\ClubUnio\Resources\Relations\FamiliesRelationManager;
use App\ClubUnio\Resources\Relations\FamilyMembersRelationManager;
use App\ClubUnio\Resources\Relations\TeamSeasonsRelationManager;
use App\ClubUnio\Resources\SeasonResource\Pages\ViewSeason;
use App\ClubUnio\Resources\TeamSeasonResource\Pages\ListTeamSeasons;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\DossierHistoryService;
use App\ClubUnio\Services\SeasonAssignmentTransferService;
use App\ClubUnio\Services\TeamAssignmentService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceEditingTest extends TestCase
{
    public function test_navigation_is_prioritized_and_settings_are_not_lost(): void
    {
        $this->get('/members')->assertOk();
        $panel = Filament::getCurrentPanel();
        $raw = $panel->getAuthorizedNavigation();
        $partition = NavigationStructure::partition($panel, $raw);
        $groups = array_map(fn ($group) => $group->getLabel(), $partition['navigation']);
        $this->assertSame([null, 'Mitglieder', 'Sport', 'Finanzen', 'Anlagen & Kommunikation', 'Auswertungen', null], $groups);
        $visible = collect($partition['navigation'])->flatMap(fn ($group) => $group->getItems());
        $this->assertFalse($visible->contains(fn ($item) => str_contains($item->getUrl(), '/countries')));
        $all = $visible->concat(collect($partition['settings'])->flatten(1))->map(fn ($item) => $item->getUrl())->sort()->values()->all();
        $original = collect($raw)->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getUrl())->sort()->values()->all();
        $this->assertSame($original, $all);
        $this->get('/configuration')->assertOk()->assertSee('Einstellungen suchen')->assertSee('Sport &amp; Anlagen', false)->assertSee('Parameter');
    }

    public function test_settings_overview_is_not_accessible_after_role_revocation(): void
    {
        $this->get('/configuration')->assertOk();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->assertFalse(SettingsOverview::canAccess());
        $this->get('/configuration')->assertForbidden();
    }

    private $db;

    public function test_bulk_assignment_actions_and_history_dialog(): void
    {
        $component = $this->manager();
        $component->callTableAction('addAssignment', data: ['team_id' => $this->team->id, 'active' => true])->assertHasNoTableActionErrors();
        $record = TeamSeason::where('tenant_id', $this->tenant->id)->sole();
        $component->callTableBulkAction('deactivateAssignments', [$record])->assertHasNoTableActionErrors();
        $this->assertFalse($record->fresh()->active);
        $component->filterTable('active', false)->callTableBulkAction('restoreAssignments', [$record->fresh()])->assertHasNoTableActionErrors();
        $this->assertTrue($record->fresh()->active);
        $component->filterTable('active', true)->mountTableAction('dossierHistory', $record->fresh())->assertSee('Änderungsverlauf')->assertSee('Fixture');
        $component = $this->manager();
        $component->mountTableBulkAction('deactivateAssignments', [$record->fresh()]);
        $record->refresh()->forceFill(['label' => 'Concurrent change'])->save();
        $component->callMountedTableAction()->assertHasTableActionErrors();
        $this->assertTrue($record->fresh()->active);
    }

    public function test_season_transfer_preview_duplicates_and_stale_snapshot(): void
    {
        $service = app(SeasonAssignmentTransferService::class);
        $target = new Season;
        $target->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Next fixture', 'active' => true])->save();
        $team = app(TeamAssignmentService::class)->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $this->team->id, 'season_id' => $this->season->id, 'active' => true]);
        $member = new Member;
        $member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Fixture', 'last_name' => 'Player'])->save();
        app(TeamAssignmentService::class)->save($this->actor, $this->tenant, TeamPlayer::class, null, ['member_id' => $member->id, 'team_season_id' => $team->id, 'active' => true]);
        $function = $this->db->table('clubora.staff_functions')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'fixture-coach', 'name' => 'Fixture coach', 'active' => true]);
        app(TeamAssignmentService::class)->save($this->actor, $this->tenant, TeamStaff::class, null, ['member_id' => $member->id, 'team_season_id' => $team->id, 'staff_function_id' => $function, 'active' => true]);
        app(TeamAssignmentService::class)->save($this->actor, $this->tenant, TechnicalLead::class, null, ['member_id' => $member->id, 'team_season_id' => $team->id, 'active' => true]);
        $plan = $service->preview($this->actor, $this->tenant, $this->season->id, $target->id);
        $this->assertCount(4, $plan['rows']);
        $this->assertSame(4, $service->apply($this->actor, $this->tenant, $this->season->id, $target->id, $plan['token']));
        $again = $service->preview($this->actor, $this->tenant, $this->season->id, $target->id);
        $this->assertSame(0, $service->apply($this->actor, $this->tenant, $this->season->id, $target->id, $again['token']));
        $this->assertCount(4, array_filter($again['rows'], fn ($r) => $r['state'] === 'vorhanden'));
        $component = $this->manager();
        $component->mountTableAction('transferSeason')->assertSet('mountedActions.0.name', 'transferSeason');
        $component->set('mountedActions.0.data.target_season_id', $target->id)->assertSet('seasonTransferPreview.target', $target->id);
        $component->set('mountedActions.0.data.confirmed', true)->callMountedTableAction()->assertHasNoTableActionErrors();
        $member->forceFill(['exit_date' => now()->toDateString()])->save();
        $blocked = $service->preview($this->actor, $this->tenant, $this->season->id, $target->id);
        $this->assertTrue(collect($blocked['rows'])->contains('state', 'blockiert'));
        $this->expectException(ValidationException::class);
        $service->apply($this->actor, $this->tenant, $this->season->id, $target->id, $again['token']);
    }

    public function test_bulk_changes_roll_back_when_any_selected_row_is_stale(): void
    {
        $otherTeam = new Team;
        $otherTeam->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Other fixture', 'active' => true])->save();
        $service = app(TeamAssignmentService::class);
        $a = $service->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $this->team->id, 'season_id' => $this->season->id, 'active' => true]);
        $b = $service->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $otherTeam->id, 'season_id' => $this->season->id, 'active' => true]);
        $component = $this->manager()->mountTableBulkAction('deactivateAssignments', [$a, $b]);
        $b->forceFill(['label' => 'Concurrent change'])->save();
        $component->callMountedTableAction()->assertHasTableActionErrors();
        $this->assertTrue($a->fresh()->active);
        $this->assertTrue($b->fresh()->active);
        $this->assertSame(2, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_season_transfer_only_selected_teams_and_rejects_invalid_selection(): void
    {
        $target = new Season;
        $target->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Target', 'active' => true])->save();
        $other = new Team;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Blocked team', 'active' => true])->save();
        $save = app(TeamAssignmentService::class);
        $a = $save->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $this->team->id, 'season_id' => $this->season->id, 'active' => true]);
        $b = $save->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $other->id, 'season_id' => $this->season->id, 'active' => true]);
        $other->forceFill(['active' => false])->save();
        $service = app(SeasonAssignmentTransferService::class);
        $this->assertTrue(collect($service->preview($this->actor, $this->tenant, $this->season->id, $target->id)['rows'])->contains('state', 'blockiert'));
        $plan = $service->preview($this->actor, $this->tenant, $this->season->id, $target->id, [$a->id]);
        $this->assertCount(1, $plan['rows']);
        foreach ([[], [$target->id + 999999], [$b->id]] as $invalid) {
            try {
                $service->apply($this->actor, $this->tenant, $this->season->id, $target->id, $plan['token'], $invalid);
                $this->fail('Invalid or changed selection accepted');
            } catch (ValidationException) {
                $this->assertSame(0, TeamSeason::where('tenant_id', $this->tenant->id)->where('season_id', $target->id)->count());
            }
        }
        $component = $this->manager()->mountTableAction('transferSeason');
        $component->set('mountedActions.0.data.target_season_id', $target->id);
        $component->set('mountedActions.0.data.confirmed', true)->set('mountedActions.0.data.team_season_ids', [$a->id])->assertSet('mountedActions.0.data.confirmed', false);
        $component->set('mountedActions.0.data.confirmed', true)->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame($this->team->id, TeamSeason::where('tenant_id', $this->tenant->id)->where('season_id', $target->id)->sole()->team_id);
    }

    public function test_history_redacts_unknown_fields_and_rechecks_permissions(): void
    {
        $record = app(TeamAssignmentService::class)->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $this->team->id, 'season_id' => $this->season->id, 'active' => true]);
        $this->db->table('clubora.object_changes')->insert(['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id, 'object_type' => 'team_seasons', 'object_id' => $record->id, 'after_value' => json_encode(['active' => false, 'api_key' => 'NEVER_SHOW', 'iban_encrypted' => 'NEVER_SHOW'])]);
        $service = app(DossierHistoryService::class);
        $this->assertStringNotContainsString('NEVER_SHOW', json_encode($service->entries($this->actor, $this->tenant, $record)));
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        $service->entries($this->actor, $this->tenant, $record);
    }

    public function test_transfer_blocks_inactive_targets_and_rechecks_role(): void
    {
        $target = new Season;
        $target->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Target', 'active' => true])->save();
        $assign = app(TeamAssignmentService::class);
        $assign->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $this->team->id, 'season_id' => $this->season->id, 'active' => true]);
        $destination = $assign->save($this->actor, $this->tenant, TeamSeason::class, null, ['team_id' => $this->team->id, 'season_id' => $target->id, 'active' => false]);
        $service = app(SeasonAssignmentTransferService::class);
        $plan = $service->preview($this->actor, $this->tenant, $this->season->id, $target->id);
        $this->assertSame('blockiert', $plan['rows'][0]['state']);
        try {
            $service->apply($this->actor, $this->tenant, $this->season->id, $target->id, $plan['token']);
            $this->fail('Inactive target must block transfer');
        } catch (ValidationException $e) {
            $this->assertFalse($destination->fresh()->active);
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        $service->apply($this->actor, $this->tenant, $this->season->id, $target->id, $plan['token']);
    }

    private Tenant $tenant;

    private Account $actor;

    private Season $season;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional database opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'workspace-'.Str::random(12), 'name' => 'Fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'office']);
        $this->season = new Season;
        $this->season->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Fixture Season', 'active' => true])->save();
        $this->team = new Team;
        $this->team->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Fixture Team', 'active' => true])->save();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function manager()
    {
        return Livewire::test(TeamSeasonsRelationManager::class, ['ownerRecord' => $this->season, 'pageClass' => ViewSeason::class]);
    }

    public function test_family_assignments_are_editable_from_both_dossiers(): void
    {
        $member = new Member;
        $member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Fixture'])->save();
        $family = new Family;
        $family->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Fixture family'])->save();
        $members = Livewire::test(FamilyMembersRelationManager::class, ['ownerRecord' => $family, 'pageClass' => ViewFamily::class]);
        $members->callTableAction('addAssignment', data: ['member_id' => $member->id, 'family_id' => -1, 'discount' => 10, 'active' => true])->assertHasNoTableActionErrors();
        $link = $family->memberships()->sole();
        $this->assertSame($family->id, $link->family_id);
        $dossier = Livewire::test(FamiliesRelationManager::class, ['ownerRecord' => $member, 'pageClass' => ViewMember::class]);
        $dossier->callTableAction('editAssignment', $link, data: ['discount' => 20])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertEquals(20, $link->fresh()->discount);
        $dossier->callTableAction('copyAssignment', $link->fresh())->assertHasTableActionErrors();
        $members->callTableAction('deactivateAssignment', $link->fresh())->assertHasNoTableActionErrors();
        $this->assertFalse($link->fresh()->active);
        $other = new Family;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Other'])->save();
        Livewire::test(FamilyMembersRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewFamily::class])
            ->call('saveAssignment', $link->id, [])->assertStatus(404);
    }

    public function test_season_assignments_are_created_and_deactivated_in_the_dossier(): void
    {
        $component = $this->manager();
        $component->callTableAction('addAssignment', data: ['team_id' => $this->team->id, 'season_id' => -999, 'active' => true])->assertHasNoTableActionErrors();
        $record = TeamSeason::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame($this->season->id, $record->season_id);
        $component->callTableAction('editAssignment', $record, data: ['active' => false])->assertHasNoTableActionErrors();
        $this->assertFalse($record->fresh()->active);
        $this->assertGreaterThan(0, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_copy_requires_a_different_assignment_and_keeps_the_source(): void
    {
        $component = $this->manager();
        $component->callTableAction('addAssignment', data: ['team_id' => $this->team->id, 'active' => true])->assertHasNoTableActionErrors();
        $record = TeamSeason::where('tenant_id', $this->tenant->id)->sole();
        $component->callTableAction('copyAssignment', $record, data: ['team_id' => $this->team->id])->assertHasTableActionErrors();
        $other = new Team;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Other Team', 'active' => true])->save();
        $this->manager()->callTableAction('copyAssignment', $record, data: ['team_id' => $other->id])->assertHasNoTableActionErrors();
        $this->assertSame(2, TeamSeason::where('tenant_id', $this->tenant->id)->count());
        $this->assertTrue($record->fresh()->active);
    }

    public function test_deactivate_preserves_the_record(): void
    {
        $component = $this->manager();
        $component->callTableAction('addAssignment', data: ['team_id' => $this->team->id, 'active' => true])->assertHasNoTableActionErrors();
        $record = TeamSeason::where('tenant_id', $this->tenant->id)->sole();
        $component->callTableAction('deactivateAssignment', $record)->assertHasNoTableActionErrors();
        $this->assertNotNull($record->fresh());
        $this->assertFalse($record->fresh()->active);
        $component->filterTable('active', false)->callTableAction('restoreAssignment', $record->fresh())->assertHasNoTableActionErrors();
        $this->assertTrue($record->fresh()->active);
    }

    public function test_standalone_copy_and_deactivate_use_the_same_services(): void
    {
        $component = Livewire::test(ListTeamSeasons::class);
        $component->callTableAction('create', data: ['team_id' => $this->team->id, 'season_id' => $this->season->id, 'active' => true])->assertHasNoTableActionErrors();
        $record = TeamSeason::where('tenant_id', $this->tenant->id)->sole();
        $component->callTableAction('copyRow', $record)->assertHasTableActionErrors();
        $component = Livewire::test(ListTeamSeasons::class);
        $component->callTableAction('deactivateRow', $record)->assertHasNoTableActionErrors();
        $this->assertFalse($record->fresh()->active);
    }

    public function test_contribution_price_copy_and_deactivate(): void
    {
        $component = Livewire::test(ManagePrices::class);
        $component->callTableAction('create', data: ['name' => 'Original', 'amount' => 20, 'active' => true])->assertHasNoTableActionErrors();
        $record = ContributionPrice::where('tenant_id', $this->tenant->id)->sole();
        $component->callTableAction('copyRow', $record, data: ['name' => 'Copy', 'amount' => 25])->assertHasNoTableActionErrors();
        $this->assertSame(2, ContributionPrice::where('tenant_id', $this->tenant->id)->count());
        $component->callTableAction('deactivateRow', $record)->assertHasNoTableActionErrors();
        $this->assertFalse($record->fresh()->active);
    }

    public function test_foreign_parent_record_is_not_editable_via_public_method(): void
    {
        $other = new Season;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Other Season', 'active' => true])->save();
        $record = app(TeamAssignmentService::class)->save($this->actor, $this->tenant, TeamSeason::class, null,
            ['team_id' => $this->team->id, 'season_id' => $other->id, 'active' => true]);
        $this->manager()->call('saveAssignment', $record->id, ['active' => false])->assertStatus(404);
        $this->assertTrue($record->fresh()->active);
    }

    public function test_foreign_tenant_team_is_rejected(): void
    {
        $other = Tenant::create(['slug' => 'other-'.Str::random(10), 'name' => 'Other']);
        $team = new Team;
        $team->forceFill(['tenant_id' => $other->id, 'name' => 'Other', 'active' => true])->save();
        $this->manager()->callTableAction('addAssignment', data: ['team_id' => $team->id, 'active' => true])->assertHasTableActionErrors();
        $this->assertSame(0, TeamSeason::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_revoked_role_cannot_submit_an_open_dialog(): void
    {
        $component = $this->manager()->mountTableAction('addAssignment');
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $component->call('saveAssignment', null, ['team_id' => $this->team->id, 'active' => true])->assertStatus(403);
    }

    public function test_contribution_rule_modal_is_parent_bound_and_revision_protected(): void
    {
        $price = app(ContributionTrainingService::class)->save($this->actor, $this->tenant,
            ContributionPrice::class, null, ['name' => 'Fixture', 'amount' => 20, 'active' => true]);
        $gender = new Gender;
        $gender->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Fixture', 'code' => 'fixture', 'sort_order' => 0, 'active' => true])->save();
        $component = Livewire::test(ContributionRulesRelationManager::class,
            ['ownerRecord' => $this->season, 'pageClass' => ViewSeason::class]);
        $component->callTableAction('addAssignment', data: ['season_id' => -1, 'price_id' => $price->id, 'gender_id' => $gender->id, 'year_from' => 2000, 'year_to' => 2005, 'active' => true])->assertHasNoTableActionErrors();
        $record = ContributionRule::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame($this->season->id, $record->season_id);
        $component->callTableAction('editAssignment', $record, data: ['year_to' => 2006])->assertHasNoTableActionErrors();
        $this->assertSame(2006, $record->fresh()->year_to);
        $component->mountTableAction('deactivateAssignment', $record->fresh());
        $record->fresh()->forceFill(['revision' => 3])->save();
        $component->callMountedTableAction()->assertHasTableActionErrors();
        $this->assertTrue($record->fresh()->active);
    }
}
