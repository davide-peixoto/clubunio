<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('staging') || config('app.url') !== 'https://stage.clubunio.com') {
    exit(1);
}
StageMigrationTarget::authorize(DB::connection('clubunio'), 'clubunio_stage');
$exit = $kernel->call('migrate', [
    '--database' => 'clubunio', '--path' => 'database/clubunio/2026_11_13_000500_member_campaigns.php', '--force' => true,
]);
echo $kernel->output();
exit($exit);
