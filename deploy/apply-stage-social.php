<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('staging') || config('app.url') !== 'https://stage.clubunio.com') {
    exit(1);
}
StageMigrationTarget::authorize(DB::connection('clubunio'), 'clubunio_stage');
$exit = $kernel->call('migrate', [
    '--database' => 'clubunio',
    '--path' => [
        'database/clubunio/2026_11_13_000100_social_studio.php',
        'database/clubunio/2026_11_13_000200_social_jobs.php',
    ],
    '--force' => true,
]);
echo $kernel->output();
exit($exit);
