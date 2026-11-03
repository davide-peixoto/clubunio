<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$enabled = in_array('--enabled', $argv, true);
if (! $enabled && ! in_array('--foundation', $argv, true)) { throw new LogicException('Explicit release stage required'); }
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected deployment database'); }
if ((bool) config('clubunio-mobile.native_planning_enabled') !== $enabled) { throw new LogicException('Native planning stage mismatch'); }
$count = 0;
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $controller = explode('@', $route->getActionName())[0];
    if (in_array($controller, [App\ClubUnio\Mobile\MobilePlanningController::class,
        App\ClubUnio\Mobile\MobilePlanningFederationController::class, App\ClubUnio\Mobile\MobilePlanningInvitationsController::class], true)) {
        if (! in_array(App\ClubUnio\Mobile\MobilePlanningReady::class, $route->gatherMiddleware(), true)) { throw new LogicException('Ungated native planning route'); }
        $count++;
    }
}
if ($count !== 21) { throw new LogicException('Unexpected native planning route inventory: '.$count); }
if ($enabled) {
    $schema = $db->getSchemaBuilder();
    foreach (['team_participations' => ['attendance_comment_encrypted', 'attendance_updated_at'],
        'team_guests' => ['attendance_comment_encrypted', 'attendance_updated_at'], 'team_absences' => ['starts_at', 'ends_at'], 'team_events' => ['deleted_at']] as $table => $columns) {
        foreach ($columns as $column) { if (! $schema->hasColumn('clubora.'.$table, $column)) { throw new LogicException('Missing native planning schema'); } }
    }
    if (! $schema->hasTable('clubora.mobile_planning_commands')) { throw new LogicException('Missing planning command identities'); }
}
echo 'Personal planning '.($enabled ? 'enabled schema' : 'disabled foundation')." and21 protected routes verified.\n";
