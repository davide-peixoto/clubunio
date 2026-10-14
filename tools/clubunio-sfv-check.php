<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\SfvPlanningService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Unexpected target');
}
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$keys = ['SFV_API_BASE_URL', 'SFV_APPLICATION_KEY', 'SFV_APPLICATION_PASS', 'SFV_CLUB_ID', 'SFV_LANGUAGE', 'SFV_SEASON_ID', 'SFV_SEASON_ID_AUTO'];
if (in_array('--enable-live-read', $argv, true)) {
    $db->table('clubora.tenant_settings')->where('tenant_id', $tenant->id)->whereIn('key', $keys)->update(['enabled' => true]);
    echo "SFV read configuration enabled; scheduler/write settings unchanged.\n";
}
foreach ($db->table('clubora.tenant_settings')->where('tenant_id', $tenant->id)->whereIn('key', $keys)->get() as $row) {
    $filled = trim(Crypt::decryptString($row->encrypted_value)) !== '';
    echo $row->key.' enabled='.(int) $row->enabled.' filled='.(int) $filled.PHP_EOL;
}
if (in_array('--refresh', $argv, true)) {
    $accountId = $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->whereIn('role', ['admin', 'office'])->value('account_id');
    $actor = Account::findOrFail($accountId);
    try {
        $count = app(SfvPlanningService::class)->refresh($actor, $tenant, ['from' => now('Europe/Zurich')->toDateString(), 'to' => now('Europe/Zurich')->addMonths(3)->toDateString()]);
        echo 'SFV schedule rows refreshed: '.$count.PHP_EOL;
    } catch (ValidationException $e) {
        echo json_encode($e->errors(), JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(1);
    }
}
echo 'SFV games: '.$db->table('clubora.sfv_schedule')->where('tenant_id', $tenant->id)->count().PHP_EOL;
echo 'Series events: '.$db->table('clubora.team_events')->where('tenant_id', $tenant->id)->whereNotNull('series_key')->count().PHP_EOL;
echo 'SFV-linked events: '.$db->table('clubora.team_events')->where('tenant_id', $tenant->id)->whereNotNull('sfv_match_id')->count().PHP_EOL;
$outside = $db->selectOne('SELECT count(*) AS n FROM clubora.team_participations p JOIN clubora.team_events e ON e.tenant_id=p.tenant_id AND e.id=p.event_id WHERE p.tenant_id=? AND e.legacy_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM clubora.team_players t WHERE t.tenant_id=e.tenant_id AND t.team_season_id=e.team_season_id AND t.member_id=p.member_id AND t.active) AND NOT EXISTS (SELECT 1 FROM clubora.team_staff t WHERE t.tenant_id=e.tenant_id AND t.team_season_id=e.team_season_id AND t.member_id=p.member_id AND t.active)', [$tenant->id]);
echo 'Snapshot participants outside current team assignments: '.$outside->n.PHP_EOL;
echo 'Event invitations: '.$db->table('clubora.team_invitations')->where('tenant_id', $tenant->id)->count().PHP_EOL;
