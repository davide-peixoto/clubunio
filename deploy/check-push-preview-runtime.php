<?php

// Read-only DEV gate. Never sends a message, creates an outbox row or calls a provider.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || config('app.url') !== 'https://dev.clubunio.ch') throw new LogicException('DEV CLI required');
Illuminate\Support\Facades\Http::preventStrayRequests();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Reviewed database required');
if (!config('clubunio-mobile-push.enabled') || !config('clubunio-mobile-push.delivery_enabled')) throw new LogicException('Existing DEV push flags required');
$start = microtime(true);
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $db->statement("SET LOCAL statement_timeout='5s'");
    $counts = $db->table('clubora.mobile_push_outbox')->selectRaw('state, count(*) AS count')->groupBy('state')->get()->pluck('count', 'state');
    $lag = $db->selectOne("SELECT count(*) AS samples, round(avg(extract(epoch from (o.finished_at-p.created_at)))::numeric,1) AS mean_seconds,
        round(max(extract(epoch from (o.finished_at-p.created_at)))::numeric,1) AS max_seconds
        FROM clubora.mobile_push_outbox o JOIN clubora.mobile_chat_publications p
        ON o.tenant_id=p.tenant_id AND o.event_key='chat.message:'||p.message_id::text
        WHERE o.state='accepted' AND p.created_at > now()-interval '1 day'");
    echo json_encode(['outbox_states' => $counts, 'chat_source_to_provider_acceptance' => $lag], JSON_THROW_ON_ERROR).PHP_EOL;
    $db->rollBack();
} catch (Throwable $e) { $db->rollBack(); throw $e; }
$presentation = app(App\ClubUnio\Mobile\MobilePushPresentation::class);
foreach (['de', 'en'] as $locale) {
    $source = ['actor' => (object)['display_name' => 'Synthetic'],
        'message' => (object)['body_encrypted' => Illuminate\Support\Facades\Crypt::encryptString(str_repeat('x', 300))]];
    $preview = $presentation->notification($locale, $source);
    if (mb_strlen($preview['body']) !== 180 || $preview['title'] !== 'Synthetic'
        || str_starts_with($presentation->notification($locale)['body'], 'mobile_push.')) throw new LogicException('Preview/catalog mismatch');
}
echo 'Read-only gate and synthetic DE/EN presentation passed in '.round((microtime(true)-$start)*1000)."ms; no provider called.\n";
