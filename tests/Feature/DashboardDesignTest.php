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
        app()->instance(\App\ClubUnio\Services\ReactUi::class, new class extends \App\ClubUnio\Services\ReactUi { public function enabled(?int $tenantId = null): bool { return true; } });
        $actor = new Account;
        $actor->id = 701;
        Filament::auth()->setUser($actor);
    }

    public function test_both_languages_render_glass_without_separate_design_switch(): void
    {
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(DashboardDesignHarness::class)->assertDontSee('wire:click="selectDesign', false)
                ->assertSee('data-cu-dashboard-hero', false)
                ->assertSee('Synthetic heading')->assertSee('/js/react-ui/dashboard-');
        }
    }

    public function test_glass_has_one_greeting_heading_and_classic_keeps_original_header(): void
    {
        $glass = Livewire::test(DashboardDesignHarness::class)->html();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$glass);
        $this->assertSame(1, $dom->getElementsByTagName('h1')->length);
        $this->assertSame('Synthetic heading', trim($dom->getElementsByTagName('h1')->item(0)->textContent));
        app()->instance(\App\ClubUnio\Services\ReactUi::class, new class extends \App\ClubUnio\Services\ReactUi { public function enabled(?int $tenantId = null): bool { return false; } });
        $this->assertNull(app(DashboardDesignHarness::class)->getHeader());
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
