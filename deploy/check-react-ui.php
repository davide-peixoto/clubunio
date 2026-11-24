<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
try {
    if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
    $db->beginTransaction();
    $db->statement("SET LOCAL statement_timeout = '30s'");
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor);
    Filament\Facades\Filament::setTenant($tenant);
    $session = $app['session']->driver(); $session->start();
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
    $row = App\ClubUnio\Models\Setting::where('tenant_id', $tenant->id)->where('area', 'general')->where('key', App\ClubUnio\Services\ReactUi::KEY)->sole();
    $checks = 0;
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach (['0', '1'] as $value) {
            app(App\ClubUnio\Services\SettingsService::class)->update($actor, $tenant, $row->id, $value);
            foreach (['month', 'week', 'teams'] as $mode) {
                $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/planning/calendar', 'GET', ['view' => $mode, 'date' => '2026-11-24']);
                $request->setUserResolver(fn () => $actor); $request->setLaravelSession($session);
                $request->attributes->set('clubunioWorkspace', true);
                $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/planning/calendar', fn () => null))->name('clubunio.workspace.planning.calendar'));
                $app->instance('request', $request);
                app(App\ClubUnio\Services\ActiveTenant::class)->select($request, $tenant);
                Filament\Facades\Filament::bootCurrentPanel();
                $enabled = $value === '1';
                if (app(App\ClubUnio\Pages\Dashboard::class)->glassSelected() !== $enabled
                    || app(App\ClubUnio\Pages\PitchPlanning::class)->modernSelected() !== $enabled) { throw new LogicException('Shared parameter mismatch'); }
                $html = app(App\ClubUnio\Http\TeamPlanningController::class)->calendar($request, $tenant)->getContent();
                if (str_contains($html, 'class="cu-team-hero"') !== $enabled
                    || str_contains($html, 'data-cu-react-navigation') !== $enabled
                    || str_contains($html, 'planning_design=')) { throw new LogicException('Calendar/navigation parameter mismatch: '.$locale.'/'.$mode.'/'.$value.' hero='.(int)str_contains($html, 'class="cu-team-hero"').' nav='.(int)str_contains($html, 'data-cu-react-navigation').' switch='.(int)str_contains($html, 'planning_design=').' views='.implode(',', config('view.paths'))); }
                $checks++;
            }
            foreach (['day', 'week', 'month'] as $mode) {
                request()->query->set('mode', $mode);
                $html = Livewire\Livewire::mount(App\ClubUnio\Pages\PitchPlanning::class);
                if (str_contains($html, 'data-cu-facility-planner') !== ($value === '1') || str_contains($html, 'selectPlanningDesign')) { throw new LogicException('Facility parameter mismatch'); }
                $checks++;
            }
        }
    }
    echo $checks." DEV renders passed: central Yes/No, DE/EN, menu/calendar/facility; settings and audit writes rolled back.\n";
} catch (Throwable $error) { fwrite(STDERR, 'React UI acceptance failed: '.$error->getMessage().PHP_EOL); exit(1); }
finally { if ($db->transactionLevel()) { $db->rollBack(); } }
