<?php

if (PHP_SAPI !== 'cli') { exit(1); }
$base = getenv('CLUBUNIO_BASE');
if (! in_array($base, ['/home/runcloud/webapps/ClubUnioStage', '/home/runcloud/webapps/ClubUnioProd'], true)) { exit(1); }
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$schema = $db->getSchemaBuilder();
echo json_encode(['url' => config('app.url'), 'identity' => $db->selectOne('SELECT current_database() AS db,current_user AS role,current_schema() AS schema'),
    'tables' => collect(['veo_cameras', 'veo_recording_teams', 'veo_recording_adjustments'])->mapWithKeys(fn ($table) => [$table => $schema->hasTable('clubunio.'.$table)])->all(),
    'migrations' => $db->table('clubunio.migrations')->where('migration', 'like', '%veo%')->pluck('migration'),
    'fixtures' => $db->table('clubunio.tenants')->where('slug', 'like', 'veo-test-%')->count(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
