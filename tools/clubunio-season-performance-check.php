<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\SeasonAssignmentTransferService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$db->beginTransaction();
try {
    $tenant = Tenant::create(['slug' => 'season-perf-'.Str::random(16), 'name' => 'Synthetic']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'office']);
    $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
    $source = $insert('seasons', ['name' => 'Synthetic source']);
    $target = $insert('seasons', ['name' => 'Synthetic target']);
    $function = $insert('staff_functions', ['code' => 'coach', 'name' => 'Synthetic']);
    for ($t = 0; $t < 4; $t++) {
        $team = $insert('teams', ['name' => 'Synthetic '.$t]);
        $ts = $insert('team_seasons', ['season_id' => $source, 'team_id' => $team, 'label' => 'Synthetic '.$t]);
        for ($m = 0; $m < 20; $m++) {
            $member = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Fixture']);
            $insert('team_players', ['team_season_id' => $ts, 'member_id' => $member]);
            if ($m < 2) {
                $insert('team_staff', ['team_season_id' => $ts, 'member_id' => $member, 'staff_function_id' => $function]);
            }
            if ($m === 0) {
                $insert('technical_leads', ['team_season_id' => $ts, 'member_id' => $member]);
            }
        }
    }
    $db->enableQueryLog();
    $db->flushQueryLog();
    $start = microtime(true);
    $plan = app(SeasonAssignmentTransferService::class)->preview($actor, $tenant, $source, $target);
    $queries = $db->getQueryLog();
    echo json_encode(['scenario' => 'four-teams-eighty-members', 'rows' => count($plan['rows']),
        'elapsed_ms' => round((microtime(true) - $start) * 1000), 'queries' => count($queries),
        'member_reads' => count(array_filter($queries, fn ($q) => str_contains($q['query'], 'from "clubora"."members"'))),
        'query_ms' => round(array_sum(array_column($queries, 'time'))), 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
