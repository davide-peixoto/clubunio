<?php

// DEV-only, reviewed additive88/89. No refresh/import/down or PHPMaker access.
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || ! str_starts_with(realpath(base_path()), '/var/www/svs-laravel/releases/')
        || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora' || config('app.url') !== 'https://dev.clubunio.ch') {
        throw new LogicException('Reviewed DEV release required');
    }
    $apply = in_array('--apply', $argv, true);
    $backup = in_array('--backup', $argv, true);
    if (! $apply && ! $backup && ! in_array('--verify-backup', $argv, true)) { throw new LogicException('Explicit stage required'); }
    if (($apply || $backup) && (config('clubunio-mobile.native_expenses_enabled') || config('clubunio-mobile.native_documents_enabled'))) { throw new LogicException('Follow-up writes must stay closed'); }
    $directory = '/var/www/svs-laravel/shared/backups';
    $file = $directory.'/mobile-before-88-89-20261103.cuenc';
    $tables = ['mobile_expense_receipts', 'mobile_expense_commands', 'mobile_member_receipts'];
    $migrations = ['2026_11_03_000088_mobile_expense_receipts', '2026_11_03_000089_mobile_member_receipts'];
    $schema = $db->getSchemaBuilder();
    $fresh = function () use ($db, $schema, $tables, $migrations) {
        foreach ($tables as $table) { if ($schema->hasTable('clubora.'.$table)) { throw new LogicException('Follow-up table already installed'); } }
        if ($db->table('clubora.migrations')->whereIn('migration', $migrations)->exists()) { throw new LogicException('Migration state changed'); }
    };
    $indices = fn () => $db->select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='clubora' AND tablename='legacy_material_receipts' ORDER BY indexname");
    $digest = function () use ($db) {
        $hash = hash_init('sha256'); $count = 0;
        foreach ($db->table('clubora.legacy_material_receipts')->orderBy('id')->cursor() as $row) {
            hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR)."\n"); $count++;
        }
        return ['count' => $count, 'sha256' => hash_final($hash)];
    };
    if ($backup) {
        $fresh();
        if (is_link($directory) || is_link($file) || file_exists($file)) { throw new LogicException('Exclusive private backup destination required'); }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) { throw new LogicException('Private backup directory unavailable'); }
        if (realpath($directory) !== $directory) { throw new LogicException('Unexpected backup directory'); }
        umask(0077); $handle = fopen($file, 'x');
        if (! $handle) { throw new LogicException('Cannot create backup'); }
        $write = function (array $record) use ($handle) {
            $line = Illuminate\Support\Facades\Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR))."\n";
            if (fwrite($handle, $line) !== strlen($line)) { throw new LogicException('Incomplete encrypted backup'); }
        };
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $db->statement("SET LOCAL statement_timeout TO '30s'");
            $write(['kind' => 'header', 'format' => 'mobile-88-89-v1', 'database' => $db->getDatabaseName(), 'created_at' => gmdate('c'),
                'table' => 'legacy_material_receipts', 'columns' => $schema->getColumns('clubora.legacy_material_receipts'), 'indices' => $indices(),
                'ledger' => $db->table('clubora.migrations')->orderBy('id')->get()->all()]);
            $hash = hash_init('sha256'); $count = 0;
            foreach ($db->table('clubora.legacy_material_receipts')->orderBy('id')->cursor() as $row) {
                $row = (array) $row; hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n"); $count++;
                $write(['kind' => 'row', 'row' => $row]);
            }
            $write(['kind' => 'footer', 'count' => $count, 'sha256' => hash_final($hash)]);
            fflush($handle); if (function_exists('fsync')) { fsync($handle); }
        } finally { $db->rollBack(); fclose($handle); }
        chmod($file, 0600);
    }
    if (! is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0) { throw new LogicException('Private encrypted backup required'); }
    $handle = fopen($file, 'r'); $header = $footer = null; $count = 0; $hash = hash_init('sha256');
    try {
        while (($line = fgets($handle)) !== false) {
            $record = json_decode(Illuminate\Support\Facades\Crypt::decryptString(rtrim($line, "\r\n")), true, 512, JSON_THROW_ON_ERROR);
            if ($footer) { throw new LogicException('Trailing backup records'); }
            if (($record['kind'] ?? null) === 'header' && ! $header && $count === 0) { $header = $record; }
            elseif (($record['kind'] ?? null) === 'row' && $header) { hash_update($hash, json_encode($record['row'], JSON_THROW_ON_ERROR)."\n"); $count++; }
            elseif (($record['kind'] ?? null) === 'footer' && $header) { $footer = $record; }
            else { throw new LogicException('Invalid backup record sequence'); }
        }
    } finally { fclose($handle); }
    if (! $header || ! $footer || $header['format'] !== 'mobile-88-89-v1' || $header['database'] !== $db->getDatabaseName()
        || $header['table'] !== 'legacy_material_receipts' || $footer['count'] !== $count || ! hash_equals($footer['sha256'], hash_final($hash))) {
        throw new LogicException('Backup scope/digest mismatch');
    }
    if ($apply) {
        if (realpath('/var/www/svs-laravel/current') !== realpath(base_path()) || basename(base_path()) !== '20261103-clubunio-native-followups-foundation-v3'
            || time() - strtotime($header['created_at']) > 3600) { throw new LogicException('Active reviewed foundation and fresh backup required'); }
        $db->transaction(function () use ($db, $schema, $fresh, $indices, $digest, $header, $footer, $migrations, $tables) {
            $db->statement("SET LOCAL lock_timeout TO '5s'"); $db->statement("SET LOCAL statement_timeout TO '20s'");
            if (! $db->selectOne("SELECT pg_try_advisory_xact_lock(hashtext('clubunio-mobile-88-89')) AS locked")->locked) { throw new LogicException('Concurrent follow-up migration'); }
            $fresh();
            // All existing FK parents and the archive index source must be idle for this brief DDL.
            $parents = ['tenants','accounts','tenant_accounts','members','document_types','member_documents','expenses','expense_lines','document_uploads','legacy_material_receipts','migrations'];
            $db->statement('LOCK TABLE '.implode(', ', array_map(fn ($name) => 'clubora.'.$name, $parents)).' IN SHARE ROW EXCLUSIVE MODE NOWAIT');
            if ($db->table('clubora.migrations')->orderBy('id')->get()->toJson() !== json_encode($header['ledger'], JSON_THROW_ON_ERROR)
                || json_encode($schema->getColumns('clubora.legacy_material_receipts'), JSON_THROW_ON_ERROR) !== json_encode($header['columns'], JSON_THROW_ON_ERROR)
                || json_encode($indices(), JSON_THROW_ON_ERROR) !== json_encode($header['indices'], JSON_THROW_ON_ERROR)
                || $digest() !== ['count' => $footer['count'], 'sha256' => $footer['sha256']]) { throw new LogicException('Schema/data/ledger changed after backup'); }
            $batch = (int) $db->table('clubora.migrations')->max('batch') + 1;
            foreach ($migrations as $migration) {
                (require database_path('clubunio/'.$migration.'.php'))->up();
                $db->table('clubora.migrations')->insert(['migration' => $migration, 'batch' => $batch]);
            }
            foreach ($tables as $table) { if (! $schema->hasTable('clubora.'.$table) || $db->table('clubora.'.$table)->exists()) { throw new LogicException('Unexpected new table state'); } }
            if ($digest() !== ['count' => $footer['count'], 'sha256' => $footer['sha256']]
                || json_encode($schema->getColumns('clubora.legacy_material_receipts'), JSON_THROW_ON_ERROR) !== json_encode($header['columns'], JSON_THROW_ON_ERROR)) {
                throw new LogicException('Archive fields changed');
            }
            $after = collect($indices())->keyBy('indexname');
            foreach ($header['indices'] as $old) { if (($after[$old['indexname']]->indexdef ?? null) !== $old['indexdef']) { throw new LogicException('Existing archive index changed'); } }
            $extra = $after->keys()->diff(array_column($header['indices'], 'indexname'))->values()->all();
            if (array_diff($extra, ['mobile_member_receipt_archive_source_uq']) || ! $after->has('mobile_member_receipt_archive_source_uq')) { throw new LogicException('Unexpected archive index delta'); }
        });
        echo "Applied only88/89 atomically; archive fields and existing indexes unchanged.\n";
    }
    echo 'Verified private archive/ledger backup SHA256 '.hash_file('sha256', $file)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Follow-up schema preparation failed: '.get_class($error)." (record contents suppressed).\n"); exit(1);
}
