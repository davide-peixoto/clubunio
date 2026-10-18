<?php

use App\ClubUnio\Http\TrainingPlanController;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TrainingPlanService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
Http::preventStrayRequests();
Mail::fake();
$db->beginTransaction();
try {
    $tenant = Tenant::create(['slug' => 'training-perf-'.Str::random(16), 'name' => 'Synthetic']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
    $season = $insert('seasons', ['name' => 'Synthetic season']);
    $team = $insert('teams', ['name' => 'Synthetic team']);
    $ts = $insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic']);
    $input = ['team_season_id' => $ts, 'teamName' => 'Synthetic', 'durationMinutes' => 60, 'playerCount' => 18, 'goal' => 'Passen', 'fieldSize' => 'Halbfeld', 'material' => 'Ball'];
    $plan = ['title' => 'Synthetic training', 'focus' => 'Passen', 'principles' => ['SV Sissach: Spielfreude', 'SFV: Ballkontakte'], 'qualityChecks' => ['SV Sissach und SFV'],
        'exercises' => array_map(fn ($phase) => ['phase' => $phase, 'title' => 'Passspiel', 'durationMinutes' => 20, 'setup' => 'Zwei Gruppen', 'flow' => 'Passen und freilaufen',
            'coachingPoints' => ['Ballkontrolle'], 'easier' => 'Mehr Platz', 'harder' => 'Weniger Zeit', 'diagramDescription' => 'Zwei Zonen',
            'markers' => [['kind' => 'player', 'x' => 25, 'y' => 50], ['kind' => 'cone', 'x' => 75, 'y' => 50]]], ['Einstieg', 'Hauptteil', 'Abschluss'])];
    for ($i = 0; $i < 25; $i++) {
        $id = $insert('training_plans', ['owner_id' => $actor->id, 'team_season_id' => $ts, 'title' => $plan['title'], 'input_json' => json_encode($input), 'plan_json' => json_encode($plan)]);
    }
    Auth::guard('clubunio')->setUser($actor);
    $request = Request::create('/planning/'.$tenant->slug.'/training-plans');
    $request->setUserResolver(fn () => $actor);
    $request->setLaravelSession(app('session.store'));
    $app->instance('request', $request);
    view()->share('errors', new ViewErrorBag);
    $measure = function (string $scenario, callable $work) use ($db) {
        $db->enableQueryLog();
        $db->flushQueryLog();
        $start = microtime(true);
        $result = $work();
        $queries = $db->getQueryLog();
        echo json_encode(['scenario' => $scenario, 'elapsed_ms' => round((microtime(true) - $start) * 1000),
            'queries' => count($queries), 'query_ms' => round(array_sum(array_column($queries, 'time'))), 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        $db->disableQueryLog();

        return $result;
    };
    $controller = app(TrainingPlanController::class);
    $response = $measure('list-twenty-of-twenty-five', fn () => $controller->index($request, $tenant));
    $rows = $response->getOriginalContent()->getData()['plans'];
    abort_unless($rows->total() === 25 && $rows->count() === 20, 409);
    echo json_encode(['scenario' => 'list-payload', 'row_bytes' => strlen(json_encode($rows->items())), 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    $measure('plan-read', fn () => app(TrainingPlanService::class)->get($actor, $tenant, $id));
    $pdf = $measure('pdf-with-authorized-read', fn () => $controller->pdf($request, $tenant, $id));
    abort_unless(str_starts_with($pdf->getContent(), '%PDF-') && $pdf->headers->get('Cache-Control') === 'no-store, private', 409);
    echo json_encode(['scenario' => 'pdf-payload', 'bytes' => strlen($pdf->getContent()), 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    $copy = $measure('private-copy-no-provider', fn () => app(TrainingPlanService::class)->copy($actor, $tenant, $id, 1, (string) Str::uuid()));
    abort_unless($copy !== $id, 409);
    abort_unless(Http::recorded()->isEmpty(), 409);
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
