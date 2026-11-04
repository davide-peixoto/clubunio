<?php
// Fixed additive DEV schema. No generic migrator, refresh, existing-row changes or provider calls.
use Illuminate\Support\Facades\{Crypt, DB};

try {
    $release = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-functional-v1';
    $previous = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-modules-performance-v1';
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__FILE__) !== $release.'/deploy/prepare-chat-experience-schema.php') throw new LogicException('Fixed Linux release required');
    $arguments = array_slice($argv, 1);
    $export = $arguments === ['--export'];
    if (!$export && (count($arguments) !== 2 || $arguments[0] !== '--apply' || !str_starts_with($arguments[1], '--backup-file='))) throw new LogicException('Explicit export or apply with backup required');
    require $release.'/vendor/autoload.php';
    $app = require $release.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (realpath(base_path()) !== $release || realpath('/var/www/svs-laravel/current') !== $previous
        || config('app.url') !== 'https://dev.clubunio.ch' || $db->getDriverName() !== 'pgsql'
        || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || $db->transactionLevel() !== 0) throw new LogicException('Exact inactive DEV release and standalone database required');
    foreach (['clubunio-mobile.ai.enabled','clubunio-mobile.training_ai.enabled','clubunio-mobile-push.enabled',
        'clubunio-mobile-push.delivery_enabled','clubunio-mobile.public_identity.mail_enabled',
        'clubunio-mobile.keybox.issuance_enabled','clubunio-mobile.keybox.provider_enabled'] as $flag) {
        if (config($flag, false)) throw new LogicException('External operations must remain closed');
    }
    $name = '2026_11_04_001100_chat_experience';
    $migration = $release.'/database/clubunio/'.$name.'.php';
    $expectedHash = '7e9d21a6d06dc56225890fc73c4c5e640643a5d901e1114bb92a9f1c19787381';
    $sourceGuard = function () use ($migration, $expectedHash): void {
        if (is_link($migration) || !hash_equals($expectedHash, hash_file('sha256', $migration))) throw new LogicException('Fixed migration source required');
    };
    $fresh = function () use ($db, $name): void {
        foreach (['chat_preferences','chat_reactions'] as $table) if ($db->selectOne('SELECT to_regclass(?) AS relation', ['clubora.'.$table])->relation !== null) throw new LogicException('Partial/applied schema requires review');
        if ($db->table('clubora.migrations')->where('migration', $name)->exists()) throw new LogicException('Existing ledger requires review');
    };
    $parents = ['tenants','accounts','chat_messages','migrations'];
    $snapshot = function () use ($db, $parents): array {
        $data = [];
        foreach ($parents as $table) {
            $rows = $db->table('clubora.'.$table)->orderBy('id')->limit(20001)->get()->all();
            if (count($rows) > 20000) throw new LogicException('Parent inventory exceeds reviewed backup bound');
            $relation = 'clubora.'.$table;
            $data[$table] = [
                'rows' => $rows,
                'columns' => $db->select("SELECT attnum,attname,format_type(atttypid,atttypmod) AS type,attnotnull,attidentity,attgenerated,pg_get_expr(d.adbin,d.adrelid) AS default_expression FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum", [$relation]),
                'constraints' => $db->select('SELECT conname,contype,convalidated,pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname', [$relation]),
                'indices' => $db->select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='clubora' AND tablename=? ORDER BY indexname", [$table]),
            ];
        }
        return $data;
    };
    $json = fn ($data) => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $directory = '/var/www/svs-laravel/shared/chat-experience-backups';
    if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700 || fileowner($directory) !== posix_geteuid()) throw new LogicException('Private owned backup directory required');
    $sourceGuard();
    if ($export) {
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $db->statement("SET LOCAL statement_timeout TO '15s'");
            $fresh();
            $record = ['release' => $release, 'migration_hash' => $expectedHash, 'created_at' => time(), 'parents' => $snapshot()];
            $bytes = Crypt::encryptString($json($record));
            $file = $directory.'/before-1100-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(16)).'.cuenc';
            umask(0077);
            $handle = fopen($file, 'x+b');
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) throw new LogicException('Exclusive backup creation failed');
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) throw new LogicException('Backup not durably written');
            } finally { fclose($handle); }
            if (!hash_equals(hash('sha256', $json($record)), hash('sha256', Crypt::decryptString(file_get_contents($file))))) throw new LogicException('Encrypted backup readback failed');
            echo $file.PHP_EOL;
        } finally { $db->rollBack(); }
        exit(0);
    }
    $file = substr($arguments[1], 14);
    if (!preg_match('#^'.preg_quote($directory, '#').'/before-1100-[0-9]{14}-[a-f0-9]{32}\.cuenc$#D', $file)
        || !is_file($file) || is_link($file) || realpath($file) !== $file || (fileperms($file) & 0777) !== 0600
        || fileowner($file) !== posix_geteuid() || lstat($file)['nlink'] !== 1 || filesize($file) > 100000000) throw new LogicException('Explicit private regular bounded backup required');
    $backup = json_decode(Crypt::decryptString(file_get_contents($file)), false, 512, JSON_THROW_ON_ERROR);
    if ($backup->release !== $release || $backup->migration_hash !== $expectedHash || time() - $backup->created_at > 1800 || $backup->created_at > time()) throw new LogicException('Fresh matching verified backup required');
    $db->transaction(function () use ($db, $sourceGuard, $fresh, $parents, $snapshot, $backup, $json, $migration, $name): void {
        $db->statement("SET LOCAL lock_timeout TO '3s'");
        $db->statement("SET LOCAL statement_timeout TO '30s'");
        $db->select('SELECT pg_advisory_xact_lock(202611041100)');
        foreach ($parents as $table) $db->statement('LOCK TABLE clubora.'.$table.' IN SHARE MODE');
        $sourceGuard(); $fresh();
        $before = $snapshot();
        if ($json($before) !== $json($backup->parents)) throw new LogicException('Parents changed since verified backup; export again');
        (require $migration)->up();
        if ($json($snapshot()) !== $json($before)) throw new LogicException('Additive schema changed parent rows or definitions');
        foreach (['chat_preferences','chat_reactions'] as $table) {
            if ($db->table('clubora.'.$table)->exists()) throw new LogicException('New tables must be empty');
            $bad = $db->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid=to_regclass(?) AND NOT convalidated", ['clubora.'.$table]);
            if ((int) $bad->n !== 0) throw new LogicException('Unvalidated new constraint');
        }
        $db->table('clubora.migrations')->insert(['migration' => $name, 'batch' => (int) $db->table('clubora.migrations')->max('batch') + 1]);
        echo "Additive chat schema 1100 verified; existing data unchanged.\n";
    });
} catch (Throwable $error) { fwrite(STDERR, 'Chat schema: '.get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); }
