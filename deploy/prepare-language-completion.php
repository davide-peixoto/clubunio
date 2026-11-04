<?php

// Fixed additive language migration with encrypted backup and unchanged-row checks.
use Illuminate\Support\Facades\{Crypt, DB};

try {
    $release = '/var/www/svs-laravel/releases/20261104-clubunio-languages-complete';
    $previous = '/var/www/svs-laravel/releases/20261104-clubunio-languages';
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__FILE__) !== $release.'/deploy/prepare-language-completion.php') {
        throw new LogicException('Fixed inactive ClubUnio release required');
    }
    require $release.'/vendor/autoload.php';
    $app = require $release.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (realpath('/var/www/svs-laravel/current') !== $previous || config('app.url') !== 'https://dev.clubunio.ch'
        || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new LogicException('Unexpected environment');
    }
    $names = ['2026_11_04_001300_lookup_languages', '2026_11_04_001400_message_template_languages'];
    $tables = ['message_templates', 'message_template_revisions'];
    $hashes = [];
    foreach ($names as $name) { $hashes[$name] = hash_file('sha256', $release.'/database/clubunio/'.$name.'.php'); }
    $snapshot = function () use ($db, $tables): array {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $db->table('clubora.'.$table)->orderBy('id')->limit(20001)->get()->all();
            if (count($result[$table]) > 20000) { throw new LogicException('Backup bound exceeded'); }
        }
        return $result;
    };
    $json = fn ($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $directory = '/var/www/svs-laravel/shared/language-backups';
    if (! is_dir($directory) || is_link($directory) || (fileperms($directory) & 0777) !== 0700 || fileowner($directory) !== posix_geteuid()) {
        throw new LogicException('Private owned backup directory required');
    }
    if (array_slice($argv, 1) === ['--export']) {
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $payload = ['created_at' => time(), 'hashes' => $hashes, 'rows' => $snapshot()];
            $bytes = Crypt::encryptString($json($payload));
            umask(0077);
            $file = $directory.'/before-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(12)).'.cuenc';
            $handle = fopen($file, 'x+b');
            if (! $handle) { throw new LogicException('Backup creation failed'); }
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) { throw new LogicException('Backup write failed'); }
            } finally { fclose($handle); }
            if (Crypt::decryptString(file_get_contents($file)) !== $json($payload)) { throw new LogicException('Backup readback failed'); }
            echo $file.PHP_EOL;
        } finally { $db->rollBack(); }
        exit(0);
    }
    $file = $argv[2] ?? '';
    if (count($argv) !== 3 || $argv[1] !== '--apply' || ! preg_match('#^'.preg_quote($directory, '#').'/before-[0-9]{14}-[a-f0-9]{24}\.cuenc$#D', $file)
        || ! is_file($file) || is_link($file) || realpath($file) !== $file || (fileperms($file) & 0777) !== 0600 || fileowner($file) !== posix_geteuid()) {
        throw new LogicException('Explicit verified backup required');
    }
    $backup = json_decode(Crypt::decryptString(file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
    if ($backup['hashes'] !== $hashes || time() - $backup['created_at'] > 1800) { throw new LogicException('Fresh matching backup required'); }
    $db->transaction(function () use ($db, $tables, $names, $release, $snapshot, $json, $backup): void {
        $db->statement("SET LOCAL lock_timeout = '5s'");
        $db->statement("SET LOCAL statement_timeout = '60s'");
        $db->select('SELECT pg_advisory_xact_lock(202611041300)');
        foreach ($tables as $table) { $db->statement('LOCK TABLE clubora.'.$table.' IN SHARE ROW EXCLUSIVE MODE'); }
        if ($json($snapshot()) !== $json($backup['rows'])) { throw new LogicException('Templates changed since backup; export again'); }
        foreach ($names as $name) {
            if ($db->table('clubora.migrations')->where('migration', $name)->exists()) { throw new LogicException('Migration already applied; review before retry'); }
            (require $release.'/database/clubunio/'.$name.'.php')->up();
            $db->table('clubora.migrations')->insert(['migration' => $name, 'batch' => (int) $db->table('clubora.migrations')->max('batch') + 1]);
        }
        foreach ($backup['rows'] as $table => $rows) {
            foreach ($rows as $row) {
                $actual = $db->table('clubora.'.$table)->where('id', $row['id'])->first();
                if ($json($actual) !== $json($row)) { throw new LogicException('Existing template/revision changed'); }
            }
        }
        echo 'Language migrations applied; existing templates and revisions unchanged.'.PHP_EOL;
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Language migration failed: '.get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
