<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Additive assistant schema only; no imports, foundation run or existing role changes.
try {
    $release = '/var/www/svs-laravel/releases/20261106-clubunio-assistant-v1';
    $directory = '/var/www/svs-laravel/shared/assistant-backups';
    $migration = '2026_11_06_000100_backoffice_assistant';
    $tables = ['assistant_conversations', 'assistant_runs', 'assistant_actions'];
    $parents = ['tenants', 'accounts', 'tenant_accounts', 'migrations'];
    $mode = $argv[1] ?? '';
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__FILE__) !== $release.'/deploy/prepare-assistant-schema.php'
        || ! in_array($mode, ['--export', '--apply'], true) || count($argv) !== ($mode === '--export' ? 3 : 5)
        || ! preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) {
        throw new LogicException('Exact mode, release and manifest required');
    }
    $manifest = '/var/www/svs-laravel/shared/assistant-20261106/manifest';
    if (! hash_equals($argv[2], hash_file('sha256', $manifest))) {
        throw new LogicException('Manifest mismatch');
    }
    $found = false;
    foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $line) {
        if (! preg_match('#^([a-f0-9]{64})  ([A-Za-z0-9_./-]+)$#D', $line, $m) || str_contains($m[2], '..')
            || realpath($release.'/'.$m[2]) !== $release.'/'.$m[2]
            || ! hash_equals($m[1], hash_file('sha256', $release.'/'.$m[2]))) {
            throw new LogicException('Source drift');
        }
        $found = $found || $m[2] === 'database/clubunio/'.$migration.'.php';
    }
    if (! $found) {
        throw new LogicException('Migration missing from manifest');
    }
    require $release.'/vendor/autoload.php';
    $app = require $release.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (realpath(base_path()) !== $release || realpath('/var/www/svs-laravel/current') === $release
        || config('app.url') !== 'https://dev.clubunio.ch' || $db->getDriverName() !== 'pgsql'
        || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || config('clubunio-assistant.provider_enabled')
        || config('clubunio-assistant.privacy_reviewed')) {
        throw new LogicException('Inactive ClubUnio with provider off required');
    }
    if (! is_dir($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700
        || fileowner($directory) !== posix_geteuid()) {
        throw new LogicException('Owned private backup directory required');
    }
    $json = fn ($v) => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $snapshot = function () use ($parents, $db): array {
        $out = [];
        foreach ($parents as $table) {
            $out[$table] = $db->table('clubora.'.$table)->orderBy('id')->limit(20001)->get()->all();
            if (count($out[$table]) > 20000) {
                throw new LogicException('Backup bound exceeded');
            }
        }

        return $out;
    };
    $fresh = function () use ($db, $tables, $migration): void {
        if ($db->table('clubora.migrations')->where('migration', $migration)->exists()) {
            throw new LogicException('Already applied; inspect, do not replay');
        }
        foreach ($tables as $table) {
            if ($db->selectOne('SELECT to_regclass(?) AS relation', ['clubora.'.$table])->relation !== null) {
                throw new LogicException('Existing assistant schema');
            }
        }
    };
    $db->beginTransaction();
    try {
        $db->statement("SET LOCAL lock_timeout='3s'");
        $db->statement("SET LOCAL statement_timeout='30s'");
        if ($mode === '--apply') {
            $db->select('SELECT pg_advisory_xact_lock(20261106000100)');
            foreach ($parents as $table) {
                $db->statement('LOCK TABLE clubora.'.$table.' IN SHARE ROW EXCLUSIVE MODE');
            }
        }
        $fresh();
        $before = $json($snapshot());
        if ($mode === '--export') {
            $plain = $json(['format' => 'assistant-v1', 'manifest' => $argv[2], 'created_at' => time(), 'parents' => $before]);
            $bytes = Crypt::encryptString($plain);
            $path = $directory.'/before-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(8)).'.cuenc';
            umask(0077);
            $handle = fopen($path, 'x+b');
            if (! $handle || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new LogicException('Backup failed');
            }
            fclose($handle);
            if (! hash_equals(hash('sha256', $plain), hash('sha256', Crypt::decryptString(file_get_contents($path))))) {
                throw new LogicException('Backup readback failed');
            }
            $db->rollBack();
            echo $json(['backup_file' => $path, 'backup_sha256' => hash_file('sha256', $path)]).PHP_EOL;
        } else {
            $path = $argv[3];
            if (! preg_match('#^'.preg_quote($directory, '#').'/before-[0-9]{14}-[a-f0-9]{16}\.cuenc$#D', $path)
                || ! is_file($path) || realpath($path) !== $path || is_link($path) || (fileperms($path) & 0777) !== 0600
                || filesize($path) > 64000000 || fileowner($path) !== posix_geteuid()
                || ! hash_equals($argv[4], hash_file('sha256', $path))) {
                throw new LogicException('Exact owned backup/hash required');
            }
            $backup = json_decode(Crypt::decryptString(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
            if ($backup['format'] !== 'assistant-v1' || $backup['manifest'] !== $argv[2] || $backup['parents'] !== $before
                || $backup['created_at'] > time() || time() - $backup['created_at'] > 1800) {
                throw new LogicException('Fresh matching snapshot required');
            }
            (require $release.'/database/clubunio/'.$migration.'.php')->up();
            if ($json($snapshot()) !== $before) {
                throw new LogicException('Parent data changed');
            }
            foreach ($tables as $table) {
                if ($db->table('clubora.'.$table)->exists()) {
                    throw new LogicException('New tables must be empty');
                }
                $db->statement('REVOKE ALL ON clubora.'.$table.' FROM PUBLIC');
            }
            $db->table('clubora.migrations')->insert(['migration' => $migration, 'batch' => (int) $db->table('clubora.migrations')->max('batch') + 1]);
            $db->commit();
            echo "Assistant schema applied; existing roles and domain data unchanged.\n";
        }
    } catch (Throwable $e) {
        if ($db->transactionLevel()) {
            $db->rollBack();
        } throw $e;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Assistant migration stopped: '.($e instanceof LogicException ? $e->getMessage() : get_class($e)).PHP_EOL);
    exit(1);
}
