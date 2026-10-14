<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Unexpected target');
}
foreach (['Bus', 'BusReservierungen'] as $table) {
    echo $table.': '.json_encode($db->table('information_schema.columns')->where('table_schema', 'legacy_prod')->where('table_name', $table)->pluck('column_name')).PHP_EOL;
    echo 'Count: '.$db->table('legacy_prod.'.$table)->count().PHP_EOL;
}
echo 'Statuses: '.json_encode($db->table('legacy_prod.BusReservierungen')->selectRaw('"ReservierungsStatus", count(*) as count')->groupBy('ReservierungsStatus')->get()).PHP_EOL;
echo 'Invalid periods: '.$db->table('legacy_prod.BusReservierungen')->where(fn ($q) => $q->whereNull('ResVon')->orWhereNull('ResBis')->orWhereColumn('ResBis', '<=', 'ResVon'))->count().PHP_EOL;
echo 'Missing assignments: '.$db->table('legacy_prod.BusReservierungen')->where(fn ($q) => $q->whereNull('TeamSaisonID')->orWhereNull('AntragstellerMitgliederID'))->count().PHP_EOL;
if ($db->getSchemaBuilder()->hasTable('clubora.bus_reservations')) {
    $tenant=$db->table('clubora.tenants')->where('slug','sv-sissach')->value('id');
    echo 'ClubUnio imported reservations: '.$db->table('clubora.bus_reservations')->where('tenant_id',$tenant)->whereNotNull('legacy_id')->count().PHP_EOL;
    echo 'ClubUnio source workflow retained: '.$db->table('clubora.bus_reservations')->where('tenant_id',$tenant)->whereNotNull('legacy_workflow')->count().PHP_EOL;
}
