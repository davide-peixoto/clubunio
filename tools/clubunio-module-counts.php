<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection('clubunio');
foreach (['members','countries','genders','billing_recipients','families','family_members','member_documents','document_types',
    'team_players','team_staff','technical_leads','staff_functions'] as $table) {
    echo $table.': '.$db->table('clubora.'.$table)->count().PHP_EOL;
}
echo 'Orphan member documents: '.$db->table('clubora.member_documents as d')->leftJoin('clubora.members as m',fn($j)=>$j->on('m.id','=','d.member_id')->on('m.tenant_id','=','d.tenant_id'))->whereNull('m.id')->count().PHP_EOL;
