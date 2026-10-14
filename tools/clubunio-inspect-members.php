<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection('clubunio');
foreach (['Positionen','TeamSatffFunktion','MitgliederAnmeldungen','TeamList','TeamStaff','TechnischeLeiterTeams'] as $table) {
    $cols=$db->select('SELECT column_name,data_type FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position',['legacy_prod',$table]);
    echo $table.': '.implode(', ',array_map(fn($r)=>$r->column_name.' ('.$r->data_type.')',$cols)).PHP_EOL;
    if ($cols) echo 'Rows: '.$db->table('legacy_prod.'.$table)->count().PHP_EOL;
}
foreach (['Geschlecht','Land','Nationalitaet','RechnungAn','Ehrenmitglied','Freimitglied','Supporter','Sponsor','Spots'] as $col) {
    echo $col.': '.json_encode($db->table('legacy_prod.Mitglieder')->select($col)->distinct()->pluck($col),JSON_UNESCAPED_UNICODE).PHP_EOL;
}
echo 'Other tables: '.json_encode($db->table('information_schema.tables')->where('table_schema','legacy_prod')->where(fn($q)=>$q->where('table_name','ilike','%funktion%')->orWhere('table_name','ilike','%position%'))->pluck('table_name')).PHP_EOL;
foreach (['TeamList','TeamStaff','TechnischeLeiterTeams'] as $table) {
    echo $table.' unmapped members: '.$db->table('legacy_prod.'.$table.' as x')->leftJoin('members as m','m.legacy_id','=','x.MitgliederID')->whereNull('m.id')->count().PHP_EOL;
    echo $table.' unmapped seasons: '.$db->table('legacy_prod.'.$table.' as x')->leftJoin('team_seasons as s','s.legacy_id','=','x.TeamSaisonID')->whereNull('s.id')->count().PHP_EOL;
}
