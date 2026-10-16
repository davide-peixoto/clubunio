<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Resources\MemberReportResource;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Resources\Relations\InvoicesRelationManager;
use App\ClubUnio\Widgets\ClubOverview;
use App\ClubUnio\Widgets\MembersByAge;
use App\ClubUnio\Widgets\OpenInvoicesTable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileUiTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private AccessProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transaction opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        if (! Schema::connection('clubunio')->hasTable('clubora.access_profiles')) {
            (require database_path('clubunio/2026_10_16_000069_access_profiles.php'))->up();
        }
        if (! Schema::connection('clubunio')->hasColumn('clubora.tenant_accounts', 'access_profile_id')) {
            (require database_path('clubunio/2026_10_16_000070_account_access_profiles.php'))->up();
        }
        $this->db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
        $this->tenant = Tenant::create(['slug' => 'ui-'.Str::random(12), 'name' => 'Profile UI fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'UI fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'UI fixture', 'active' => true,
            'permissions' => ['members.view'], 'revision' => 1])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id,
            'account_id' => $this->actor->id, 'role' => 'profile', 'access_profile_id' => $this->profile->id]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function permissions(array $permissions): void
    {
        $this->profile->forceFill(['permissions' => $permissions])->save();
    }

    public function test_read_only_actions_and_unknown_actions_are_denied(): void
    {
        $component = new \App\ClubUnio\Resources\MemberResource\Pages\ListMembers;
        foreach (['create', 'edit', 'copyRow', 'deactivateRow', 'restore', 'pdf', 'export', 'unknown'] as $name) {
            $this->assertFalse(ProfileUi::actionAllowed(Action::make($name)->livewire($component)), $name);
        }
        $this->assertTrue(ProfileUi::actionAllowed(Action::make('view')->livewire($component)));
        $this->assertFalse(MemberResource::canCreate());
        $record = (new Member)->forceFill(['tenant_id' => $this->tenant->id]);
        $this->assertFalse(MemberResource::canEdit($record));
        $this->permissions(['members.view', 'members.update']);
        $this->assertTrue(MemberResource::canEdit($record));
        $this->assertFalse(MemberResource::canCreate());
        $record->archived_at = now();
        $this->assertFalse(MemberResource::canEdit($record));
    }

    public function test_administrative_actions_remain_unmapped_and_bexio_operations_match_services(): void
    {
        foreach (['checkInstagram', 'design', 'instagram', 'manage', 'newLink', 'newAccount',
            'newProfile', 'editProfile', 'copyProfile', 'bexio_unknown'] as $name) {
            $this->assertNull(ProfileUi::actionFunction($name), $name);
        }
        $this->assertSame('send', ProfileUi::actionFunction('bexio_issue'));
        $this->assertSame('cancel', ProfileUi::actionFunction('bexio_cancel'));
    }

    public function test_widget_and_report_permissions_are_independent(): void
    {
        $this->assertTrue(MembersByAge::canView());
        $this->assertFalse(OpenInvoicesTable::canView());
        $this->assertFalse(ClubOverview::canView());
        $this->assertFalse(MemberReportResource::canViewAny());
        $this->permissions(['reports.view', 'invoices.view']);
        $this->assertTrue(MemberReportResource::canViewAny());
        $this->assertTrue(OpenInvoicesTable::canView());
        $this->assertFalse(MembersByAge::canView());
        $this->assertFalse(ClubOverview::canView());
    }

    public function test_panel_guard_preserves_visibility_and_modal_controls(): void
    {
        Filament::getCurrentPanel()->boot();
        $component = new \App\ClubUnio\Resources\MemberResource\Pages\ListMembers;
        $this->assertTrue(Action::make('export')->livewire($component)->isHidden());
        $this->assertTrue(Action::make('view')->livewire($component)->visible(false)->isHidden());
        $this->permissions(['members.view', 'members.update']);
        $edit = Action::make('edit')->livewire($component);
        $this->assertFalse($edit->isHidden());
        $this->assertFalse(Action::make('submit')->parentAction($edit)->livewire($component)->isHidden());
        $this->assertFalse(Action::make('cancel')->parentAction($edit)->livewire($component)->isHidden());
        $this->permissions(['members.view']);
        $this->assertTrue(Action::make('submit')->parentAction($edit)->livewire($component)->isHidden());
    }

    public function test_relation_requires_child_and_parent_permission(): void
    {
        $member = (new Member)->forceFill(['tenant_id' => $this->tenant->id]);
        $page = \App\ClubUnio\Resources\MemberResource\Pages\ViewMember::class;
        $this->assertFalse(InvoicesRelationManager::canViewForRecord($member, $page));
        $this->permissions(['members.view', 'invoices.view']);
        $this->assertTrue(InvoicesRelationManager::canViewForRecord($member, $page));
        $this->permissions(['invoices.view']);
        $this->assertFalse(InvoicesRelationManager::canViewForRecord($member, $page));
        $member->tenant_id = -1;
        $this->assertFalse(InvoicesRelationManager::canViewForRecord($member, $page));
    }

    public function test_direct_custom_mutation_is_forbidden_for_read_only_profile(): void
    {
        $this->permissions(['contributions.view']);
        $page = new \App\ClubUnio\Pages\ContributionPreview;
        $this->assertTrue($page::canAccess());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $page->createDraft();
    }

    public function test_profile_deactivation_revokes_ui_access(): void
    {
        $this->assertTrue(ProfileUi::model(Member::class));
        $this->profile->forceFill(['active' => false])->save();
        $this->assertFalse(ProfileUi::model(Member::class));
        $this->assertFalse(MembersByAge::canView());
        $this->assertFalse(MemberResource::canCreate());
    }

    public function test_direct_mounted_action_is_denied_after_permission_revocation(): void
    {
        $this->permissions(['members.view', 'members.create']);
        $component = \Livewire\Livewire::test(\App\ClubUnio\Resources\MemberResource\Pages\ListMembers::class)
            ->mountTableAction('create');
        $this->permissions(['members.view']);
        $component->call('callMountedAction')->assertForbidden();
        $this->assertSame(0, Member::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_all_view_profile_can_read_mapped_resources_but_cannot_mutate(): void
    {
        $this->permissions(\App\ClubUnio\Services\PermissionCatalog::readOnly());
        $adminOnly = ['AccessProfileResource', 'TenantAccountResource', 'AccountMemberLinkResource',
            'SettingResource', 'StatusStyleResource', 'BusKeyboxResource', 'ObjectChangeResource'];
        foreach (glob(app_path('ClubUnio/Resources/*Resource.php')) as $path) {
            $name = basename($path, '.php');
            $class = 'App\\ClubUnio\\Resources\\'.$name;
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            if (in_array($name, $adminOnly, true)) {
                $this->assertFalse($class::canViewAny(), $name.' remains administrative');
                continue;
            }
            $model = $class::getModel();
            $this->assertNotNull(\App\ClubUnio\Services\ModulePermissionService::modelModule($model),
                $name.' must have an explicit module mapping or administrative exception');
            $this->assertTrue($class::canViewAny(), $name.' allows module view');
            $this->assertFalse($class::canCreate(), $name.' denies create');
            $record = (new $model)->forceFill(['tenant_id' => $this->tenant->id]);
            $this->assertFalse($class::canEdit($record), $name.' denies update');
        }
    }

    public function test_training_navigation_does_not_require_planning_permission(): void
    {
        $this->permissions(['training.view']);
        $panel = Filament::getCurrentPanel();
        $sections = \App\ClubUnio\NavigationStructure::partition($panel, []);
        $links = collect($sections['navigation'])->flatMap(fn ($group) => $group->getItems());
        $this->assertTrue($links->contains(fn ($item) => $item->getUrl() === route('clubunio.training.index', $this->tenant->slug)));
        $this->assertFalse(\App\ClubUnio\Pages\TeamPlanning::canAccess());
        $this->permissions(['members.view']);
        $this->assertSame([], \App\ClubUnio\NavigationStructure::partition($panel, [])['navigation']);
    }

    public function test_bexio_dispatch_navigation_is_registered_and_module_guarded(): void
    {
        $page = \App\ClubUnio\Pages\BexioDispatchTrials::class;
        $this->assertTrue($page::shouldRegisterNavigation());
        $this->assertFalse($page::canAccess());
        $this->permissions(['bexio.view']);
        $this->assertTrue($page::canAccess());
        $sections = \App\ClubUnio\NavigationStructure::partition(Filament::getCurrentPanel(), [
            \Filament\Navigation\NavigationGroup::make('Finanzen')->items($page::getNavigationItems()),
        ]);
        $this->assertTrue(collect($sections['settings']['Finanzen'])->contains(fn ($item) => $item->getUrl() === $page::getNavigationUrl()));
        $this->assertFalse($page::canDocumentOperation('invoice', 'send'));
    }

    public function test_admin_user_table_shows_and_filters_assigned_profiles(): void
    {
        $membership = \App\ClubUnio\Models\TenantAccount::where('account_id', $this->actor->id)->firstOrFail();
        $membership->forceFill(['role' => 'admin', 'access_profile_id' => null])->save();
        $assigned = new Account;
        $assigned->forceFill(['username' => Str::random(20), 'display_name' => 'Assigned fixture',
            'password' => bcrypt('fixture'), 'active' => true])->save();
        $assignment = new \App\ClubUnio\Models\TenantAccount;
        $assignment->forceFill(['tenant_id' => $this->tenant->id, 'account_id' => $assigned->id,
            'role' => 'profile', 'access_profile_id' => $this->profile->id])->save();
        $this->profile->forceFill(['active' => false])->save();

        $component = \Livewire\Livewire::test(\App\ClubUnio\Resources\TenantAccountResource\Pages\ManageAccounts::class)
            ->assertSuccessful()
            ->assertTableColumnExists('accessProfile.name')
            ->assertCanSeeTableRecords([$membership, $assignment])
            ->filterTable('access_profile_id', $this->profile->id)
            ->assertCanSeeTableRecords([$assignment])
            ->assertCanNotSeeTableRecords([$membership]);
        $this->assertSame($this->profile->name, $component->instance()->getTable()->getFilter('access_profile_id')->getOptions()[$this->profile->id]);
        $rows = \App\ClubUnio\Resources\TenantAccountResource::getEloquentQuery()->get();
        foreach ($rows as $row) {
            $this->assertTrue($row->relationLoaded('accessProfile'));
        }
        $profile = \App\ClubUnio\Resources\AccessProfileResource::getEloquentQuery()->findOrFail($this->profile->id);
        $this->assertSame(1, $profile->tenant_accounts_count);
        $this->assertFalse($profile->relationLoaded('tenantAccounts'));
    }

    private function trainingViewData(array $overrides = []): array
    {
        $plan = ['title' => 'UI training', 'focus' => 'Fixture', 'principles' => [], 'exercises' => [], 'qualityChecks' => []];

        return array_replace(['actor' => $this->actor, 'tenant' => $this->tenant, 'managed' => [], 'teams' => collect(),
            'errors' => new \Illuminate\Support\ViewErrorBag,
            'row' => (object) ['id' => 1, 'title' => $plan['title'], 'revision' => 1, 'archived_at' => null,
                'shared' => true, 'plan' => $plan, 'input' => ['teamName' => 'Fixture', 'durationMinutes' => 90, 'playerCount' => 18]],
            'editable' => false, 'canManage' => false, 'review' => ['plan' => $plan], 'requestKey' => (string) Str::uuid()], $overrides);
    }

    public function test_training_read_only_blades_hide_mutations_and_cross_module_navigation(): void
    {
        $this->permissions(['training.view']);
        $data = $this->trainingViewData();
        $this->view('clubunio.training.show', $data)->assertSee('UI training')
            ->assertDontSee('Private Kopie')->assertDontSee(' PDF')->assertDontSee('Trainingsplan bearbeiten')
            ->assertDontSee('Archivieren')->assertDontSee('Gespeicherten Plan prüfen lassen')->assertDontSee('Vorschlag übernehmen')
            ->assertDontSee('>Absenzen</a>', false)->assertDontSee('>Kalender</a>', false)
            ->assertSee('>Trainingspläne</a>', false);
        $data['plans'] = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        $this->view('clubunio.training.index', $data)->assertDontSee('Neuer Trainingsplan')->assertSee('Altpläne prüfen');
        $this->permissions(['training.view', 'training.create']);
        $this->view('clubunio.training.index', $data)->assertSee('Neuer Trainingsplan');
        $data += ['seasons' => collect(), 'selectedSeason' => null];
        $this->view('clubunio.training.create', $data)->assertSee('Trainingsplan erstellen');
        $this->permissions(['training.view']);
        $this->view('clubunio.training.create', $data)->assertDontSee('Trainingsplan erstellen');
    }

    public function test_training_blades_use_each_operation_and_preserve_sharing_without_send(): void
    {
        $data = $this->trainingViewData(['editable' => true, 'canManage' => true]);
        $this->permissions(['training.view', 'training.update']);
        $this->view('clubunio.training.show', $data)->assertSee('Trainingsplan bearbeiten')
            ->assertDontSee('Private Kopie')->assertDontSee(' PDF')->assertDontSee('Archivieren')
            ->assertDontSee('Gespeicherten Plan prüfen lassen')->assertDontSee('Vorschlag übernehmen')
            ->assertDontSee('type="checkbox" name="shared"', false)
            ->assertSee('type="hidden" name="shared" value="1"', false);
        $this->permissions(['training.view', 'training.update', 'training.send', 'training.approve', 'training.download', 'training.copy', 'training.deactivate']);
        $this->view('clubunio.training.show', $data)->assertSee('Private Kopie')->assertSee(' PDF')->assertSee('Archivieren')
            ->assertSee('Gespeicherten Plan prüfen lassen')->assertSee('Vorschlag übernehmen')
            ->assertSee('type="checkbox" name="shared"', false);
        $this->permissions(['training.view', 'training.approve']);
        $data['editable'] = $data['canManage'] = false;
        $this->view('clubunio.training.show', $data)->assertSee('Gespeicherten Plan prüfen lassen')
            ->assertDontSee('Vorschlag übernehmen')->assertDontSee('Trainingsplan bearbeiten');
        $this->permissions(['training.view', 'training.deactivate']);
        $this->view('clubunio.training.show', $data)->assertSee('Archivieren');
        $data['row']->archived_at = now();
        $this->view('clubunio.training.show', $data)->assertDontSee('Wiederherstellen')->assertDontSee('Gespeicherten Plan prüfen lassen');
        $this->permissions(['training.view', 'training.restore']);
        $this->view('clubunio.training.show', $data)->assertSee('Wiederherstellen');
        $data['row']->legacy_quality_pending = true;
        $this->view('clubunio.training.show', $data)->assertDontSee('Wiederherstellen');
    }

    public function test_training_portal_owner_guards_are_preserved(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)
            ->update(['role' => 'trainer', 'access_profile_id' => null]);
        $data = $this->trainingViewData();
        $this->view('clubunio.training.show', $data)->assertSee('Private Kopie')->assertSee(' PDF')
            ->assertDontSee('Trainingsplan bearbeiten')->assertDontSee('Archivieren')->assertDontSee('Gespeicherten Plan prüfen lassen');
        $data['editable'] = $data['canManage'] = true;
        $this->view('clubunio.training.show', $data)->assertSee('Trainingsplan bearbeiten')->assertSee('Archivieren')
            ->assertSee('Gespeicherten Plan prüfen lassen')->assertSee('Vorschlag übernehmen');
    }

    public function test_bexio_selection_fields_follow_document_and_operation_rights(): void
    {
        $ids = [];
        foreach (['ready', 'uncertain'] as $state) {
            $ids[$state] = $this->db->table('clubora.bexio_dispatch_trials')->insertGetId([
                'tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id, 'object_type' => 'expense',
                'object_id' => 1, 'reference' => (string) Str::uuid(), 'fingerprint' => Str::random(32),
                'state' => $state, 'total_cents' => 100, 'line_count' => 1,
            ]);
        }
        $page = \App\ClubUnio\Pages\BexioDispatchTrials::class;
        $this->permissions(['bexio.view', 'expenses.view']);
        \Livewire\Livewire::test($page)->assertSuccessful()->assertSet('type', 'expense')
            ->assertSeeHtml('value="expense"')->assertDontSeeHtml('value="invoice"')
            ->assertDontSee('Simulierte Antwort')->assertDontSee('Simuliertes Abgleichsergebnis')
            ->call('simulate', $ids['ready'])->assertForbidden();
        \Livewire\Livewire::test($page)->call('resolve', $ids['uncertain'])->assertForbidden();
        $this->permissions(['bexio.view', 'expenses.view', 'expenses.send']);
        \Livewire\Livewire::test($page)->assertSuccessful()->assertSee('Simulierte Antwort')->assertDontSee('Simuliertes Abgleichsergebnis');
        $this->permissions(['bexio.view', 'expenses.view', 'expenses.sync']);
        \Livewire\Livewire::test($page)->assertSuccessful()->assertDontSee('Simulierte Antwort')->assertSee('Simuliertes Abgleichsergebnis');
        $this->db->table('clubora.bexio_dispatch_trials')->whereIn('id', $ids)->update(['state' => 'simulated']);
        \Livewire\Livewire::test($page)->assertDontSee('Simulierte Antwort')->assertDontSee('Simuliertes Abgleichsergebnis');
        \Livewire\Livewire::test(\App\ClubUnio\Pages\BexioReconciliation::class)->assertSuccessful()
            ->assertSet('type', 'expense')->assertSeeHtml('value="expense"')->assertDontSeeHtml('value="invoice"');
        $this->permissions(['bexio.view']);
        \Livewire\Livewire::test($page)->assertSuccessful()->assertDontSeeHtml('wire:model.live="type"');
    }
}
