<?php

use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Console\Kernel;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->routesAreCached()) {
        throw new RuntimeException('Cached administration routes required');
    }
    $pages = ['SfvCompetitions', 'SponsorRuns', 'SponsorRunBilling', 'Synchronisation',
        'BexioReconciliation', 'BexioDispatchTrials', 'BexioSandbox', 'DocumentSeries', 'ApiIntegrations'];
    foreach ($pages as $page) {
        if (! is_subclass_of('App\\ClubUnio\\Pages\\'.$page, HasTable::class)) {
            throw new RuntimeException('Native page table missing: '.$page);
        }
    }
    $components = ['TeamPlanningTable', 'TrainingPlansTable', 'BusReservationsTable', 'PlanningAbsencesTable', 'PlanningSfvTable'];
    foreach ($components as $component) {
        if (! is_subclass_of('App\\Livewire\\ClubUnio\\'.$component, HasTable::class)) {
            throw new RuntimeException('Native workspace table missing: '.$component);
        }
    }
    if (! str_contains(file_get_contents(resource_path('views/clubunio/workspace/layout.blade.php')), "@yield('native-content')")
        || ! is_file(public_path('css/clubunio-administration.css'))) {
        throw new RuntimeException('Administration presentation assets missing');
    }
    echo json_encode(['native_administration_pages' => count($pages), 'native_workspace_tables' => count($components),
        'business_migration' => false, 'cached_routes' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Administration release check failed: '.$error::class.' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
}
