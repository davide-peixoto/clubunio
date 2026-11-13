<?php

// Stream the verified DEV database to an encrypted backup pipeline, never a plaintext file.
$argv[1] = 'backup-stream';
require __DIR__.'/verify-pi-dev-vps-database.php';
$config = $db->getConfig();
$password = $config['password'];
$ca = realpath($config['sslrootcert'] ?? '');
if (! is_string($password) || strpbrk($password, "\r\n") !== false || ! $ca || ! is_readable($ca)) {
    exit(1);
}
$process = proc_open([
    'docker', 'run', '--rm', '--network', 'host', '--read-only', '-i',
    '-v', $ca.':/dev-db-ca.pem:ro', '--entrypoint', 'sh', 'postgres:16-alpine',
    '-ec', 'IFS= read -r PGPASSWORD; export PGPASSWORD PGSSLMODE=verify-full PGSSLROOTCERT=/dev-db-ca.pem PGHOSTADDR=127.0.0.1; exec pg_dump "$@"',
    'sh', '-h', 'clubunio.com', '-p', '15432', '-U', 'clubunio_dev_app', '-d', 'clubunio_dev',
    '--schema=clubunio', '--format=custom', '--compress=1', '--no-owner', '--lock-wait-timeout=5s',
], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (! is_resource($process)) {
    exit(1);
}
fwrite($pipes[0], $password."\n");
fclose($pipes[0]);
exit(proc_close($process));
