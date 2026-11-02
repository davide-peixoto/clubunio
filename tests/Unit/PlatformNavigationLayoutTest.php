<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformNavigationLayoutTest extends TestCase
{
    public function test_platform_navigation_has_explicit_icon_dimensions_and_versioned_styles(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/clubunio/tenant-switcher.blade.php');
        $css = file_get_contents($root.'/public/css/clubunio-navigation.css');
        $head = file_get_contents($root.'/resources/views/clubunio/brand/head.blade.php');

        $this->assertStringContainsString('class="cu-platform-link"', $view);
        $this->assertStringContainsString("'cu-platform-link-icon'", $view);
        $this->assertMatchesRegularExpression('/\.cu-platform-link > \.cu-platform-link-icon\s*\{[^}]*width: 20px;[^}]*height: 20px;[^}]*flex: 0 0 20px;/s', $css);
        $this->assertStringContainsString("clubunio-navigation.css') }}?v=6", $head);
        $this->assertStringContainsString('PlatformAdministrationService::class)->allowed($actor)', $view);
    }

    public function test_submenu_disclosure_keeps_links_and_keyboard_buttons_separate(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/vendor/filament-panels/components/sidebar/item.blade.php');
        $script = file_get_contents($root.'/public/js/clubunio-navigation.js');
        $this->assertStringContainsString('aria-controls=', $view);
        $this->assertStringContainsString('x-bind:aria-expanded=', $view);
        $this->assertStringContainsString('type="button" class="cu-submenu-toggle"', $view);
        $this->assertStringContainsString('heroicon-m-chevron-down', $view);
        $this->assertStringContainsString("@include('clubunio-filament-original::components.sidebar.item')", $view);
        $this->assertStringContainsString("new CustomEvent('cu-submenu-search'", $script);
        $this->assertStringContainsString('beforeSearch = null', $view);
    }
}
