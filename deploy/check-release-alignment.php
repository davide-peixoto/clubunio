<?php

if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['stage', 'prod'], true)) {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\ClubUnio\Models\{Account, Invoice, Tenant};
use App\ClubUnio\Services\{ActiveTenant, DashboardReservationRequests, DashboardService, ReactAssets, WorkNotificationHub};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB, Http, Mail, Queue};

$env = $argv[1];
$origin = $env === 'stage' ? 'https://stage.clubunio.com' : 'https://app.clubunio.com';
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'clubunio_'.$env || config('app.url') !== $origin) {
    throw new LogicException('Unexpected alignment target');
}
Http::preventStrayRequests();
Mail::fake();
Queue::fake();
$checks = 0;
$expect = function (bool $ok, string $case) use (&$checks) {
    if (! $ok) { throw new LogicException($case); }
    $checks++;
};
$db->beginTransaction();
try {
    $db->statement("SET LOCAL statement_timeout='30s'");
    $db->statement("SET LOCAL lock_timeout='5s'");
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = Tenant::where('slug', 'sv-sissach')->sole();
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));
    Filament::auth()->setUser($actor);
    Filament::setTenant($tenant, isQuiet: true);
    config(['session.driver' => 'array']);
    $session = app('session')->driver();
    $session->start();
    $request = Illuminate\Http\Request::create($origin.'/');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $actor);
    $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/', fn () => null))->name('filament.clubunio.pages.dashboard'));
    $app->instance('request', $request);
    app(ActiveTenant::class)->select($request, $tenant);
    Filament::bootCurrentPanel();
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);

    $service = app(DashboardService::class);
    $native = $service->scopeOpenInvoices(Invoice::where('tenant_id', $tenant->id)->where('active', true), $tenant);
    $reference = $db->query()->fromSub($native->toBase(), 'native_totals')->selectRaw('count(*) as total,COALESCE(sum(open_amount),0) as amount')->first();
    $summary = $service->openSummary($actor, $tenant);
    $expect((int) $reference->total === $summary['count'] && abs((float) $reference->amount - (float) $summary['amount']) < .000001, 'Invoice aggregate differs from native list');
    $hub = app(WorkNotificationHub::class);
    $expect($hub->unreadCount($actor, $tenant) === $hub->page($actor, $tenant, 1, true)['count'], 'Unread badge differs from authorized page');
    $reservations = app(DashboardReservationRequests::class);
    $counts = $reservations->counts($actor, $tenant);
    foreach ($reservations->pending($actor, $tenant) as $kind => $group) {
        $expect($counts[$kind] === $group['count'], 'Reservation counter differs');
    }
    foreach (['navigation', 'dashboard', 'facility'] as $entry) {
        $expect(app(ReactAssets::class)->entry($entry) !== null, 'Missing React entry '.$entry);
    }
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach (['navigation', 'dashboard', 'facility'] as $entry) {
            $html = view('clubunio.react-script', ['entry' => $entry, 'legacy' => '/js/unused.js'])->render();
            $expect(str_contains($html, 'type="module"') && ! str_contains($html, '/js/unused.js'), 'React render fallback unexpectedly used');
        }
        $expect(! str_contains(trans('assistant_chat.daily_question'), 'assistant_chat.'), 'Missing assistant translation');
        $expect(str_contains(Livewire\Livewire::mount(App\ClubUnio\Pages\Dashboard::class), 'wire:'), 'Dashboard failed to render');
        $expect(str_contains(Livewire\Livewire::mount(App\ClubUnio\Pages\BackofficeAssistant::class), 'wire:'), 'Assistant failed to render');
    }
    echo json_encode(['environment' => $env, 'checks' => $checks, 'rolled_back' => true, 'outbound_disabled' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->rollBack();
}
