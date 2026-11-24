<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
class ModuleCampaignFixture extends App\ClubUnio\Pages\MemberCampaigns { public function mount(int $fixture): void { $this->selected = $fixture; } }
$db = Illuminate\Support\Facades\DB::connection('clubunio');
try {
    if (config('app.url') !== 'https://dev.clubunio.ch') { throw new LogicException('DEV required'); }
    $db->beginTransaction(); $db->statement("SET LOCAL statement_timeout = '30s'");
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
    if (! $actor->hasUnrestrictedBackofficeAccess($tenant)) { throw new LogicException('Administrator required'); }
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('clubunio'));
    Filament\Facades\Filament::auth()->setUser($actor); Filament\Facades\Filament::setTenant($tenant);
    $session = $app['session']->driver(); $session->start();
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
    $mode = new class extends App\ClubUnio\Services\ReactUi {
        public bool $value = true;
        public function enabled(?int $tenantId = null): bool { return $this->value; }
    };
    $app->instance(App\ClubUnio\Services\ReactUi::class, $mode);
    $fixtures = in_array('--fixtures', $argv, true);
    if ($fixtures) {
        $campaignId = $db->table('clubunio.campaigns')->insertGetId(['tenant_id' => $tenant->id, 'title' => 'Module acceptance fixture', 'channel' => 'email', 'created_by' => $actor->id, 'workflow_version' => 2]);
        $team = $db->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)->value('id');
        $planId = $db->table('clubunio.training_plans')->insertGetId(['tenant_id' => $tenant->id, 'owner_id' => $actor->id, 'team_season_id' => $team,
            'title' => 'Module acceptance fixture', 'input_json' => json_encode(['durationMinutes' => 60, 'playerCount' => 16, 'teamName' => 'Fixture']),
            'plan_json' => json_encode(['title' => 'Fixture', 'focus' => 'Fixture', 'principles' => [], 'qualityChecks' => [], 'exercises' => []])]);
    }
    $checks = 0;
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        foreach ([true, false] as $modern) {
            $mode->value = $modern;
            $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/planning/sv-sissach/training-plans');
            $request->setUserResolver(fn () => $actor); $request->setLaravelSession($session);
            $request->attributes->set('clubunioWorkspace', true);
            $request->setRouteResolver(fn () => (new Illuminate\Routing\Route('GET', '/planning/{tenant}/training-plans', fn () => null))->name('clubunio.workspace.training.index'));
            $app->instance('request', $request);
            app(App\ClubUnio\Services\ActiveTenant::class)->select($request, $tenant);
            Filament\Facades\Filament::bootCurrentPanel();
            if (! $fixtures) {
            foreach ([
                App\ClubUnio\Pages\SeasonPlanning::class => 'cu-season-overview',
                App\ClubUnio\Pages\WorkInbox::class => 'cu-module-stats',
                App\ClubUnio\Pages\Synchronisation::class => 'cu-module-stats',
                App\ClubUnio\Pages\SocialStudio::class => 'clubunio-social-workspace.js',
                App\ClubUnio\Pages\Chats::class => 'data-chat-overview',
                App\ClubUnio\Pages\MemberCampaigns::class => null,
                App\ClubUnio\Resources\InvoiceResource\Pages\ListInvoices::class => 'cu-module-header',
                App\ClubUnio\Resources\ExpenseResource\Pages\ListExpenses::class => 'cu-module-header',
            ] as $page => $marker) {
                $html = Livewire\Livewire::mount($page);
                if ($marker && str_contains($html, $marker) !== $modern) { throw new LogicException('Mode mismatch: '.$page.'/'.$locale.'/'.$modern); }
                if (preg_match('/module_ui\.[a-z_]+/', $html)) { throw new LogicException('Missing translation: '.$page); }
                $checks++;
            }
            foreach ([
                App\ClubUnio\Resources\MemberResource::class,
                App\ClubUnio\Resources\InvoiceResource::class,
                App\ClubUnio\Resources\ExpenseResource::class,
            ] as $resource) {
                $record = $resource::getEloquentQuery()->first();
                if (! $record) { throw new LogicException('Record fixture unavailable: '.$resource); }
                $page = $resource.'\\Pages\\View'.class_basename($resource::getModel());
                $html = Livewire\Livewire::mount($page, ['record' => (string) $record->id]);
                if (str_contains($html, 'cu-module-header') !== $modern) { throw new LogicException('Dossier mode mismatch: '.$page); }
                $checks++;
            }
            }
            $html = app(App\ClubUnio\Http\TrainingPlanController::class)->index($request, $tenant)->getContent();
            if (! str_contains($html, 'fi-ta')) { throw new LogicException('Training table failed'); }
            $checks++;
            if ($fixtures) {
                if (! str_contains($html, 'Module acceptance fixture')) { throw new LogicException('Training card fixture missing'); }
                $html = Livewire\Livewire::mount(ModuleCampaignFixture::class, ['fixture' => $campaignId]);
                if (str_contains($html, 'cu-campaign-stage') !== $modern) { throw new LogicException('Campaign phase failed'); }
                $checks++;
                $html = app(App\ClubUnio\Http\TrainingPlanController::class)->show($request, $tenant, $planId)->getContent();
                if (str_contains($html, 'cu-exercise-deck') !== $modern) { throw new LogicException('Exercise deck mode failed'); }
                $checks++;
            }
        }
    }
    echo $checks.($fixtures ? " fixture DEV renders passed: training cards/details and campaign phase" : " DEV renders passed: six module packages and dossiers").", DE/EN, React on/off. No external requests; transaction rolled back.\n";
} catch (Throwable $error) { fwrite(STDERR, 'Module acceptance failed: '.$error->getMessage().PHP_EOL); exit(1); }
finally { if ($db->transactionLevel()) { $db->rollBack(); } }
