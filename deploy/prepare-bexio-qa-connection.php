<?php

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '/root/clubunio-bexio-qa-20261124') exit(1);
$root = '/home/runcloud/webapps/ClubUnioStage';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection('clubunio')->getDatabaseName() !== 'clubunio_stage') exit(1);
$connection = config('database.connections.clubunio');
$connection = array_replace($connection, ['host' => '127.0.0.1', 'port' => 5432, 'url' => null,
    'database' => 'clubunio_bexio_qa_20261124', 'search_path' => 'clubora', 'sslmode' => 'disable',
    'options' => [PDO::PGSQL_ATTR_DISABLE_PREPARES => true]]);
$path = $argv[1].'/connection.json';
file_put_contents($path, json_encode($connection, JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Private QA-only connection prepared.\n";
