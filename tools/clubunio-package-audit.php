<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Unexpected target');
}
// Metadata and counts only. No credentials, names, prompts or payloads.
$legacy = $db->table('information_schema.tables')->where('table_schema', 'legacy_prod')->where('table_name', 'ilike', '%Training%')->pluck('table_name');
foreach ($legacy as $name) {
    echo $name.': '.$db->table('legacy_prod.'.$name)->count().' rows'.PHP_EOL;
    if ($name === 'TrainingPlannerAuditLog') {
        echo 'Columns: '.implode(', ', $db->table('information_schema.columns')->where('table_schema', 'legacy_prod')->where('table_name', $name)->orderBy('ordinal_position')->pluck('column_name')->all()).PHP_EOL;
    }
}
$tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
echo 'Unassigned historical buses: '.$db->table('clubora.bus_reservations')->where('tenant_id', $tenant)->whereNotNull('legacy_id')
    ->where(fn ($q) => $q->whereNull('member_id')->orWhereNull('team_season_id'))->count().PHP_EOL;
echo 'Banks with imported address: '.$db->table('clubora.banks')->where('tenant_id', $tenant)->whereNotNull('street')->count().PHP_EOL;
$modules = json_decode($db->table('migration_audit.catalog')->where('category', 'modules')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
foreach ($modules as $module) {
    if (in_array($module['name'] ?? '', ['ListeSpotsFamilie', 'ListeSpots'], true)) {
        echo json_encode($module, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
$shapes = [];
foreach ($db->table('legacy_prod.TrainingPlannerAuditLog')->get(['PlanJson', 'RequestJson', 'Status', 'SharedTeamSaisonID']) as $row) {
    $plan = json_decode($row->PlanJson ?? '', true);
    $request = json_decode($row->RequestJson ?? '', true);
    $shape = json_encode(['status' => $row->Status, 'planKeys' => is_array($plan) ? array_keys($plan) : [],
        'requestKeys' => is_array($request) ? array_keys($request) : [],
        'exerciseKeys' => array_keys($plan['exercises'][0] ?? []), 'hasTeam' => $row->SharedTeamSaisonID !== null]);
    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
}
foreach ($shapes as $shape => $count) {
    echo $count.' x '.$shape.PHP_EOL;
}
echo 'Key source rows: '.$db->table('legacy_prod.Schluesselliste')->count().PHP_EOL;
echo 'Imported keys: '.$db->table('clubora.member_keys')->where('tenant_id', $tenant)->whereNotNull('legacy_id')->count().PHP_EOL;
foreach ($db->table('clubora.spots_recipients')->where('tenant_id', $tenant)->selectRaw('kind, count(*) as total')->groupBy('kind')->get() as $row) {
    echo 'Spots '.$row->kind.': '.$row->total.PHP_EOL;
}
