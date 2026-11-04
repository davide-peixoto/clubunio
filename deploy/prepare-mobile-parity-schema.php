<?php

// Reviewed DEV-only additive800/900/1000. Export, verification and apply are separate.
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
    $release = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-parity-foundation';
    if (realpath(__FILE__) !== $release.'/deploy/prepare-mobile-parity-schema.php') throw new LogicException('Runner must belong to fixed release');
    require $release.'/vendor/autoload.php';
    $app = require $release.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $directory = '/var/www/svs-laravel/shared/backups';
    $db = DB::connection('clubunio');
    $guard = function () use ($db, $release): void {
        if (realpath(base_path()) !== $release || realpath('/var/www/svs-laravel/current') !== $release
            || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
            || config('app.url') !== 'https://dev.clubunio.ch' || ! config('clubunio-mobile.enabled')) throw new LogicException('Exact active DEV foundation required');
        $version=(int)$db->selectOne("SELECT current_setting('server_version_num')::integer AS version")->version;
        if ($version<180000 || $version>=190000) throw new LogicException('Reviewed PG18 catalog semantics required');
        foreach (['native_role_guide_enabled', 'native_push_admin_enabled', 'push_enabled'] as $flag) {
            if (config('clubunio-mobile.'.$flag, false)) throw new LogicException('Package writes must stay closed');
        }
        foreach (['clubunio-mobile.bootstrap.enabled','clubunio-expense-provenance.enabled','clubunio-expense-ocr.enabled',
            'clubunio-mobile.keybox.administration_enabled','clubunio-mobile-push.enabled','clubunio-mobile-push.delivery_enabled',
            'clubunio-mobile.public_identity.mail_enabled','clubunio-mobile.ai.enabled','clubunio-mobile.training_ai.enabled',
            'clubunio-mobile.keybox.issuance_enabled','clubunio-mobile.keybox.provider_enabled',
            'clubunio-mobile.keybox.operations_reviewed','clubunio-mobile.keybox.offline_revocation_acknowledged'] as $flag) {
            if (config($flag, false)) throw new LogicException('External/followup operations must remain closed');
        }
        if (config('clubunio-mobile-push.apps', []) || config('clubunio-mobile.bootstrap.origins', [])
            || config('clubunio-mobile.keybox.tenant_slugs', []) || config('clubunio-mobile.keybox.approved_keybox_ids', []))
            throw new LogicException('No external runtime configuration in foundation');
    };
    $guard();
    if ($db->transactionLevel() !== 0) throw new LogicException('Standalone schema operation required');
    $specPath = __DIR__.'/mobile-parity-schema.json';
    $specHash = '9bc49f6f1700358e2fe175e4075bacb20e3311d7c1a9ee8e18af23b28612a06c';
    if (!is_file($specPath) || is_link($specPath) || !hash_equals($specHash, hash_file('sha256', $specPath))) throw new LogicException('Fixed schema blueprint required');
    $spec = json_decode(file_get_contents($specPath), true, 64, JSON_THROW_ON_ERROR);
    $migrations = $spec['migrations']; $parents = $spec['parents']; $newTables = $spec['newTables'];
    $expectedFks = $spec['expectedFks']; $primaryKeys = $spec['primaryKeys'];
    if (count($migrations) !== 3 || count($parents) !== 9 || count($newTables) !== 4
        || array_sum(array_map('count', $expectedFks)) !== 11) throw new LogicException('Unexpected fixed schema inventory');
    $settingsDefaults = $spec['settingsDefaults'];
    $identifier = function (mixed $value): void {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]*$/D', $value)) throw new LogicException('Invalid blueprint identifier');
    };
    foreach ($parents as $table) $identifier($table);
    foreach ($newTables as $table => $columns) {
        $identifier($table);
        if (!is_array($columns) || !array_is_list($columns) || count($columns) !== count(array_unique($columns))) throw new LogicException('Invalid blueprint columns');
        foreach ($columns as $column) $identifier($column);
        if (!isset($expectedFks[$table], $primaryKeys[$table])) throw new LogicException('Incomplete blueprint');
        foreach (explode(',', $primaryKeys[$table]) as $column) { $identifier($column); if (!in_array($column,$columns,true)) throw new LogicException('Invalid PK column'); }
        foreach ($expectedFks[$table] as $foreignKey) {
            $parts = explode('|', $foreignKey);
            if (count($parts) !== 4 || $parts[1] !== 'clubora') throw new LogicException('Invalid FK blueprint');
            $identifier($parts[2]);
            if (!in_array($parts[2], array_merge($parents,array_keys($newTables)),true)) throw new LogicException('Unknown FK parent');
            foreach ([$parts[0],$parts[3]] as $csv) foreach (explode(',',$csv) as $column) $identifier($column);
            foreach (explode(',',$parts[0]) as $column) if (!in_array($column,$columns,true)) throw new LogicException('Unknown local FK column');
            if (count(explode(',',$parts[0])) !== count(explode(',',$parts[3]))) throw new LogicException('Invalid FK width');
        }
    }
    if (array_keys($settingsDefaults) !== ['MaintenanceMode','MaintenanceMessage','RegistrationActive']) throw new LogicException('Unexpected settings defaults');
    foreach ($settingsDefaults as $key => $setting) if (array_keys($setting) !== ['label','type','value']
        || !is_string($setting['label']) || !in_array($setting['type'],['boolean','string'],true) || !is_string($setting['value'])) throw new LogicException('Invalid setting blueprint');
    $checkSources = function () use ($migrations, $specPath, $specHash): void {
        if (!hash_equals($specHash, hash_file('sha256', $specPath))) throw new LogicException('Blueprint source changed');
        foreach ($migrations as $name => $hash) {
            if (!preg_match('/^2026_11_04_(?:000800|000900|001000)_[a-z_]+$/D',$name) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new LogicException('Invalid migration source identity');
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
        if ($db->selectOne("SELECT count(*) AS total FROM pg_constraint WHERE conrelid=to_regclass('clubora.mobile_ai_work') AND conname='mobile_ai_work_tenant_actor_id_unique'")->total != 0) throw new LogicException('AI unique addition already exists');
        if ($db->selectOne("SELECT to_regclass('clubora.mobile_ai_work_tenant_actor_id_unique') AS relation")->relation !== null) throw new LogicException('AI unique index already exists');
    };
    $session = function () use ($db): void {
        $db->statement("SET LOCAL application_name TO 'clubunio-packages-800-900-1000-schema'");
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
        return ['relation' => $db->select('SELECT relkind,relpersistence,relrowsecurity,relforcerowsecurity,relreplident,reloptions,relacl FROM pg_class WHERE oid=to_regclass(?)',[$relation]), 'columns' => $columns,
            'indices' => $db->select("SELECT v.indexname,v.indexdef,i.indisvalid,i.indisready,i.indislive,i.indisunique,i.indisprimary,i.indnullsnotdistinct,i.indisexclusion,i.indisclustered,i.indisreplident,to_jsonb(i)::text AS catalog_properties FROM pg_indexes v JOIN pg_namespace n ON n.nspname=v.schemaname JOIN pg_class c ON c.relnamespace=n.oid AND c.relname=v.indexname JOIN pg_index i ON i.indexrelid=c.oid WHERE v.schemaname='clubora' AND v.tablename=? ORDER BY v.indexname", [$table]),
            'constraints' => $db->select('SELECT conname,contype,convalidated,condeferrable,condeferred,conenforced,conislocal,coninhcount,connoinherit,confmatchtype,confupdtype,confdeltype,(to_jsonb(pg_constraint)-\'oid\'-\'conbin\')::text AS catalog_properties,pg_get_constraintdef(oid,true) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname', [$relation])];
    };
    // A server-side cursor fetches bounded batches; parent rows never accumulate in PHP memory.
    $rows = function (string $table, callable $consume, bool $withoutNewLedger = false, array $excludeSettings = []) use ($db, $migrations): void {
        if ($excludeSettings && $table !== 'tenant_settings') throw new LogicException('Unexpected row exclusions');
        foreach ($excludeSettings as $id) if (!preg_match('/^[1-9][0-9]*$/D',(string)$id)) throw new LogicException('Invalid excluded ID');
        $filter = $withoutNewLedger ? " WHERE migration NOT IN ('".implode("','", array_keys($migrations))."')" : '';
        $filter .= $excludeSettings ? ' WHERE id NOT IN ('.implode(',',$excludeSettings).')' : '';
        $db->unprepared('DECLARE cu_packages_rows NO SCROLL CURSOR FOR SELECT * FROM clubora.'.$table.$filter.' ORDER BY id');
        try {
            do {
                $batch = $db->select('FETCH FORWARD 128 FROM cu_packages_rows');
                foreach ($batch as $row) $consume((array) $row);
            } while (count($batch) === 128);
        } finally { $db->statement('CLOSE cu_packages_rows'); }
    };
    $digest = function (string $table, bool $withoutNewLedger = false, array $excludeSettings = []) use ($rows, $canonical): array {
        $hash = hash_init('sha256'); $count = 0;
        $rows($table, function (array $row) use ($hash, &$count, $canonical): void { hash_update($hash, $canonical($row)."\n"); $count++; }, $withoutNewLedger, $excludeSettings);
        return ['count' => $count, 'sha256' => hash_final($hash)];
    };
    // Only bounded ID/pair inventories are held in memory; all private parent rows stream.
    $settingsBaseline = function () use ($db, $settingsDefaults): array {
        $tenants = $db->table('clubora.tenants')->orderBy('id')->limit(2001)->pluck('id')->map(fn ($id)=>(string)$id)->all();
        $ids = $db->table('clubora.tenant_settings')->orderBy('id')->limit(50001)->pluck('id')->map(fn ($id)=>(string)$id)->all();
        if (count($tenants)>2000 || count($ids)>50000) throw new LogicException('Settings inventory exceeds reviewed memory bounds');
        $existing = $db->table('clubora.tenant_settings')->where('area','app')->whereIn('key',array_keys($settingsDefaults))->limit(50001)->get(['tenant_id','key']);
        if ($existing->count()>50000) throw new LogicException('App settings exceed reviewed bounds');
        $seen=[]; foreach ($existing as $row) $seen[$row->tenant_id.':'.$row->key]=true;
        $missing=[];
        foreach ($tenants as $tenant) foreach ($settingsDefaults as $key=>$setting) if (!isset($seen[$tenant.':'.$key])) $missing[]=$tenant.':'.$key;
        return ['tenants'=>$tenants,'setting_ids'=>$ids,'missing_pairs'=>$missing];
    };
    $verifyNewSettings = function (array $before) use ($db, $settingsDefaults): array {
        if (count($before['tenants'])>2000 || count($before['setting_ids'])>50000 || count($before['missing_pairs'])>6000) throw new LogicException('Backup settings bounds invalid');
        $ids=$db->table('clubora.tenant_settings')->orderBy('id')->limit(56001)->pluck('id')->map(fn ($id)=>(string)$id)->all();
        if (count($ids)>56000 || array_diff($before['setting_ids'],$ids)) throw new LogicException('Old settings disappeared or inventory grew unexpectedly');
        $added=array_values(array_diff($ids,$before['setting_ids']));
        if (count($added)!==count($before['missing_pairs'])) throw new LogicException('Unexpected new settings count');
        $actual=[];
        if ($added) foreach ($db->table('clubora.tenant_settings')->whereIn('id',$added)->orderBy('id')->get() as $row) {
            $setting=$settingsDefaults[$row->key]??null;
            if (!$setting || $row->area!=='app' || $row->label!==$setting['label'] || $row->value_type!==$setting['type']
                || $row->enabled!==true || $row->is_secret!==false || Crypt::decryptString($row->encrypted_value)!==$setting['value'])
                throw new LogicException('Unexpected new default setting content');
            $actual[]=$row->tenant_id.':'.$row->key;
        }
        $expected=$before['missing_pairs']; sort($actual); sort($expected);
        if ($actual!==$expected) throw new LogicException('Unexpected settings pair delta');
        return $added;
    };
    $oldKinds=['chat','announcement','invoice','planning','expense','member','member_change','registration','bus','document_receipt','document_file','document_archive','penalty'];
    $oldKindDefinition="CHECK (kind::text = ANY (ARRAY[".implode(', ',array_map(fn ($kind)=>"'".$kind."'::character varying",$oldKinds))."]::text[]))";
    $newKindDefinition=str_replace("'penalty'::character varying]", "'penalty'::character varying, 'admin_notice'::character varying]",$oldKindDefinition);
    if ($newKindDefinition===$oldKindDefinition) throw new LogicException('Expected single kind append unavailable');
    if (! is_dir($directory) && ($stage !== '--export' || ! mkdir($directory, 0700, true))) throw new LogicException('Private backup directory unavailable');
    if (is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0077) !== 0) throw new LogicException('Exact private backup directory required');
    $file = $stage === '--export' ? $directory.'/mobile-before-800-900-1000-20261104-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(16)).'.cuenc' : $fileArgument;
    if (! preg_match('#^'.preg_quote($directory, '#').'/mobile-before-800-900-1000-20261104-[0-9]{14}-[a-f0-9]{32}\.cuenc$#D', $file)) throw new LogicException('Fixed private backup scope required');
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
            $write(['kind' => 'header', 'format' => 'clubunio-packages-800-900-1000-v1', 'created_at' => gmdate('c'),
                'database' => $db->getDatabaseName(), 'release' => $release, 'migrations' => $migrations, 'parents' => $parents, 'settings_before' => $settingsBaseline()]);
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
        if (! feof($handle) || ! $header || ! $footer || $header['format'] !== 'clubunio-packages-800-900-1000-v1'
            || $header['database'] !== $db->getDatabaseName() || $header['release'] !== $release || $header['migrations'] !== $migrations
            || $header['parents'] !== $parents || ! hash_equals($footer['sha256'] ?? '', hash_final($chain))) throw new LogicException('Backup identity/source/chain mismatch');
        $created = strtotime($header['created_at']);
        $assertBackupFresh = function () use ($created): void {
            if (! $created || $created > time() + 5 || time() - $created > 3600) throw new LogicException('Verified backup must be no older than one hour');
        };
        $assertBackupFresh();
        $guard(); $checkSources();
        $settingsBefore=$header['settings_before']??null;
        if (!is_array($settingsBefore) || array_keys($settingsBefore)!==['tenants','setting_ids','missing_pairs']) throw new LogicException('Backup settings inventory missing');
        foreach ($settingsBefore as $items) if (!is_array($items) || !array_is_list($items) || count($items)>50000) throw new LogicException('Invalid backup inventory bounds');
        $verifyParents = function (bool $after = false) use ($parents, $summaries, $metadata, $digest, $canonical, $settingsBaseline, $verifyNewSettings, $settingsBefore, $oldKindDefinition, $newKindDefinition): void {
            $newSettings=[];
            if ($after) $newSettings=$verifyNewSettings($settingsBefore);
            elseif ($canonical($settingsBaseline())!==$canonical($settingsBefore)) throw new LogicException('Settings baseline changed since backup');
            foreach ($parents as $table) {
                $actualMetadata = $metadata($table);
                if ($after && $table === 'mobile_ai_work') {
                    $indices = array_values(array_filter($actualMetadata['indices'], fn ($i) => $i->indexname === 'mobile_ai_work_tenant_actor_id_unique'));
                    $constraints = array_values(array_filter($actualMetadata['constraints'], fn ($c) => $c->conname === 'mobile_ai_work_tenant_actor_id_unique'));
                    if (count($indices) !== 1 || $indices[0]->indexdef !== 'CREATE UNIQUE INDEX mobile_ai_work_tenant_actor_id_unique ON clubora.mobile_ai_work USING btree (tenant_id, account_id, id)'
                        || count($constraints) !== 1 || $constraints[0]->contype !== 'u' || $constraints[0]->definition !== 'UNIQUE (tenant_id, account_id, id)'
                        || !$constraints[0]->convalidated || !$constraints[0]->conenforced || $constraints[0]->condeferrable || $constraints[0]->condeferred) throw new LogicException('Unexpected AI unique addition');
                    foreach (['indisvalid','indisready','indislive','indisunique'] as $flag) if ($indices[0]->$flag!==true) throw new LogicException('Invalid new unique index state');
                    foreach (['indisprimary','indnullsnotdistinct','indisexclusion','indisclustered','indisreplident'] as $flag) if ($indices[0]->$flag!==false) throw new LogicException('Unexpected new unique index policy');
                    $actualMetadata['indices'] = array_values(array_filter($actualMetadata['indices'], fn ($i) => $i->indexname !== 'mobile_ai_work_tenant_actor_id_unique'));
                    $actualMetadata['constraints'] = array_values(array_filter($actualMetadata['constraints'], fn ($c) => $c->conname !== 'mobile_ai_work_tenant_actor_id_unique'));
                }
                if ($table === 'mobile_push_outbox') {
                    $constraints=array_values(array_filter($actualMetadata['constraints'],fn ($c)=>$c->conname==='mobile_push_outbox_kind_check'));
                    if (count($constraints)!==1 || $constraints[0]->contype!=='c' || $constraints[0]->definition!==($after?$newKindDefinition:$oldKindDefinition)
                        || !$constraints[0]->convalidated || !$constraints[0]->conenforced || $constraints[0]->condeferrable || $constraints[0]->condeferred)
                        throw new LogicException('Exact known PG18 outbox kind check required');
                    // Normalize precisely its one allowed definition change; compare every other property unchanged.
                    if ($after) $constraints[0]->definition=$oldKindDefinition;
                }
                if ($canonical($actualMetadata) !== $canonical($summaries[$table]['metadata'])
                    || $digest($table, $after && $table === 'migrations', $after && $table==='tenant_settings'?$newSettings:[]) !== ['count' => $summaries[$table]['count'], 'sha256' => $summaries[$table]['sha256']]) throw new LogicException('Parent fields/indexes/constraints/rows or ledger changed');
            }
        };
        if ($stage !== '--apply') {
            $db->beginTransaction();
            try { $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); $session(); $fresh(); $verifyParents(); }
            finally { $db->rollBack(); }
        } else {
            $db->transaction(function () use ($db, $session, $fresh, $verifyParents, $guard, $checkSources, $migrations, $parents, $newTables, $expectedFks, $primaryKeys, $assertBackupFresh): void {
                $session();
                $locked = $db->selectOne("SELECT CASE WHEN pg_try_advisory_xact_lock(hashtext('clubunio-mobile-packages-800-900-1000')) THEN 1 ELSE 0 END AS locked");
                if ((int) $locked->locked !== 1) throw new LogicException('Concurrent package schema operation');
                $db->statement('LOCK TABLE '.implode(', ', array_map(fn ($table) => 'clubora.'.$table, $parents)).' IN SHARE ROW EXCLUSIVE MODE NOWAIT');
                $guard(); $checkSources(); $fresh(); $verifyParents(); $assertBackupFresh();
                $batch = (int) $db->table('clubora.migrations')->max('batch') + 1;
                foreach ($migrations as $name => $hash) {
                    (require database_path('clubunio/'.$name.'.php'))->up();
                    $db->table('clubora.migrations')->insert(['migration' => $name, 'batch' => $batch]);
                }
                foreach ($newTables as $table => $columns) {
                    if (! $db->getSchemaBuilder()->hasTable('clubora.'.$table) || $db->table('clubora.'.$table)->exists()
                        || $db->getSchemaBuilder()->getColumnListing('clubora.'.$table) !== $columns) throw new LogicException('Unexpected new table state');
                    $fks = $db->select("SELECT CASE WHEN c.convalidated THEN 1 ELSE 0 END AS validated, CASE WHEN c.conenforced THEN 1 ELSE 0 END AS enforced, CASE WHEN c.condeferrable THEN 1 ELSE 0 END AS deferrable, CASE WHEN c.condeferred THEN 1 ELSE 0 END AS deferred,c.confmatchtype,c.confupdtype,c.confdeltype,
                        array_to_string(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num ORDER BY k.ord),',') AS local_columns,
                        n.nspname AS parent_schema,p.relname AS parent_table,
                        array_to_string(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.num ORDER BY k.ord),',') AS parent_columns
                        FROM pg_constraint c JOIN pg_class p ON p.oid=c.confrelid JOIN pg_namespace n ON n.oid=p.relnamespace
                        WHERE c.conrelid=to_regclass(?) AND c.contype='f'", ['clubora.'.$table]);
                    $actual = [];
                    foreach ($fks as $fk) {
                        if ((int) $fk->validated !== 1 || (int) $fk->enforced !== 1 || (int) $fk->deferrable !== 0 || (int) $fk->deferred !== 0 || $fk->confmatchtype !== 's' || $fk->confupdtype !== 'a' || $fk->confdeltype !== 'a') throw new LogicException('Unexpected new FK policy');
                        $actual[] = $fk->local_columns.'|'.$fk->parent_schema.'|'.$fk->parent_table.'|'.$fk->parent_columns;
                    }
                    $expected = $expectedFks[$table]; sort($actual); sort($expected);
                    if ($actual !== $expected) throw new LogicException('New tenant FK scope mismatch');
                    $primary = $db->select("SELECT c.convalidated,c.conenforced,c.condeferrable,c.condeferred,array_to_string(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num ORDER BY k.ord),',') AS columns
                        FROM pg_constraint c WHERE c.conrelid=to_regclass(?) AND c.contype='p'", ['clubora.'.$table]);
                    $expectedPrimary = $primaryKeys[$table];
                    if (count($primary) !== 1 || $primary[0]->columns !== $expectedPrimary || !$primary[0]->convalidated || !$primary[0]->conenforced || $primary[0]->condeferrable || $primary[0]->condeferred) throw new LogicException('Unexpected new tenant primary key');
                    $public = $db->selectOne("SELECT count(*) AS total FROM pg_class c CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl,acldefault('r',c.relowner))) acl WHERE c.oid=to_regclass(?) AND acl.grantee=0", ['clubora.'.$table]);
                    if ((int) $public->total !== 0) throw new LogicException('New table grants PUBLIC access');
                }
                $verifyParents(true);
                $ledger = $db->table('clubora.migrations')->whereIn('migration', array_keys($migrations))->orderBy('migration')->get();
                if ($ledger->count() !== 3 || $ledger->pluck('migration')->all() !== array_keys($migrations) || $ledger->contains(fn ($row) => (int) $row->batch !== $batch)) throw new LogicException('Unexpected package ledger delta');
                $guard(); $checkSources(); $assertBackupFresh();
            });
        }
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    echo ($stage === '--apply' ? 'Applied only800/900/1000 atomically; new tables empty and tenant FKs verified; parent rows preserved; only exact settings defaults, AI unique addition and outbox kind append. ' : 'Verified encrypted metadata/index/constraint/ledger and streamed parent backup. ')
        .'Backup '.basename($file).' SHA256 '.hash_file('sha256', $file)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Package schema preparation failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()." (private records and connection details suppressed).\n"); exit(1);
}
