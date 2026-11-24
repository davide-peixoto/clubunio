<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
try {
    if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
    $db->beginTransaction();
    $db->statement('SET TRANSACTION READ ONLY');
    $db->statement("SET LOCAL statement_timeout = '30s'");
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor);
    Filament\Facades\Filament::setTenant($tenant);
    $session = $app['session']->driver();
    $session->start();
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
    $checks = 0;
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach (['month', 'week', 'teams'] as $mode) {
            $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/planning/calendar', 'GET', ['view' => $mode, 'date' => '2026-11-24']);
            $request->setUserResolver(fn () => $actor);
            $request->setLaravelSession($session);
            $request->attributes->set('clubunioWorkspace', true);
            $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/planning/calendar', fn () => null))->name('clubunio.workspace.planning.calendar'));
            $app->instance('request', $request);
            app(App\ClubUnio\Services\ActiveTenant::class)->select($request, $tenant);
            $key = App\ClubUnio\Services\PlanningUi::key($request, $tenant->id);
            foreach (['modern', 'classic'] as $design) {
                $session->put($key, $design);
                $html = app(App\ClubUnio\Http\TeamPlanningController::class)->calendar($request, $tenant)->getContent();
                if (str_contains($html, 'class="cu-team-hero"') !== ($design === 'modern') || !str_contains($html, trans('planning_ui.classic'))) {
                    throw new LogicException('Calendar render failed: '.$locale.'/'.$mode.'/'.$design);
                }
                $checks++;
            }
        }
    }
    echo $checks." read-only DEV calendar renders passed: DE/EN, month/week/teams, modern/classic.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Team planning UI acceptance failed: '.$error->getMessage().PHP_EOL);
    exit(1);
} finally {
    if ($db->transactionLevel()) { $db->rollBack(); }
}
