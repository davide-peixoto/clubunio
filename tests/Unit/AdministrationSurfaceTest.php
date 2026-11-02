<?php

namespace Tests\Unit;

use Filament\Tables\Contracts\HasTable;
use PHPUnit\Framework\TestCase;

class AdministrationSurfaceTest extends TestCase
{
    public function test_specialist_lists_implement_the_native_table_contract(): void
    {
        foreach (['SfvCompetitions', 'SponsorRuns', 'SponsorRunBilling', 'Synchronisation',
            'BexioReconciliation', 'BexioDispatchTrials', 'BexioSandbox', 'DocumentSeries', 'ApiIntegrations'] as $page) {
            $this->assertTrue(is_subclass_of('App\\ClubUnio\\Pages\\'.$page, HasTable::class), $page);
        }
        foreach (['TeamPlanningTable', 'TrainingPlansTable', 'BusReservationsTable', 'PlanningAbsencesTable', 'PlanningSfvTable'] as $table) {
            $this->assertTrue(is_subclass_of('App\\Livewire\\ClubUnio\\'.$table, HasTable::class), $table);
        }
    }

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
