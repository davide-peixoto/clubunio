<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection('clubunio');
$tables=['Teams','TeamsSaison','Saison','MitgliederAnmeldungen','AnmeldungStatus','MitgliederStatus','MitgliederArt','Mitglieder'];
foreach ($tables as $table) {
    $rows=$db->select('SELECT column_name,data_type FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position',['legacy_prod',$table]);
    echo $table.': '.implode(', ',array_map(fn($r)=>$r->column_name.' ('.$r->data_type.')',$rows)).PHP_EOL;
}
foreach (['AnmeldungStatus','MitgliederStatus','MitgliederArt'] as $table) {
    echo $table.': '.json_encode($db->table('legacy_prod.'.$table)->get(),JSON_UNESCAPED_UNICODE).PHP_EOL;
}
