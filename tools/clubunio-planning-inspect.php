<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
echo 'Database timezone: '.$db->selectOne('SHOW TIMEZONE')->TimeZone.PHP_EOL;
foreach (['TeamTermine', 'TeamTerminTeilnahmen', 'TeamAbsenzen'] as $table) {
    echo $table.': '.$db->table('legacy_prod.'.$table)->count().PHP_EOL;
    echo implode(',', $db->table('information_schema.columns')->where('table_schema', 'legacy_prod')->where('table_name', $table)->pluck('column_name')->all()).PHP_EOL;
}
foreach (['team_events', 'team_participations', 'team_absences'] as $table) {
    echo 'clubora.'.$table.': '.$db->table('clubora.'.$table)->where('tenant_id', $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id'))->count().PHP_EOL;
}
foreach (['TeamTerminGaeste', 'TeamTerminGuests'] as $table) {
    if ($db->getSchemaBuilder()->hasTable('legacy_prod.'.$table)) {
        echo $table.': '.$db->table('legacy_prod.'.$table)->count().PHP_EOL;
        echo implode(',', $db->table('information_schema.columns')->where('table_schema', 'legacy_prod')->where('table_name', $table)->pluck('column_name')->all()).PHP_EOL;
    }
}
foreach ($db->table('clubora.tenant_settings')->where('key', 'like', 'SFV_%')->get(['key', 'area', 'enabled']) as $s) {
    echo 'Setting '.$s->key.' area='.$s->area.' enabled='.(int) $s->enabled.PHP_EOL;
}
