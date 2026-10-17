<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ContributionBatchService;
use App\ClubUnio\Services\ContributionInvoiceService;
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
    $tenant = Tenant::create(['slug' => 'contribution-perf-'.Str::random(16), 'name' => 'Synthetic']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'office']);
    $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
    $season = $insert('seasons', ['name' => 'Synthetic season']);
    $team = $insert('teams', ['name' => 'Synthetic team']);
    $ts = $insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic']);
    $gender = $insert('genders', ['code' => 'X', 'name' => 'Synthetic']);
    $price = $insert('contribution_prices', ['name' => 'Synthetic tariff', 'amount' => '250.0050']);
    $insert('contribution_rules', ['season_id' => $season, 'price_id' => $price, 'gender_id' => $gender, 'year_from' => 2010, 'year_to' => 2010]);
    $insert('invoice_line_statuses', ['code' => 'P', 'name' => 'Pendent']);
    $insert('invoice_workflow_statuses', ['code' => 'draft', 'name' => 'Draft']);
    for ($i = 0; $i < 25; $i++) {
        $member = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Fixture', 'gender_id' => $gender, 'birth_date' => '2010-01-01']);
        $insert('team_players', ['team_season_id' => $ts, 'member_id' => $member]);
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
    $batch = $measure('twenty-five-preview', fn () => app(ContributionBatchService::class)->preview($actor, $tenant, $season));
    abort_unless(count($batch['rows']) === 25 && ! array_filter($batch['rows'], fn ($row) => isset($row['error'])), 409);
    $row = $batch['rows'][0];
    $invoice = $measure('single-internal-draft', fn () => app(ContributionInvoiceService::class)->create($actor, $tenant, $row['member_id'], $season, $row['fingerprint']));
    abort_unless($invoice->bexio_invoice_id === null, 409);
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
