<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Synthetic, rolled-back local transaction; never touch existing club members.
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
    $tenant = Tenant::create(['slug' => 'form-check-'.Str::random(16), 'name' => 'Synthetic performance check']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'office']);
    $insert = fn ($table, $values) => $db->table('clubora.'.$table)->insertGetId(['tenant_id' => $tenant->id] + $values);
    $country = $insert('countries', ['code' => 'CH', 'name' => 'Synthetic']);
    $input = ['first_name' => 'Synthetic', 'last_name' => 'Performance', 'company' => null, 'email' => 'fixture@example.invalid',
        'status_id' => $insert('member_statuses', ['code' => 'M', 'name' => 'Member', 'restores_membership' => true]),
        'type_id' => $insert('member_types', ['code' => 'A', 'name' => 'Active']),
        'country_id' => $country, 'nationality_id' => $country,
        'gender_id' => $insert('genders', ['code' => 'N', 'name' => 'Neutral']),
        'billing_recipient_id' => $insert('billing_recipients', ['code' => 'E', 'name' => 'Self', 'email_field' => 'email'])];
    $db->enableQueryLog();
    $db->flushQueryLog();
    $start = microtime(true);
    $member = app(MemberService::class)->create($actor, $tenant, $input);
    $queries = $db->getQueryLog();
    $lookupTables = ['countries', 'member_statuses', 'member_types', 'genders', 'billing_recipients'];
    $lookupReads = 0;
    foreach ($queries as $query) {
        foreach ($lookupTables as $table) {
            if (str_contains($query['query'], 'from "clubora"."'.$table.'"')) {
                $lookupReads++;
            }
        }
    }
    echo json_encode(['scenario' => 'synthetic-member-create', 'elapsed_ms' => round((microtime(true) - $start) * 1000),
        'queries' => count($queries), 'lookup_reads' => $lookupReads, 'query_ms' => round(array_sum(array_column($queries, 'time'))),
        'saved' => $member->exists, 'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
