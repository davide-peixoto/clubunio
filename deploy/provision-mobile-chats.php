<?php

use App\ClubUnio\Mobile\ChatService;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDriverName() === 'pgsql' && $db->getDatabaseName() === 'db_acdf8d_dclubora'
    && config('clubunio-mobile.enabled'), 409, 'Reviewed active ClubUnio module required');
$count = 0;
Tenant::query()->orderBy('id')->chunkById(50, function ($tenants) use ($db, &$count) {
    foreach ($tenants as $tenant) {
        $db->transaction(function () use ($db, $tenant, &$count) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $db->table('clubora.team_seasons')->where('tenant_id', $tenant->id)->where('active', true)
                ->orderBy('id')->chunkById(100, function ($teams) use ($tenant, &$count) {
                    foreach ($teams as $team) { app(ChatService::class)->provisionTeam($tenant, (int) $team->id); $count++; }
                });
        });
    }
});
echo 'Season chat scopes provisioned idempotently: '.$count."; no member notifications sent.\n";
