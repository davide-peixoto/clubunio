<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Pages\Dashboard;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardDesignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        config(['clubunio-dashboard.enabled' => true]);
        $actor = new Account;
        $actor->id = 701;
        Filament::auth()->setUser($actor);
    }

    public function test_both_languages_render_glass_and_accessible_switch_with_server_fallback(): void
    {
        foreach (['de' => 'Bisheriges Design', 'en' => 'Previous design'] as $locale => $label) {
            app()->setLocale($locale);
            Livewire::test(DashboardDesignHarness::class)->assertSee($label)
                ->assertSee('data-cu-dashboard-hero', false)
                ->assertSee('Synthetic heading')->assertSee('clubunio-dashboard.js');
        }
    }

    public function test_switch_persists_classic_and_can_switch_back(): void
    {
        Livewire::test(DashboardDesignHarness::class)->call('selectDesign', 'classic')->assertRedirect();
        $this->assertSame('classic', session('clubunio.dashboard.design.701'));
        Livewire::test(DashboardDesignHarness::class)->assertDontSee('data-cu-dashboard-hero', false)
            ->assertSee(trans('dashboard_design.glass'));
        Livewire::test(DashboardDesignHarness::class)->call('selectDesign', 'glass')->assertRedirect();
        $this->assertTrue(app(DashboardDesignHarness::class)->glassSelected());
    }

    public function test_preference_is_scoped_to_account(): void
    {
        session()->put('clubunio.dashboard.design.701', 'classic');
        $actor = new Account;
        $actor->id = 702;
        Filament::auth()->setUser($actor);
        $this->assertTrue(app(DashboardDesignHarness::class)->glassSelected());
    }

    public function test_disabled_gate_returns_existing_view_and_rejects_switch(): void
    {
        config(['clubunio-dashboard.enabled' => false]);
        $this->assertSame('filament-panels::pages.page', app(DashboardDesignHarness::class)->getView());
        Livewire::test(DashboardDesignHarness::class)->call('selectDesign', 'glass')->assertForbidden();
    }

    public function test_unknown_design_is_rejected(): void
    {
        Livewire::test(DashboardDesignHarness::class)->call('selectDesign', '../other')->assertForbidden();
    }

    public function test_assets_missing_preserve_classic_dashboard(): void
    {
        app()->usePublicPath(storage_path('framework/testing-dashboard-no-assets'));
        $this->assertFalse(app(DashboardDesignHarness::class)->glassAvailable());
        $this->assertSame('filament-panels::pages.page', app(DashboardDesignHarness::class)->getView());
    }
}

class DashboardDesignHarness extends Dashboard
{
    public function getWidgets(): array { return []; }
    public function getHeading(): string { return 'Synthetic heading'; }
}
