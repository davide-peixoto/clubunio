<?php

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{VeoRecordingPlan, VeoRecordingAdjustments};
use Illuminate\Support\Facades\{DB, Http, Mail, Queue};
use Filament\Facades\Filament;

$db = DB::connection('clubunio');
$target = $argv[1] ?? 'dev';
$targets = ['dev' => ['https://dev.clubunio.ch', 'clubunio_dev'],
    'stage' => ['https://stage.clubunio.com', 'clubunio_stage'], 'prod' => ['https://app.clubunio.com', 'clubunio_prod']];
if (! isset($targets[$target]) || [config('app.url'), $db->getDatabaseName()] !== $targets[$target]) {
    throw new LogicException('Unexpected Veo acceptance target');
}
$origin = config('app.url');
Http::preventStrayRequests(); Mail::fake(); Queue::fake();
config(['clubunio-mobile-push.enabled' => false, 'clubunio-mobile-push.delivery_enabled' => false]);
$db->beginTransaction();
try {
    $db->statement("SET LOCAL statement_timeout='30s'");
    $actor = Account::where('active', true)->where('api_only', false)
        ->whereHas('tenants', fn ($query) => $query->whereIn('clubora.tenant_accounts.role', ['admin', 'office']))->orderBy('id')->firstOrFail();
    $tenant = Tenant::create(['slug' => 'veo-test-'.bin2hex(random_bytes(6)), 'name' => 'Veo rollback acceptance']);
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $insert = fn ($table, $data) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $data);
    $season = $insert('seasons', ['name' => 'Acceptance', 'starts_on' => '2029-07-01', 'ends_on' => '2030-06-30', 'active' => true]);
    $team = $insert('teams', ['name' => 'Acceptance team', 'sfv_team_id' => 987651001, 'active' => true]);
    $teamSeason = $insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Acceptance team', 'active' => true]);
    App\ClubUnio\Services\TeamPlanningService::seedOptions($tenant->id);
    $field = $insert('training_options', ['category' => 'field', 'code' => 'H', 'name' => 'Acceptance field', 'active' => true]);
    $field2 = $insert('training_options', ['category' => 'field', 'code' => 'K', 'name' => 'Second field', 'active' => true]);
    $status = $insert('facility_statuses', ['code' => 'VEO_OPEN', 'name' => 'Open', 'blocks_training' => false, 'active' => true]);
    foreach ([$field, $field2] as $id) { $insert('field_availabilities', ['field_id' => $id, 'status_id' => $status, 'active' => true]); }
    $insert('sfv_schedule', ['match_id' => 987651002, 'season_id' => 2030, 'team_a_id' => 987651001,
        'host_name' => 'Acceptance team', 'guest_name' => 'Other team', 'location' => 'Tannenbrunn - 1, Sissach',
        'title' => 'Acceptance recording', 'starts_at' => '2030-01-10T14:00:00+01:00', 'state' => 'Angesetzt', 'home' => true]);
    $competition = $insert('competitions', ['association_match_id' => 987651002, 'plays_on' => '2030-01-10',
        'starts_at' => '14:00:00', 'field_id' => $field, 'host_name' => 'Acceptance team', 'guest_name' => 'Other team', 'active' => true]);
    $plan = app(VeoRecordingPlan::class); $service = app(VeoRecordingAdjustments::class);
    $plan->saveCamera($tenant, $field, 'Acceptance camera'); $plan->setTeamEnabled($tenant, $teamSeason, true);
    $entry = $service->current($tenant, 987651002);
    $input = ['revision' => $entry['revision'], 'field_id' => $field, 'date' => '2030-01-10', 'time' => '13:30', 'duration' => 150];
    $service->save($actor, $tenant, 987651002, $input);
    if ($service->current($tenant, 987651002)['starts_at']->format('H:i') !== '13:30') { throw new LogicException('Move missing'); }
    try { $service->save($actor, $tenant, 987651002, $input); throw new LogicException('Stale move accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 409) { throw $e; } }
    $entry = $service->current($tenant, 987651002);
    $service->save($actor, $tenant, 987651002, ['revision' => $entry['revision']], true);
    if ($service->current($tenant, 987651002)['adjusted']) { throw new LogicException('Reset failed'); }
    if ($db->table('clubora.competitions')->where('id', $competition)->value('starts_at') !== '14:00:00') { throw new LogicException('Fixture changed'); }
    if ($db->table('clubora.object_changes')->where('tenant_id', $tenant->id)->where('object_type', 'veo_recording')->count() !== 2) { throw new LogicException('Audit missing'); }
    echo "PostgreSQL move, stale-write protection, reset, fixture isolation and audit passed\n";
    $plan->saveCamera($tenant, $field2, 'Second camera');
    $input['revision'] = $service->current($tenant, 987651002)['revision'];
    $service->save($actor, $tenant, 987651002, $input);
    $db->table('clubora.competitions')->where('tenant_id', $tenant->id)->where('id', $competition)->update(['field_id' => $field2]);
    if ($service->current($tenant, 987651002)['camera'] !== 'Second camera') { throw new LogicException('Confirmed pitch move did not change camera'); }
    foreach (['2030-01-11' => ['16:00', 2, 'Second camera'], '2030-01-12' => ['19:00', 1, 'Acceptance camera']] as $date => [$time, $pitch, $camera]) {
        $db->table('clubora.sfv_schedule')->where('tenant_id', $tenant->id)->where('match_id', 987651002)->update([
            'starts_at' => $date.'T'.$time.':00+01:00', 'location' => 'Tannenbrunn - '.$pitch.', Sissach', 'state' => 'Neu angesetzt', 'revision' => $pitch + 2]);
        $result = app(App\ClubUnio\Services\SfvReconcileService::class)->reconcile($actor, $tenant, $date, $date);
        if ($result['issues'] || $result['new'] + $result['updated'] !== 1) { throw new LogicException('SFV reconciliation failed: '.json_encode($result['issues'])); }
        $entry = $service->current($tenant, 987651002);
        $expected = Carbon\CarbonImmutable::parse($date.' '.$time, 'Europe/Zurich')->subMinutes(30);
        if ($entry['camera'] !== $camera || ! $entry['starts_at']->equalTo($expected) || ! $entry['ends_at']->equalTo($expected->addMinutes(150))) {
            throw new LogicException('SFV recording follow failed');
        }
        if ($plan->entries($tenant, '2030-01-10', '2030-01-10')) { throw new LogicException('Old recording remained on calendar'); }
    }
    echo "Confirmed pitch change and two actual SFV reconciliations followed automatically, preserving lead-in and duration\n";
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));
    Filament::auth()->setUser($actor); Filament::setTenant($tenant, isQuiet: true);
    $session = $app['session']->driver(); $session->start();
    $request = Illuminate\Http\Request::create($origin.'/veo-planning', 'GET');
    $request->setUserResolver(fn () => $actor); $request->setLaravelSession($session);
    $app->instance('request', $request);
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
    foreach (['de', 'en'] as $locale) {
        $app->setLocale($locale);
        $html = Livewire\Livewire::mount(App\ClubUnio\Pages\VeoPlanning::class);
        if (! str_contains($html, 'data-cu-facility-planner') || str_contains($html, 'veo_calendar.')) { throw new LogicException('Calendar render failed'); }
    }
    echo "Native DE/EN page rendering passed\n";
} finally {
    $db->rollBack();
}
echo "VEO_CALENDAR_ACCEPTANCE_OK; all fixtures rolled back\n";
