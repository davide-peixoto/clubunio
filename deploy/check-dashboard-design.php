<?php

// Read-only DEV acceptance: existing account/tenant; no stored preferences or database writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor);
    Filament\Facades\Filament::setTenant($tenant);
    $app['session']->driver()->start();
    $page = app(App\ClubUnio\Pages\Dashboard::class);
    if (! $page->glassSelected()) { throw new LogicException('Glass unavailable'); }
    $widgets = $page->getWidgets();
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        $html = Livewire\Livewire::mount(App\ClubUnio\Pages\Dashboard::class);
        if (! str_contains($html, 'data-cu-dashboard-hero') || ! str_contains($html, e(trans('dashboard_design.classic')))) {
            throw new LogicException('Glass render failed');
        }
        $app['session']->put('clubunio.dashboard.design.'.$actor->id, 'classic');
        $classic = Livewire\Livewire::mount(App\ClubUnio\Pages\Dashboard::class);
        if (str_contains($classic, 'data-cu-dashboard-hero') || ! str_contains($classic, e(trans('dashboard_design.glass')))) {
            throw new LogicException('Classic fallback failed');
        }
        if ($page->getWidgets() !== $widgets) { throw new LogicException('Widgets changed'); }
        $app['session']->forget('clubunio.dashboard.design.'.$actor->id);
    }
    config(['clubunio-dashboard.enabled' => false]);
    if ($page->getView() !== 'filament-panels::pages.page') { throw new LogicException('Global rollback failed'); }
    echo "DEV glass/classic DE/EN rendered; same widgets; global fallback verified; read-only.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Dashboard acceptance failed: '.$error->getMessage().PHP_EOL);
    exit(1);
} finally {
    $db->rollBack();
}
