<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection('clubunio');
foreach (['Institut','BankkontoStaff','Bemerkungen','Funktionen','StaffQualifikationen','Funktionaere','Schluesselliste','SPONSOR','Events','Gaeste','Rechnung','Positionen','Spesenbelege','SpesenPositionen','SpesenTyp','BexioStatusMapping'] as $table) {
    $cols = $db->select('SELECT column_name,data_type FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['legacy_prod',$table]);
    echo $table.' ['.$db->table('legacy_prod.'.$table)->count().']: '.implode(', ',array_map(fn($r) => $r->column_name.' ('.$r->data_type.')', $cols)).PHP_EOL;
}
foreach (['BexioStatusMapping','SpesenTyp'] as $table) {
    echo $table.': '.json_encode($db->table('legacy_prod.'.$table)->get(), JSON_UNESCAPED_UNICODE).PHP_EOL;
}
