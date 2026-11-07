<?php

use Illuminate\Support\Facades\DB;

try {
    $root = '/var/www/svs-laravel';
    $previous = "$root/releases/20261107-clubunio-push-bound";
    $release = "$root/releases/20261107-clubunio-tenant-storage";
    $mode = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || realpath(dirname(__DIR__)) !== $release
        || realpath("$root/current") !== $previous || ! in_array($mode, ['check', 'dump', 'migrate', 'verify'], true)) {
        throw new LogicException('Expected inactive ClubUnio release.');
    }

    require "$release/vendor/autoload.php";
    $app = require "$release/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (config('app.url') !== 'https://dev.clubunio.ch' || $db->getDriverName() !== 'pgsql'
        || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) {
        throw new LogicException('Expected separate ClubUnio PostgreSQL database.');
    }
    foreach (config('clubunio-storage') as $flag) {
        if ($flag !== false) {
            throw new LogicException('All document storage flags must remain off.');
        }
    }
    if (! extension_loaded('gd') || ! class_exists(League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)
        || ! app('router')->getRoutes()->getByName('clubunio.tenant-logo')) {
        throw new LogicException('Logo or storage runtime is incomplete.');
    }
    $names = ['2026_11_07_000100_tenant_storage_profiles', '2026_11_07_000200_tenant_storage_providers'];
    $ledger = $db->table('clubora.migrations');
    $tables = ['clubora.tenant_storage_profiles', 'clubora.tenant_storage_objects'];
    $verify = function () use ($tables, $db, $ledger, $names) {
        foreach ($tables as $table) {
            if ($db->table($table)->exists() || (int) $db->selectOne("SELECT count(*) AS total FROM pg_class c
                CROSS JOIN LATERAL aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) acl
                WHERE c.oid=to_regclass(?) AND acl.grantee=0", [$table])->total !== 0) {
                throw new LogicException('Expected private, empty tables.');
            }
        }
        if ((clone $ledger)->whereIn('migration', $names)->count() !== count($names)) {
            throw new LogicException('Missing migration ledger entries.');
        }
    };
    if ($mode === 'verify') {
        $verify();
        echo "Private empty tables and migration ledger verified.\n";
        exit(0);
    }
    if ($mode === 'dump') {
        $host = $db->getConfig('host');
        $user = $db->getConfig('username');
        $port = (string) $db->getConfig('port');
        $password = $db->getConfig('password');
        if (! is_string($host) || ! preg_match('/^[a-z0-9-]+\.site4now\.net$/D', $host)
            || ! is_string($user) || ! preg_match('/^[a-z0-9_]+$/D', $user)
            || ! ctype_digit($port) || ! is_string($password) || preg_match('/[\r\n]/', $password)) {
            throw new LogicException('Unexpected database connection format.');
        }
        $proc = proc_open(['docker', 'run', '--rm', '--network', 'host', '--read-only', '-i', '--entrypoint', 'sh', 'postgres:18-alpine',
            '-ec', 'IFS= read -r PGPASSWORD; export PGPASSWORD PGSSLMODE=verify-full PGSSLROOTCERT=/etc/ssl/certs/ca-certificates.crt; exec pg_dump "$@"',
            'sh', '-h', $host, '-p', $port, '-U', $user, '-d', $db->getDatabaseName(),
            '--schema=clubora', '--no-owner', '--no-acl', '--format=custom', '--compress=1', '--lock-wait-timeout=5s'],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (! is_resource($proc)) {
            throw new LogicException('Database backup could not start.');
        }
        fwrite($pipes[0], $password."\n");
        fclose($pipes[0]);
        exit(proc_close($proc));
    }
    $db->transaction(function () use ($mode, $db, $ledger, $names, $tables, $verify, $release) {
        $db->statement("SET LOCAL lock_timeout TO '5s'");
        $db->statement("SET LOCAL statement_timeout TO '30s'");
        if ($mode === 'migrate') {
            $db->statement('LOCK TABLE clubora.migrations IN SHARE ROW EXCLUSIVE MODE');
        }
        if ((clone $ledger)->whereIn('migration', $names)->exists()) {
            throw new LogicException('Unexpected previous storage migration.');
        }
        foreach ($tables as $table) {
            if ($db->selectOne('SELECT to_regclass(?) AS relation', [$table])->relation !== null) {
                throw new LogicException('Unexpected previous storage table.');
            }
        }
        if ($mode === 'migrate') {
            $batch = (int) (clone $ledger)->max('batch') + 1;
            foreach ($names as $name) {
                (require "$release/database/clubunio/$name.php")->up();
                (clone $ledger)->insert(['migration' => $name, 'batch' => $batch]);
            }
            $verify();
        }
    });
    echo $mode === 'check' ? "Inactive storage runtime and untouched schema verified.\n" : "Two additive storage migrations applied atomically.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Storage release gate failed: '.get_class($error).($error instanceof LogicException ? ': '.$error->getMessage() : '').PHP_EOL);
    exit(1);
}
