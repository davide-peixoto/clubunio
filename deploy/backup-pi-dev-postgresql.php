<?php

if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== realpath('/var/www/svs-laravel/current')) {
    exit(1);
}

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$config = $db->getConfig();
if (config('app.url') !== 'https://dev.clubunio.ch'
    || $db->getDriverName() !== 'pgsql'
    || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
    || $config['host'] !== 'pg6001.site4now.net'
    || ! is_string($config['password']) || strpbrk($config['password'], "\r\n") !== false) {
    fwrite(STDERR, "Unexpected ClubUnio DEV source.\n");
    exit(1);
}

$process = proc_open([
    'docker', 'run', '--rm', '--network', 'host', '--read-only', '-i', '--entrypoint', 'sh', 'postgres:18-alpine',
    '-ec', 'IFS= read -r PGPASSWORD; export PGPASSWORD PGSSLMODE=verify-full PGSSLROOTCERT=/etc/ssl/certs/ca-certificates.crt; exec pg_dump "$@"',
    'sh', '-h', $config['host'], '-p', (string) $config['port'], '-U', $config['username'], '-d', $db->getDatabaseName(),
    '--format=custom', '--compress=1', '--quote-all-identifiers', '--lock-wait-timeout=5s',
], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (! is_resource($process)) {
    exit(1);
}
fwrite($pipes[0], $config['password']."\n");
fclose($pipes[0]);
exit(proc_close($process));
