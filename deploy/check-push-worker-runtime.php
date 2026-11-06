<?php
require dirname(__DIR__).'/vendor/autoload.php'; $app=require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(PHP_SAPI!=='cli' || config('app.url')!=='https://dev.clubunio.ch') throw new LogicException('DEV CLI required');
Illuminate\Support\Facades\Http::preventStrayRequests();
$db=Illuminate\Support\Facades\DB::connection('clubunio');
if($db->getDriverName()!=='pgsql'||$db->getDatabaseName()!=='db_acdf8d_dclubora') throw new LogicException('Reviewed database required');
$db->beginTransaction(); $db->statement('SET TRANSACTION READ ONLY'); $db->statement("SET LOCAL statement_timeout='5s'");
$rows=$db->select("SELECT round(extract(epoch from (p.finished_at-p.created_at))::numeric,3) AS wait_and_fanout_s, round(extract(epoch from (o.finished_at-o.created_at))::numeric,3) AS delivery_s, round(extract(epoch from (o.finished_at-p.created_at))::numeric,3) AS source_to_provider_s, p.attempts, p.queued_count FROM clubora.mobile_push_outbox o JOIN clubora.mobile_chat_publications p ON o.tenant_id=p.tenant_id AND o.event_key='chat.message:'||p.message_id::text WHERE o.state='accepted' ORDER BY o.finished_at DESC LIMIT 4");
echo json_encode($rows,JSON_THROW_ON_ERROR).PHP_EOL; $row=$db->table('clubora.mobile_push_outbox')->where('state','accepted')->orderByDesc('finished_at')->first();
$actor=App\ClubUnio\Models\Account::findOrFail($row->account_id); $tenant=App\ClubUnio\Models\Tenant::findOrFail($row->tenant_id);
foreach (['target','source'] as $part) {
 $db->enableQueryLog(); $db->flushQueryLog(); $start=microtime(true);
 $result=$part==='target' ? app(App\ClubUnio\Mobile\MobilePushOutbox::class)->targetAllowed($actor,$tenant,$row->kind,(int)$row->object_id) : app(App\ClubUnio\Mobile\ChatPushPublications::class)->source($tenant,(int)substr($row->event_key,13));
 echo json_encode(['part'=>$part,'queries'=>count($db->getQueryLog()),'ms'=>round((microtime(true)-$start)*1000),'allowed'=>(bool)$result]).PHP_EOL; $db->disableQueryLog();
}
$db->rollBack(); echo "Worker proof gate passed; no provider call or durable writes.\n";
