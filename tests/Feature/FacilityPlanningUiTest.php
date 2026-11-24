<?php

namespace Tests\Feature;

require_once __DIR__.'/PitchPlanningPageTest.php';

use App\ClubUnio\Models\Account;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class FacilityPlanningUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $actor = new Account;
        $actor->id = 801;
        Filament::auth()->setUser($actor);
        config(['clubunio-facility-ui.enabled' => true]);
        PitchPlanningHarness::$calls = [];
        PitchPlanningHarness::$failure = null;
        PitchPlanningHarness::$manage = PitchPlanningHarness::$request = true;
        PitchPlanningHarness::$fixtureEntries = [PitchPlanningPageTest::entry()];
    }

    public function test_modern_and_server_fallback_render_safely_in_both_languages(): void
    {
        foreach (['de' => 'Interaktiver Plan', 'en' => 'Interactive planner'] as $locale => $label) {
            app()->setLocale($locale);
            Livewire::test(FacilityPlanningHarness::class)->assertSee($label)
                ->assertSee('data-cu-facility-planner', false)->assertSee('data-cu-facility-fallback', false)
                ->assertDontSee('<script>unsafe</script>', false);
        }
    }

    public function test_design_switch_preserves_date_and_mode_and_is_scoped_to_account(): void
    {
        Livewire::test(FacilityPlanningHarness::class)->set('mode', 'week')->call('selectPlanningDesign', 'classic')->assertRedirect();
        $this->assertSame('classic', session('clubunio.facility.design.801'));
        Livewire::test(FacilityPlanningHarness::class)->assertDontSee('data-cu-facility-planner', false)->assertSee('pitch-board', false);
        $actor = new Account;
        $actor->id = 802;
        Filament::auth()->setUser($actor);
        $this->assertTrue(app(FacilityPlanningHarness::class)->modernSelected());
    }

    public function test_new_projection_does_not_bypass_existing_request_permissions(): void
    {
        PitchPlanningHarness::$request = PitchPlanningHarness::$manage = false;
        Livewire::test(FacilityPlanningHarness::class)->assertSee('&quot;canRequest&quot;:false', false)
            ->assertSee('&quot;canManage&quot;:false', false)->call('requestSlot', 1, '2026-11-04', '18:00')->assertForbidden();
    }

    public function test_moving_only_opens_existing_confirmation_and_does_not_write(): void
    {
        Livewire::test(FacilityPlanningHarness::class)->call('proposeMove', PitchPlanningPageTest::entry()['key'], 2, '2026-11-04', '20:00')
            ->assertSet('editor', 'edit')->assertSet('form.field_id', 2)->assertSet('form.starts_at', '20:00');
        $this->assertNotContains('move', array_column(PitchPlanningHarness::$calls, 0));
    }

    public function test_invalid_design_and_disabled_gate_are_rejected(): void
    {
        Livewire::test(FacilityPlanningHarness::class)->call('selectPlanningDesign', 'external')->assertForbidden();
        config(['clubunio-facility-ui.enabled' => false]);
        Livewire::test(FacilityPlanningHarness::class)->assertDontSee('data-cu-facility-planner', false)->call('selectPlanningDesign', 'modern')->assertForbidden();
    }

    public function test_missing_assets_preserve_previous_view(): void
    {
        app()->usePublicPath(storage_path('framework/facility-assets-missing'));
        $this->assertFalse(app(FacilityPlanningHarness::class)->modernAvailable());
    }

    public function test_day_week_month_project_correct_dates_and_real_entry_keys(): void
    {
        $component = Livewire::test(FacilityPlanningHarness::class);
        foreach (['day', 'week', 'month'] as $mode) {
            $component->set('mode', $mode)->assertHasNoErrors()->assertSee('data-cu-facility-planner', false);
            $html = $component->html();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $nodes = (new \DOMXPath($dom))->query('//*[@data-cu-facility-planner]');
            $data = json_decode($nodes->item(0)->getAttribute('data-props'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($mode, $data['mode']);
            $this->assertSame('training:7:2026-11-04', collect($data['days'])->flatMap(fn ($d) => $d['events'])->first()['key']);
            $this->assertArrayNotHasKey('revision', collect($data['days'])->flatMap(fn ($d) => $d['events'])->first());
        }
    }
}

class FacilityPlanningHarness extends PitchPlanningHarness
{
    public function render(): \Illuminate\Contracts\View\View
    {
        return view('clubunio.pitch-planning.page', $this->getViewData());
    }
}
