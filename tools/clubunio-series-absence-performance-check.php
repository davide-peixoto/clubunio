<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\PlanningSeriesService;
use App\ClubUnio\Services\TeamPlanningService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
    $tenant = Tenant::create(['slug' => 'series-perf-'.Str::random(16), 'name' => 'Synthetic']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
    TeamPlanningService::seedOptions($tenant->id);
    $season = $insert('seasons', ['name' => 'Synthetic season']);
    $team = $insert('teams', ['name' => 'Synthetic team']);
    $ts = $insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic']);
    $members = [];
    for ($i = 0; $i < 20; $i++) {
        $member = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Fixture']);
        $members[] = $member;
        $insert('team_players', ['team_season_id' => $ts, 'member_id' => $member]);
    }
    $events = [];
    $series = Str::uuid()->toString();
    for ($i = 0; $i < 12; $i++) {
        $start = now('Europe/Zurich')->addDays(5 + $i)->setTime(18, 0);
        $events[] = $event = $insert('team_events', ['team_season_id' => $ts, 'title' => 'Synthetic', 'kind' => 'TRAINING', 'mode' => 'ABMELDEN', 'status' => 'AKTIV', 'capacity' => 30,
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(),
            'deadline_at' => ($i < 2 ? now()->subHour() : $start->copy()->subDay())->toIso8601String(), 'series_key' => $series]);
        foreach ($members as $member) {
            $insert('team_participations', ['event_id' => $event, 'member_id' => $member, 'status' => 'DABEI']);
        }
    }
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
    $preview = $measure('series-ten-eligible-events', fn () => app(PlanningSeriesService::class)->preview($actor, $tenant, $events[2], [
        'from' => now('Europe/Zurich')->toDateString(), 'shift_minutes' => 30, 'change_capacity' => 1, 'capacity' => 25,
    ]));
    abort_unless(count($preview['changes']) === 10 && $preview['skipped'] === 2, 409);
    $absence = $measure('absence-ten-open-two-locked', fn () => app(TeamPlanningService::class)->saveAbsence($actor, $tenant, null, [
        'member_id' => $members[0], 'starts_on' => now('Europe/Zurich')->toDateString(), 'ends_on' => now('Europe/Zurich')->addDays(25)->toDateString(), 'kind' => 'KRANK',
    ]));
    abort_unless($absence['skipped'] === 2, 409);
    abort_unless($db->table('clubora.team_participations')->where('tenant_id', $tenant->id)->where('source', 'absence')->count() === 10, 409);
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
