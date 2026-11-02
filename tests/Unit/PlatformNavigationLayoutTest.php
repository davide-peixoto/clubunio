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
        $this->assertStringContainsString("clubunio-navigation.css') }}?v=5", $head);
        $this->assertStringContainsString('PlatformAdministrationService::class)->allowed($actor)', $view);
    }
}
