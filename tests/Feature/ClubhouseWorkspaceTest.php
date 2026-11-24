<?php

namespace Tests\Feature;

use App\ClubUnio\Services\ActiveTenant;
use App\ClubUnio\Services\ClubhouseAccess;
use App\ClubUnio\Services\ClubhouseHours;
use App\ClubUnio\Services\ClubhouseMenus;
use App\Livewire\ClubUnio\ClubhouseWorkspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ClubhouseFixture;
use Tests\TestCase;

class ClubhouseWorkspaceTest extends TestCase
{
    use ClubhouseFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clubhouseFixture();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->club, isQuiet: true);
        auth('clubunio')->setUser($this->admin);
        $active = $this->mock(ActiveTenant::class);
        $active->shouldReceive('key')->andReturn('clubhouse-test');
        $active->shouldReceive('assertSnapshot')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_backend_forms_create_and_edit_openings_and_menu_drafts(): void
    {
        $page = Livewire::test(ClubhouseWorkspace::class, ['tenantId' => 71]);
        $page->assertSee(trans('clubhouse.closed_now'));
        $page->call('selectTab', 'hours')->call('mountAction', 'newOpening')
            ->set('mountedActions.0.data', $this->opening())->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame(1, app(ClubhouseHours::class)->rules($this->club)->count());
        $page->call('mountAction', 'editOpening', ['id' => 1])->assertSet('mountedActions.0.data.weekdays', [2, 4]);
    }

    public function test_a_portal_trainer_with_additional_role_uses_the_same_component_without_backend_role_escalation(): void
    {
        app(ClubhouseAccess::class)->grant($this->admin, $this->club, 2, ['hours_write' => true, 'menus_write' => false,
            'menus_publish' => false, 'active' => true, 'revision' => 0, 'reason' => 'Synthetic clubhouse role']);
        auth('portal')->setUser($this->trainer);
        $page = Livewire::test(ClubhouseWorkspace::class, ['tenantId' => 71, 'guard' => 'portal']);
        $page->assertSee(trans('clubhouse.add_opening'))->assertDontSee(trans('clubhouse.assign_role'));
        $page->call('selectTab', 'access')->assertForbidden();
        $this->assertSame('trainer', DB::connection('clubunio')->table('clubora.tenant_accounts')->where('account_id', 2)->value('role'));
    }

    public function test_revoked_additional_rights_block_open_forms_and_actor_switches(): void
    {
        app(ClubhouseAccess::class)->grant($this->admin, $this->club, 2, ['hours_write' => true, 'menus_write' => false,
            'menus_publish' => false, 'active' => true, 'revision' => 0, 'reason' => 'Synthetic clubhouse role']);
        auth('portal')->setUser($this->trainer);
        $page = Livewire::test(ClubhouseWorkspace::class, ['tenantId' => 71, 'guard' => 'portal']);
        DB::connection('clubunio')->table('clubora.clubhouse_grants')->update(['active' => false]);
        $page->call('selectTab', 'hours')->assertForbidden();
    }

    public function test_menus_are_escaped_and_both_locales_have_matching_catalogues(): void
    {
        app(ClubhouseMenus::class)->save($this->admin, $this->club, null, $this->menu(['title_de' => '<script>unsafe</script>']));
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(ClubhouseWorkspace::class, ['tenantId' => 71])->call('selectTab', 'menus')
                ->assertSee(trans('clubhouse.add_menu'))->assertDontSee('<script>unsafe</script>', false);
        }
        $this->assertSame(array_keys(require lang_path('de/clubhouse.php')), array_keys(require lang_path('en/clubhouse.php')));
    }

    public function test_menu_and_role_dialogs_persist_with_server_permissions(): void
    {
        $page = Livewire::test(ClubhouseWorkspace::class, ['tenantId' => 71]);
        $page->call('selectTab', 'menus')->call('mountAction', 'newMenu')
            ->set('mountedActions.0.data', $this->menu())->call('callMountedAction')->assertHasNoErrors();
        $id = app(ClubhouseMenus::class)->query($this->club)->value('id');
        $this->assertNotNull($id);
        $page->call('mountAction', 'publish', ['id' => $id])->call('callMountedAction')->assertHasNoErrors();
        $this->assertCount(1, app(ClubhouseMenus::class)->published($this->club, 'web'));
        $page->call('selectTab', 'access')->call('mountAction', 'grant')
            ->set('mountedActions.0.data', ['account_id' => 2, 'hours_write' => true,
                'menus_write' => false, 'menus_publish' => false, 'active' => true, 'revision' => 0,
                'reason' => 'Synthetic dialog role assignment'])->call('callMountedAction')->assertHasNoErrors();
        $this->assertTrue(app(ClubhouseAccess::class)->allows($this->trainer, $this->club, 'hours.write'));
        $page->call('mountAction', 'settings')
            ->set('mountedActions.0.data', ['public_enabled' => true, 'timezone' => 'Europe/Zurich', 'revision' => 1])
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertTrue(app(\App\ClubUnio\Services\ClubhouseService::class)->publicEnabled($this->club));
    }
}
