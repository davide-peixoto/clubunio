<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $db->statement("SET LOCAL statement_timeout = '30s'");
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor);
    Filament\Facades\Filament::setTenant($tenant);
    $app['session']->driver()->start();
    $key = 'clubunio.facility.design.'.$actor->id;
    if (! App\ClubUnio\Pages\PitchPlanning::canAccess() || ! app(App\ClubUnio\Pages\PitchPlanning::class)->modernSelected()) {
        throw new LogicException('Modern DEV planner not available');
    }
    $checks = 0;
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach (['day', 'week', 'month'] as $mode) {
            request()->query->set('mode', $mode);
            $html = Livewire\Livewire::mount(App\ClubUnio\Pages\PitchPlanning::class);
            if (! str_contains($html, 'data-cu-facility-planner') || ! str_contains($html, 'data-cu-facility-fallback')
                || ! str_contains($html, e(trans('facility_ui.classic')))) { throw new LogicException('Modern render failed'); }
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $node = (new DOMXPath($dom))->query('//*[@data-cu-facility-planner]')->item(0);
            $data = json_decode($node->getAttribute('data-props'), true, flags: JSON_THROW_ON_ERROR);
            if ($data['mode'] !== $mode || ! $data['fields'] || ! $data['days']) { throw new LogicException('Calendar projection failed'); }
            $checks++;
        }
        session()->put($key, 'classic');
        request()->query->set('mode', 'day');
        $classic = Livewire\Livewire::mount(App\ClubUnio\Pages\PitchPlanning::class);
        if (str_contains($classic, 'data-cu-facility-planner') || ! str_contains($classic, 'pitch-board')) { throw new LogicException('Classic fallback failed'); }
        session()->forget($key);
        $checks++;
    }
    config(['clubunio-facility-ui.enabled' => false]);
    if (app(App\ClubUnio\Pages\PitchPlanning::class)->modernAvailable()) { throw new LogicException('Gate failed'); }
    echo $checks." read-only DEV facility renders passed: day/week/month, DE/EN, classic fallback and gate.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Facility UI acceptance failed: '.$error->getMessage().PHP_EOL);
    exit(1);
} finally { $db->rollBack(); }
