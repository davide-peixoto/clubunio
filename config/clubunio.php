<?php

$path = base_path('.env.postgresql.local');
$v = is_file($path) ? \Dotenv\Dotenv::parse(file_get_contents($path)) : [];

return [
    'outbound_enabled' => false,
    'connection' => [
        'driver' => 'pgsql', 'host' => ($v['PGDATABASE'] ?? '') === 'clubunio_dev' && ($v['PGHOSTADDR'] ?? '') === '127.0.0.1' && ($v['PGHOST'] ?? '') === 'clubunio.com' ? 'clubunio.com;hostaddr=127.0.0.1' : ($v['PGHOST'] ?? ''),
        'port' => $v['PGPORT'] ?? '6432', 'database' => $v['PGDATABASE'] ?? '',
        'username' => $v['PGUSER'] ?? '', 'password' => $v['PGPASSWORD'] ?? '',
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'clubunio',
        'sslmode' => 'verify-full', 'sslrootcert' => $v['PGSSLROOTCERT'] ?? '',
        'options' => defined('PDO::PGSQL_ATTR_DISABLE_PREPARES')
            ? [PDO::ATTR_EMULATE_PREPARES => false, constant('PDO::PGSQL_ATTR_DISABLE_PREPARES') => true]
            : [],
    ],
];
