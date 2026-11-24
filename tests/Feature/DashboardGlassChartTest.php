<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Widgets\NewMembersMonthly;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardGlassChartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $actor = new Account;
        $actor->id = 901;
        Filament::auth()->setUser($actor);
        app()->instance(\App\ClubUnio\Services\ReactUi::class, new class extends \App\ClubUnio\Services\ReactUi { public function enabled(?int $tenantId = null): bool { return true; } });
    }

    public function test_template_charts_preserve_values_and_offer_escaped_server_table_in_both_languages(): void
    {
        foreach (['de' => 'Daten anzeigen', 'en' => 'Show data'] as $locale => $text) {
            app()->setLocale($locale);
            Livewire::test(GlassChartHarness::class)->assertSee('data-cu-dashboard-chart', false)
                ->assertSee($text)->assertSee('17.00')->assertSee('0.00')
                ->assertDontSee('<script>unsafe</script>', false);
        }
    }

    public function test_classic_selection_uses_original_chart_renderer(): void
    {
        app()->instance(\App\ClubUnio\Services\ReactUi::class, new class extends \App\ClubUnio\Services\ReactUi { public function enabled(?int $tenantId = null): bool { return false; } });
        Livewire::test(GlassChartHarness::class)->assertDontSee('data-cu-dashboard-chart', false)
            ->assertSee('x-data', false);
    }

    public function test_chart_render_rechecks_visibility_before_exposing_data(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DeniedGlassChartHarness::class)->render();
    }
}

class GlassChartHarness extends NewMembersMonthly
{
    public static function canView(): bool { return true; }
    protected function getData(): array
    {
        return ['labels' => ['2026-01', '<script>unsafe</script>'],
            'datasets' => [['label' => 'Synthetic', 'data' => [17, 0]]]];
    }
}

class DeniedGlassChartHarness extends GlassChartHarness
{
    public static function canView(): bool { return false; }
}
