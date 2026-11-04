<?php

// Reviewed DEV-only additive100/200/300. Export, verification and apply are separate.
// No down, refresh, imports, provider calls or process termination.
use Illuminate\Support\Facades\{Crypt, DB};

try {
    $stages = array_values(array_filter(array_slice($argv, 1), fn ($argument) => in_array($argument, ['--export', '--verify-backup', '--apply'], true)));
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || count($stages) !== 1) throw new LogicException('Explicit reviewed Linux CLI stage required');
    $stage = $stages[0]; $fileArgument = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === $stage) continue;
        if (str_starts_with($argument, '--backup-file=') && $fileArgument === null) $fileArgument = substr($argument, 14);
        else throw new LogicException('Unexpected or duplicate stage argument');
    }
    if (($stage === '--export') !== ($fileArgument === null)) throw new LogicException('Export creates a new backup; verify/apply require its explicit path');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $release = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-packages-foundation-v3';
    $directory = '/var/www/svs-laravel/shared/backups';
    $db = DB::connection('clubunio');
    $guard = function () use ($db, $release): void {
        if (realpath(base_path()) !== $release || realpath('/var/www/svs-laravel/current') !== $release
            || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
            || config('app.url') !== 'https://dev.clubunio.ch' || ! config('clubunio-mobile.enabled')) throw new LogicException('Exact active DEV foundation required');
        foreach (['native_training_enabled', 'native_sponsor_enabled', 'native_registrations_enabled'] as $flag) {
            if (config('clubunio-mobile.'.$flag, false)) throw new LogicException('Package writes must stay closed');
        }
    };
    $guard();
    if ($db->transactionLevel() !== 0) throw new LogicException('Standalone schema operation required');
    $migrations = [
        '2026_11_03_000100_mobile_training_shares' => '85a83c20bb4804cf2997d3159f85a5ad6045c1f021e62001c8a50f3136f3bb64',
        '2026_11_03_000200_mobile_sponsor_commands' => '5bb0f80d881623cd3d6e1cfc8c02e85f49c19651b42bbf69f6c69d1163edd625',
        '2026_11_03_000300_mobile_registration_requests' => 'b3bfc921480baa7bd324e0eac88ed6861681ea834c49e087e3dff848ce6cbc1e',
    ];
    $parents = ['tenants', 'tenant_accounts', 'training_plans', 'team_seasons', 'sponsor_run_events', 'sponsor_run_participants', 'registrations', 'migrations'];
    $newTables = [
        'mobile_training_plan_shares' => ['tenant_id', 'plan_id', 'team_season_id', 'shared_by', 'created_at'],
        'mobile_sponsor_commands' => ['tenant_id', 'account_id', 'command_key', 'operation', 'fingerprint', 'event_id', 'participant_id', 'result_revision', 'created_at'],
        'mobile_registration_requests' => ['tenant_id', 'account_id', 'request_key', 'payload_sha256', 'registration_id', 'created_at'],
    ];
    $checkSources = function () use ($migrations): void {
        foreach ($migrations as $name => $hash) {
            $path = database_path('clubunio/'.$name.'.php');
            if (! is_file($path) || is_link($path) || ! hash_equals($hash, hash_file('sha256', $path))) throw new LogicException('Fixed migration source changed');
        }
    };
    $checkSources();
    $fresh = function () use ($db, $newTables, $migrations): void {
        foreach (array_keys($newTables) as $table) {
            if ($db->selectOne('SELECT to_regclass(?) AS relation', ['clubora.'.$table])->relation !== null) throw new LogicException('New relation already exists; partial state requires review');
        }
        if ($db->table('clubora.migrations')->whereIn('migration', array_keys($migrations))->exists()) throw new LogicException('Package ledger already exists; partial state requires review');
    };
    $session = function () use ($db): void {
        $db->statement("SET LOCAL application_name TO 'clubunio-packages-100-200-300-schema'");
        $db->statement("SET LOCAL lock_timeout TO '2s'");
        $db->statement("SET LOCAL statement_timeout TO '15s'");
        $db->statement("SET LOCAL TIME ZONE 'UTC'");
        $db->statement("SET LOCAL DateStyle TO 'ISO, YMD'");
        $db->statement("SET LOCAL bytea_output TO 'hex'");
        $db->statement('SET LOCAL extra_float_digits TO 3');
        $db->statement('SET LOCAL search_path TO pg_catalog, clubora');
    };
    $canonical = fn (mixed $record): string => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $metadata = function (string $table) use ($db): array {
        $relation = 'clubora.'.$table;
        $columns = $db->select("SELECT a.attnum,a.attname,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attidentity,a.attgenerated,
            pg_get_expr(d.adbin,d.adrelid) AS default_expression,co.collname AS collation
            FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
            LEFT JOIN pg_collation co ON co.oid=a.attcollation
            WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum", [$relation]);
        if (! $columns || ! in_array('id', array_column($columns, 'attname'), true)) throw new LogicException('Expected fixed parent columns unavailable');
        return ['columns' => $columns,
            'indices' => $db->select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='clubora' AND tablename=? ORDER BY indexname", [$table]),
            'constraints' => $db->select('SELECT conname,contype,convalidated,condeferrable,condeferred,pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname', [$relation])];
    };
    // A server-side cursor fetches bounded batches; parent rows never accumulate in PHP memory.
    $rows = function (string $table, callable $consume, bool $withoutNewLedger = false) use ($db, $migrations): void {
        $filter = $withoutNewLedger ? " WHERE migration NOT IN ('".implode("','", array_keys($migrations))."')" : '';
        $db->unprepared('DECLARE cu_packages_rows NO SCROLL CURSOR FOR SELECT * FROM clubora.'.$table.$filter.' ORDER BY id');
        try {
            do {
                $batch = $db->select('FETCH FORWARD 128 FROM cu_packages_rows');
                foreach ($batch as $row) $consume((array) $row);
            } while (count($batch) === 128);
        } finally { $db->statement('CLOSE cu_packages_rows'); }
    };
    $digest = function (string $table, bool $withoutNewLedger = false) use ($rows, $canonical): array {
        $hash = hash_init('sha256'); $count = 0;
        $rows($table, function (array $row) use ($hash, &$count, $canonical): void { hash_update($hash, $canonical($row)."\n"); $count++; }, $withoutNewLedger);
        return ['count' => $count, 'sha256' => hash_final($hash)];
    };
    if (! is_dir($directory) && ($stage !== '--export' || ! mkdir($directory, 0700, true))) throw new LogicException('Private backup directory unavailable');
    if (is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0077) !== 0) throw new LogicException('Exact private backup directory required');
    $file = $stage === '--export' ? $directory.'/mobile-before-100-200-300-20261104-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(16)).'.cuenc' : $fileArgument;
    if (! preg_match('#^'.preg_quote($directory, '#').'/mobile-before-100-200-300-20261104-[0-9]{14}-[a-f0-9]{32}\.cuenc$#D', $file)) throw new LogicException('Fixed private backup scope required');
    if ($stage === '--export') {
        umask(0077);
        if (file_exists($file) || is_link($file)) throw new LogicException('Exclusive new backup required');
        $handle = fopen($file, 'x+b');
        if (! $handle || ! chmod($file, 0600) || ! flock($handle, LOCK_EX | LOCK_NB)) throw new LogicException('Cannot create exclusive private backup');
        $chain = hash_init('sha256');
        $write = function (array $record, bool $includeInChain = true) use ($handle, $chain, $canonical): void {
            $json = $canonical($record);
            if ($includeInChain) hash_update($chain, $json."\n");
            $line = Crypt::encryptString($json)."\n";
            if (fwrite($handle, $line) !== strlen($line)) throw new LogicException('Incomplete encrypted backup');
        };
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); $session(); $fresh();
            $write(['kind' => 'header', 'format' => 'clubunio-packages-100-200-300-v1', 'created_at' => gmdate('c'),
                'database' => $db->getDatabaseName(), 'release' => $release, 'migrations' => $migrations, 'parents' => $parents]);
            foreach ($parents as $table) {
                $write(['kind' => 'table', 'table' => $table, 'metadata' => $metadata($table)]);
                $hash = hash_init('sha256'); $count = 0;
                $rows($table, function (array $row) use ($write, $table, $canonical, $hash, &$count): void {
                    hash_update($hash, $canonical($row)."\n"); $count++;
                    $write(['kind' => 'row', 'table' => $table, 'data' => $row]);
                });
                $write(['kind' => 'table-end', 'table' => $table, 'count' => $count, 'sha256' => hash_final($hash)]);
            }
            $write(['kind' => 'footer', 'sha256' => hash_final($chain)], false);
            if (! fflush($handle) || ! fsync($handle)) throw new LogicException('Encrypted backup not durably flushed');
        } finally { $db->rollBack(); flock($handle, LOCK_UN); fclose($handle); }
    }
    clearstatcache(true, $file);
    if (! is_file($file) || is_link($file) || realpath($file) !== $file || (fileperms($file) & 0777) !== 0600
        || ! function_exists('posix_geteuid') || fileowner($file) !== posix_geteuid()) throw new LogicException('Owned regular0600 encrypted backup required');
    $stat = lstat($file);
    if (! $stat || $stat['nlink'] !== 1) throw new LogicException('Single regular backup inode required');
    $handle = fopen($file, 'rb');
    $opened = $handle ? fstat($handle) : false;
    if (! $opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) throw new LogicException('Backup inode changed');
    if (! $handle || ! flock($handle, LOCK_SH | LOCK_NB)) throw new LogicException('Cannot lock private backup for verification');
    $header = $footer = null; $current = null; $summaries = []; $chain = hash_init('sha256'); $rowHash = null; $rowCount = 0;
    try {
        while (($line = fgets($handle, 67108865)) !== false) {
            if (! str_ends_with($line, "\n") || strlen($line) > 67108864 || $footer !== null) throw new LogicException('Truncated, oversized or trailing backup record');
            $record = json_decode(Crypt::decryptString(rtrim($line, "\r\n")), true, 512, JSON_THROW_ON_ERROR);
            $kind = $record['kind'] ?? null;
            if ($kind !== 'footer') hash_update($chain, $canonical($record)."\n");
            if ($kind === 'header' && $header === null && ! $summaries && $current === null) $header = $record;
            elseif ($kind === 'table' && $header !== null && $current === null && count($summaries) < count($parents)
                && ($record['table'] ?? null) === $parents[count($summaries)]) {
                $current = $record; $rowHash = hash_init('sha256'); $rowCount = 0;
            } elseif ($kind === 'row' && $current !== null && ($record['table'] ?? null) === $current['table'] && is_array($record['data'] ?? null)) {
                hash_update($rowHash, $canonical($record['data'])."\n"); $rowCount++;
            } elseif ($kind === 'table-end' && $current !== null && ($record['table'] ?? null) === $current['table']) {
                if (($record['count'] ?? null) !== $rowCount || ! hash_equals($record['sha256'] ?? '', hash_final($rowHash))) throw new LogicException('Encrypted parent count/digest mismatch');
                $summaries[$current['table']] = ['metadata' => $current['metadata'], 'count' => $rowCount, 'sha256' => $record['sha256']]; $current = null;
            } elseif ($kind === 'footer' && $current === null && count($summaries) === count($parents)) $footer = $record;
            else throw new LogicException('Invalid encrypted backup sequence');
        }
        if (! feof($handle) || ! $header || ! $footer || $header['format'] !== 'clubunio-packages-100-200-300-v1'
            || $header['database'] !== $db->getDatabaseName() || $header['release'] !== $release || $header['migrations'] !== $migrations
            || $header['parents'] !== $parents || ! hash_equals($footer['sha256'] ?? '', hash_final($chain))) throw new LogicException('Backup identity/source/chain mismatch');
        $created = strtotime($header['created_at']);
        $assertBackupFresh = function () use ($created): void {
            if (! $created || $created > time() + 5 || time() - $created > 3600) throw new LogicException('Verified backup must be no older than one hour');
        };
        $assertBackupFresh();
        $guard(); $checkSources();
        $verifyParents = function (bool $after = false) use ($parents, $summaries, $metadata, $digest, $canonical): void {
            foreach ($parents as $table) {
                if ($canonical($metadata($table)) !== $canonical($summaries[$table]['metadata'])
                    || $digest($table, $after && $table === 'migrations') !== ['count' => $summaries[$table]['count'], 'sha256' => $summaries[$table]['sha256']]) throw new LogicException('Parent fields/indexes/constraints/rows or ledger changed');
            }
        };
        if ($stage !== '--apply') {
            $db->beginTransaction();
            try { $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); $session(); $fresh(); $verifyParents(); }
            finally { $db->rollBack(); }
        } else {
            $db->transaction(function () use ($db, $session, $fresh, $verifyParents, $guard, $checkSources, $migrations, $parents, $newTables, $assertBackupFresh): void {
                $session();
                $locked = $db->selectOne("SELECT CASE WHEN pg_try_advisory_xact_lock(hashtext('clubunio-mobile-packages-100-200-300')) THEN 1 ELSE 0 END AS locked");
                if ((int) $locked->locked !== 1) throw new LogicException('Concurrent package schema operation');
                $db->statement('LOCK TABLE '.implode(', ', array_map(fn ($table) => 'clubora.'.$table, $parents)).' IN SHARE ROW EXCLUSIVE MODE NOWAIT');
                $guard(); $checkSources(); $fresh(); $verifyParents(); $assertBackupFresh();
                $batch = (int) $db->table('clubora.migrations')->max('batch') + 1;
                foreach ($migrations as $name => $hash) {
                    (require database_path('clubunio/'.$name.'.php'))->up();
                    $db->table('clubora.migrations')->insert(['migration' => $name, 'batch' => $batch]);
                }
                $expectedFks = [
                    'mobile_training_plan_shares' => ['tenant_id|clubora|tenants|id', 'tenant_id,plan_id|clubora|training_plans|tenant_id,id', 'tenant_id,team_season_id|clubora|team_seasons|tenant_id,id', 'tenant_id,shared_by|clubora|tenant_accounts|tenant_id,account_id'],
                    'mobile_sponsor_commands' => ['tenant_id,account_id|clubora|tenant_accounts|tenant_id,account_id', 'tenant_id,event_id|clubora|sponsor_run_events|tenant_id,id', 'tenant_id,event_id,participant_id|clubora|sponsor_run_participants|tenant_id,event_id,id'],
                    'mobile_registration_requests' => ['tenant_id|clubora|tenants|id', 'tenant_id,account_id|clubora|tenant_accounts|tenant_id,account_id', 'tenant_id,registration_id|clubora|registrations|tenant_id,id'],
                ];
                foreach ($newTables as $table => $columns) {
                    if (! $db->getSchemaBuilder()->hasTable('clubora.'.$table) || $db->table('clubora.'.$table)->exists()
                        || $db->getSchemaBuilder()->getColumnListing('clubora.'.$table) !== $columns) throw new LogicException('Unexpected new table state');
                    $fks = $db->select("SELECT CASE WHEN c.convalidated THEN 1 ELSE 0 END AS validated, CASE WHEN c.condeferrable THEN 1 ELSE 0 END AS deferrable,c.confmatchtype,c.confupdtype,c.confdeltype,
                        array_to_string(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num ORDER BY k.ord),',') AS local_columns,
                        n.nspname AS parent_schema,p.relname AS parent_table,
                        array_to_string(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.num ORDER BY k.ord),',') AS parent_columns
                        FROM pg_constraint c JOIN pg_class p ON p.oid=c.confrelid JOIN pg_namespace n ON n.oid=p.relnamespace
                        WHERE c.conrelid=to_regclass(?) AND c.contype='f'", ['clubora.'.$table]);
                    $actual = [];
                    foreach ($fks as $fk) {
                        if ((int) $fk->validated !== 1 || (int) $fk->deferrable !== 0 || $fk->confmatchtype !== 's' || $fk->confupdtype !== 'a' || $fk->confdeltype !== 'a') throw new LogicException('Unexpected new FK policy');
                        $actual[] = $fk->local_columns.'|'.$fk->parent_schema.'|'.$fk->parent_table.'|'.$fk->parent_columns;
                    }
                    $expected = $expectedFks[$table]; sort($actual); sort($expected);
                    if ($actual !== $expected) throw new LogicException('New tenant FK scope mismatch');
                    $primary = $db->select("SELECT array_to_string(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num ORDER BY k.ord),',') AS columns
                        FROM pg_constraint c WHERE c.conrelid=to_regclass(?) AND c.contype='p'", ['clubora.'.$table]);
                    $expectedPrimary = match ($table) {
                        'mobile_training_plan_shares' => 'tenant_id,plan_id',
                        'mobile_sponsor_commands' => 'tenant_id,account_id,command_key',
                        'mobile_registration_requests' => 'tenant_id,account_id,request_key',
                    };
                    if (count($primary) !== 1 || $primary[0]->columns !== $expectedPrimary) throw new LogicException('Unexpected new tenant primary key');
                    $public = $db->selectOne("SELECT count(*) AS total FROM pg_class c CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl,acldefault('r',c.relowner))) acl WHERE c.oid=to_regclass(?) AND acl.grantee=0", ['clubora.'.$table]);
                    if ((int) $public->total !== 0) throw new LogicException('New table grants PUBLIC access');
                }
                $verifyParents(true);
                $ledger = $db->table('clubora.migrations')->whereIn('migration', array_keys($migrations))->orderBy('migration')->get();
                if ($ledger->count() !== 3 || $ledger->pluck('migration')->all() !== array_keys($migrations) || $ledger->contains(fn ($row) => (int) $row->batch !== $batch)) throw new LogicException('Unexpected package ledger delta');
                $guard(); $checkSources();
            });
        }
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    echo ($stage === '--apply' ? 'Applied only100/200/300 atomically; new tables empty and tenant FKs verified; parents preserved. ' : 'Verified encrypted metadata/index/constraint/ledger and streamed parent backup. ')
        .'Backup '.basename($file).' SHA256 '.hash_file('sha256', $file)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Package schema preparation failed: '.get_class($error)." (private records and connection details suppressed).\n"); exit(1);
}
