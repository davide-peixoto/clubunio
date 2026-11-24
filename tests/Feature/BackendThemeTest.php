<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackendTheme;
use App\ClubUnio\Services\ReactUi;
use Filament\Facades\Filament;
use Tests\TestCase;

class BackendThemeTest extends TestCase
{
    public function test_extreme_brand_colors_keep_readable_text_and_navigation(): void
    {
        $theme = new BackendTheme;
        foreach (['#ffffff', '#000000', '#ffff00', '#888888', '#22aa44', '#cf1032', '#164b8a', '#9933ee', '#ff8800'] as $color) {
            $tokens = $theme->tokens($color);
            $this->assertSame($color, $tokens['--cu-brand-color']);
            $this->assertGreaterThanOrEqual(4.5, BackendTheme::contrast($color, $tokens['--cu-brand-ink']));
            $this->assertGreaterThanOrEqual(4.5, BackendTheme::contrast($tokens['--cu-brand-hero'], $tokens['--cu-brand-ink']));
            $this->assertGreaterThanOrEqual(4.5, BackendTheme::contrast($tokens['--cu-brand-text'], '#ffffff'));
            $this->assertGreaterThanOrEqual(4.5, BackendTheme::contrast($tokens['--cu-brand-light'], '#152236'));
            $this->assertGreaterThanOrEqual(5, BackendTheme::contrast($tokens['--cu-nav-top'], '#ffffff'));
            $this->assertGreaterThanOrEqual(5, BackendTheme::contrast($tokens['--cu-nav-bottom'], '#ffffff'));
            $this->assertGreaterThanOrEqual(4.5, BackendTheme::contrast($tokens['--cu-nav-symbol'], $tokens['--cu-nav-marker']));
            $this->assertGreaterThanOrEqual(4.5, BackendTheme::contrast($tokens['--cu-brand-light'], $tokens['--cu-brand-dark-soft']));
        }
    }

    public function test_invalid_color_cannot_inject_css(): void
    {
        $tokens = (new BackendTheme)->tokens('#fff; } body { display:none');
        $this->assertSame('#164b8a', $tokens['--cu-brand-color']);
        foreach ($tokens as $value) { $this->assertStringNotContainsString(';', $value); }
    }

    public function test_selected_tenant_brand_is_cached_separately_and_disabled_mode_keeps_original_logo(): void
    {
        $mode = new class extends ReactUi { public bool $value = true; public function enabled(?int $tenantId = null): bool { return $this->value; } };
        app()->instance(ReactUi::class, $mode);
        $theme = new class extends BackendTheme {
            public array $reads = [];
            protected function designFor(Tenant $tenant): array { $this->reads[] = $tenant->id; return ['color' => $tenant->id === 1 ? '#cf1032' : '#22aa44', 'logo' => '/images/club-'.$tenant->id.'.png']; }
        };
        $one = new Tenant; $one->setRawAttributes(['id' => 1, 'name' => 'One']);
        $two = new Tenant; $two->setRawAttributes(['id' => 2, 'name' => 'Two']);
        $this->assertSame('#cf1032', $theme->brand($one)['color']);
        $this->assertSame('#22aa44', $theme->brand($two)['color']);
        $this->assertSame('#cf1032', $theme->brand($one)['color']);
        $this->assertSame([1, 2], $theme->reads);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $actor = new \App\ClubUnio\Models\Account;
        $actor->id = 91;
        Filament::auth()->setUser($actor);
        Filament::setTenant($two);
        app()->instance(BackendTheme::class, $theme);
        $this->assertStringEndsWith('/images/club-2.png', $theme->logo());
        $this->assertSame('/images/club-2.png', Filament::getPanel('clubunio')->getBrandLogo());
        $this->assertSame('Two', Filament::getPanel('clubunio')->getBrandName());
        $mode->value = false;
        $this->assertNull($theme->brand($two));
        $this->assertStringEndsWith('/brand/blue-v1/clubunio-logo-horizontal.svg', $theme->logo());
        Filament::setTenant(null);
    }

    public function test_existing_ui_packages_use_shared_color_tokens(): void
    {
        foreach (['dashboard', 'facility-planner', 'team-planning', 'react-navigation'] as $file) {
            $this->assertStringContainsString('var(--cu-brand-', file_get_contents(public_path('css/clubunio-'.$file.'.css')));
        }
    }
}
