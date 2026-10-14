<?php

// Generate a private deployment file without printing credentials.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = $argv[1] ?? throw new RuntimeException('Private output path required');
if (file_exists($target)) {
    throw new RuntimeException('Refusing to overwrite an existing deployment key');
}
$connection = config('legacy.connection');
$values = [
    'APP_NAME' => 'SV Sissach DEV2', 'APP_ENV' => 'staging',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
    'APP_URL' => 'https://dev-my2.svsissach.ch', 'APP_LOCALE' => 'de',
    'DB_CONNECTION' => 'legacy', 'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'svs_dev2_session', 'SESSION_SECURE_COOKIE' => 'true',
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'log',
    'LOG_CHANNEL' => 'daily', 'LOG_DAILY_DAYS' => '14',
    'SVS_DEV_DB_HOST' => $connection['host'], 'SVS_DEV_DB_PORT' => $connection['port'],
    'SVS_DEV_DB_DATABASE' => $connection['database'], 'SVS_DEV_DB_USERNAME' => $connection['username'],
    'SVS_DEV_DB_PASSWORD' => $connection['password'],
];
$lines = [];
foreach ($values as $name => $value) {
    $lines[] = $name.'="'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value).'"';
}
file_put_contents($target, implode("\n", $lines)."\n");
echo "Private staging environment generated.\n";
