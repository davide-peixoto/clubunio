<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\Synchronisation;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
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
    $tenant = Tenant::create(['slug' => 'sync-view-perf-'.Str::random(16), 'name' => 'Synthetic']);
    $actor = new Account;
    $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic', 'active' => true, 'password' => bcrypt(Str::random(40))])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $cipher = Crypt::encryptString(json_encode(['fixture' => str_repeat('x', 1000)]));
    $note = Crypt::encryptString(str_repeat('Synthetic note. ', 40));
    for ($i = 0; $i < 20; $i++) {
        $id = $db->table('clubora.sync_work_items')->insertGetId(['tenant_id' => $tenant->id, 'operation' => 'catalog', 'object_id' => $i,
            'deduplication_key' => hash('sha256', (string) $i), 'encrypted_payload' => $cipher, 'created_by' => $actor->id,
            'attempts' => 50, 'state' => 'attention', 'error_code' => 'status_503']);
        $events = [];
        for ($j = 0; $j < 100; $j++) {
            $events[] = ['work_item_id' => $id, 'actor_id' => $actor->id, 'event' => 'attention', 'code' => 'status_503', 'encrypted_note' => $note];
        }
        $db->table('clubora.sync_work_events')->insert($events);
    }
    Auth::guard('clubunio')->setUser($actor);
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));
    Filament::setTenant($tenant);
    $page = new Synchronisation;
    $read = new ReflectionMethod($page, 'getViewData');
    $measure = function (string $scenario) use ($db, $page, $read) {
        $db->enableQueryLog();
        $db->flushQueryLog();
        $start = microtime(true);
        $data = $read->invoke($page);
        $queries = $db->getQueryLog();
        $eventRows = isset($data['events']) ? $data['events']->sum(fn ($rows) => $rows->count()) : ($data['history']?->count() ?? 0);
        echo json_encode(['scenario' => $scenario, 'elapsed_ms' => round((microtime(true) - $start) * 1000),
            'queries' => count($queries), 'query_ms' => round(array_sum(array_column($queries, 'time'))),
            'event_rows' => $eventRows, 'loaded_bytes' => strlen(json_encode($data['rows']->items())) + strlen(json_encode($data['events'] ?? $data['history']?->items() ?? [])),
            'rollback' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        $db->disableQueryLog();
        abort_unless($data['rows']->count() === 20, 409);
    };
    $measure('list-twenty-items-two-thousand-events');
    if (property_exists($page, 'historyItemId')) {
        $page->historyItemId = $id;
    }
    $measure('one-history-open');
} finally {
    $db->disableQueryLog();
    $db->rollBack();
}
