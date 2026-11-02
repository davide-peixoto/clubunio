<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdministrationSurfaceTest extends TestCase
{
    public function test_native_workspace_tables_are_outside_legacy_style_scope(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root.'/resources/views/clubunio/workspace/layout.blade.php');
        $css = file_get_contents($root.'/public/css/clubunio-workspace.css');
        $this->assertStringContainsString("@yield('native-content')", $layout);
        $this->assertStringContainsString('<div class="workspace-content">', $layout);
        $this->assertStringContainsString('.club-workspace .workspace-content {', $css);
        $this->assertStringContainsString('input:not(.fi-input)', $css);
        $this->assertStringContainsString('button:not(.fi-btn):not(.fi-icon-btn)', $css);
        $this->assertStringContainsString('clubunio-workspace.css\') }}?v=3', $layout);
    }
}
