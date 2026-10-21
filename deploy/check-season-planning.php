<?php

use App\ClubUnio\Http\UseActiveTenant;
use App\ClubUnio\Pages\SeasonPlanning;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new RuntimeException('Unexpected database');
}
foreach (['season_plans', 'season_plan_results'] as $table) {
    if (! Schema::connection('clubunio')->hasTable('clubora.'.$table)) {
        throw new RuntimeException('Planning schema missing');
    }
}
if (! in_array(SeasonPlanning::class, Filament\Facades\Filament::getPanel('clubunio')->getPages(), true)) {
    throw new RuntimeException('Planning page missing');
}
$route = app('router')->getRoutes()->getByName('filament.clubunio.pages.season-planning');
if (! $route || ! in_array(UseActiveTenant::class, $route->gatherMiddleware(), true)) {
    throw new RuntimeException('Planning tenant guard missing');
}
$count = 0;
foreach ($db->table('clubora.season_plans')->select('document_encrypted')->cursor() as $row) {
    json_decode(Crypt::decryptString($row->document_encrypted), true, 128, JSON_THROW_ON_ERROR);
    $count++;
}
echo "Planning schema, route and encrypted drafts verified: $count\n";
