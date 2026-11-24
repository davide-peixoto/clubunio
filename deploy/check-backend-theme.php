<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
try {
    if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
    $db->beginTransaction(); $db->statement("SET LOCAL statement_timeout = '30s'");
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    if (! $actor->hasUnrestrictedBackofficeAccess($tenant)) { throw new LogicException('Administrator required'); }
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor); Filament\Facades\Filament::setTenant($tenant);
    $session = $app['session']->driver(); $session->start();
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
    $color = App\ClubUnio\Models\Setting::where('tenant_id', $tenant->id)->where('area', 'signage')->where('key', 'SIGNAGE_BRAND_COLOR')->first() ?? new App\ClubUnio\Models\Setting;
    $checks = 0;
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach (['#cf1032', '#228844', '#ffff00'] as $hex) {
            $color->forceFill(['tenant_id' => $tenant->id, 'area' => 'signage', 'key' => 'SIGNAGE_BRAND_COLOR', 'label' => 'Brand',
                'value_type' => 'string', 'enabled' => true, 'is_secret' => false,
                'encrypted_value' => Illuminate\Support\Facades\Crypt::encryptString($hex)])->save();
            $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/planning/calendar');
            $request->setUserResolver(fn () => $actor); $request->setLaravelSession($session);
            $request->attributes->set('clubunioWorkspace', true);
            $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/planning/calendar', fn () => null))->name('clubunio.workspace.planning.calendar'));
            $app->instance('request', $request);
            app(App\ClubUnio\Services\ActiveTenant::class)->select($request, $tenant);
            Filament\Facades\Filament::bootCurrentPanel();
            $brand = app(App\ClubUnio\Services\BackendTheme::class)->brand();
            if (Filament\Facades\Filament::getPanel('clubunio')->getBrandLogo() !== $brand['logo']
                || Filament\Facades\Filament::getPanel('clubunio')->getBrandName() !== $tenant->name) { throw new LogicException('Selected tenant logo/name mismatch'); }
            $html = app(App\ClubUnio\Http\TeamPlanningController::class)->calendar($request, $tenant)->getContent();
            if (! str_contains($html, '--cu-brand-color: '.$hex) || ! str_contains($html, 'clubunio-backend-theme.css')) { throw new LogicException('Branded calendar failed: '.$locale.'/'.$hex); }
            $checks++;
        }
        foreach ([App\ClubUnio\Resources\MemberResource\Pages\ListMembers::class,
            App\ClubUnio\Resources\InvoiceResource\Pages\ListInvoices::class,
            App\ClubUnio\Resources\ExpenseResource\Pages\ListExpenses::class,
            App\ClubUnio\Resources\SettingResource\Pages\ListSettings::class] as $page) {
            $html = Livewire\Livewire::mount($page);
            if (! str_contains($html, 'fi-ta')) { throw new LogicException('Native table failed: '.$page); }
            $checks++;
        }
    }
    echo $checks." DEV renders passed: DE/EN, three brand palettes, members/invoices/expenses/settings. Temporary design values rolled back.\n";
} catch (Throwable $error) { fwrite(STDERR, 'Backend theme acceptance failed: '.$error->getMessage().PHP_EOL); exit(1); }
finally { if ($db->transactionLevel()) { $db->rollBack(); } }
