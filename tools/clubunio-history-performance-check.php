<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\DossierHistoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
$db->beginTransaction();
try {
    $tenant = Tenant::create(['slug' => 'history-perf-'.Str::random(16), 'name' => 'Synthetic']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'office']);
    $member = Member::findOrFail($db->table('clubora.members')->insertGetId(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Fixture']));
    $rows = [];
    for ($i = 0; $i < 110; $i++) {
        $rows[] = ['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'members', 'object_id' => $member->id,
            'before_value' => json_encode(['notes' => 'Before', 'revision' => $i]),
            'after_value' => json_encode(['notes' => 'After', 'revision' => $i + 1, 'ignored_fixture' => str_repeat('UNRELEASED-FIXTURE', 1200)])];
    }
    $db->table('clubora.object_changes')->insert($rows);
    $db->enableQueryLog();
    $db->flushQueryLog();
    $start = microtime(true);
    $entries = app(DossierHistoryService::class)->entries($actor, $tenant, $member);
    $queries = $db->getQueryLog();
    $json = json_encode($entries, JSON_THROW_ON_ERROR);
    abort_unless(count($entries) === 100 && ! str_contains($json, 'UNRELEASED-FIXTURE'), 409);
    echo json_encode(['scenario' => 'large-dossier-history', 'elapsed_ms' => round((microtime(true) - $start) * 1000),
        'queries' => count($queries), 'query_ms' => round(array_sum(array_column($queries, 'time'))), 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
