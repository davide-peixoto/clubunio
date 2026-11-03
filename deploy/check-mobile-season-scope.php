<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected database'); }
$request = Illuminate\Http\Request::create('/api/mobile/v1');
$request->attributes->set('mobile.season_scope', true);
$app->instance('request', $request);
$scope = app(App\ClubUnio\Mobile\MobileSeasonScope::class);
$tenants = App\ClubUnio\Models\Tenant::query()->get();
foreach ($tenants as $tenant) {
    $season = $scope->current($tenant);
    $query = app(App\ClubUnio\Services\TeamPlanningService::class)->table($tenant, 'team_seasons');
    if (! $season && $query->exists()) { throw new LogicException('Missing season exposes rows'); }
    if ($season && (clone $query)->where('season_id', '!=', $season->id)->exists()) { throw new LogicException('Historical season exposed'); }
}
echo "Personal mobile current-season SQL scope verified; no data changed.\n";
