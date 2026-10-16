<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AnnouncementChoices;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
foreach (['previous-full-options', 'bounded-options'] as $mode) {
    $db->enableQueryLog();
    $db->flushQueryLog();
    $start = microtime(true);
    $options = $mode === 'previous-full-options'
        ? Account::whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id))->where('active', true)->orderBy('display_name')->pluck('display_name', 'id')->all()
        : app(AnnouncementChoices::class)->search($tenant, 'accounts', '');
    $queries = $db->getQueryLog();
    // Never emit names, IDs, bindings, or notification data.
    echo json_encode(['mode' => $mode, 'rows' => count($options), 'payload_bytes' => strlen(json_encode($options, JSON_THROW_ON_ERROR)),
        'queries' => count($queries), 'elapsed_ms' => round((microtime(true) - $start) * 1000),
        'query_ms' => round(array_sum(array_column($queries, 'time')))], JSON_THROW_ON_ERROR).PHP_EOL;
    $db->disableQueryLog();
    unset($options);
}
