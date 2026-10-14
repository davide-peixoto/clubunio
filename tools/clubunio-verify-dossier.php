<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection('clubunio');
$tenant=$db->table('clubora.tenants')->where('slug','sv-sissach')->value('id');
$tables=['Institut'=>'banks','BankkontoStaff'=>'member_bank_accounts','Bemerkungen'=>'member_notes','Funktionaere'=>'member_functions',
    'Schluesselliste'=>'member_keys','SPONSOR'=>'sponsorships','Events'=>'club_events','Gaeste'=>'event_guests',
    'Rechnung'=>'invoices','Positionen'=>'invoice_lines','Spesenbelege'=>'expenses','SpesenPositionen'=>'expense_lines'];
foreach ($tables as $source=>$target) {
    $expected=$db->table('legacy_prod.'.$source)->count();
    $actual=$db->table('clubora.'.$target)->where('tenant_id',$tenant)->whereNotNull('legacy_id')->count();
    if ($expected!==$actual) { throw new RuntimeException('Count mismatch: '.$target); }
    echo "$target: $actual verified\n";
}
foreach ([['Positionen','NettoBetrag','invoice_lines','net_amount'],['SpesenPositionen','Betrag','expense_lines','amount']] as [$source,$old,$target,$new]) {
    $sourceTotal=$db->table('legacy_prod.'.$source)->sum($old);
    $targetTotal=$db->table('clubora.'.$target)->where('tenant_id',$tenant)->sum($new);
    if (bccomp((string)$sourceTotal,(string)$targetTotal,4)!==0) { throw new RuntimeException('Amount mismatch: '.$target); }
    echo "$target: monetary total verified\n";
}
$unresolved=$db->table('clubora.team_players')->where('tenant_id',$tenant)->whereNotNull('legacy_invoice_position_id')->whereNull('invoice_line_id')->count();
echo "Unresolved historic player/invoice links: $unresolved\n";
$unknown=0;
foreach (['invoice_statuses','invoice_line_statuses','expense_statuses','expense_rounds','game_types'] as $table) {
    $unknown+=$db->table('clubora.'.$table)->where('tenant_id',$tenant)->where('name','like','Unbekannt (%')->count();
}
echo "Preserved unknown lookup codes: $unknown\n";
$count=0;
foreach ($db->table('clubora.member_bank_accounts')->where('tenant_id',$tenant)->pluck('iban_encrypted') as $cipher) {
    \Illuminate\Support\Facades\Crypt::decryptString($cipher); $count++;
}
echo "Encrypted bank accounts verified: $count\n";
