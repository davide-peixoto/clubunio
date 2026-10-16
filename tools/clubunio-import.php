<?php
declare(strict_types=1);

// Standalone CLI: never boot either application or execute source business logic.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/legacy-source-paths.php';

function values(string $path): array {
    return Dotenv\Dotenv::parse(file_get_contents($path));
}
function quoted(string $name): string { return '"'.str_replace('"', '""', $name).'"'; }
function bracket(string $name): string { return '['.str_replace(']', ']]', $name).']'; }
function pgType(array $column): string {
    return match (strtolower($column['type_name'])) {
        'bigint'=>'bigint', 'int'=>'integer', 'smallint','tinyint'=>'smallint',
        'bit'=>'boolean',
        'decimal','numeric'=>'numeric('.(int)$column['precision'].','.(int)$column['scale'].')',
        'money'=>'numeric(19,4)', 'smallmoney'=>'numeric(10,4)',
        // Legacy timestamps have no documented zone; do not invent one.
        'datetime','smalldatetime'=>'timestamp without time zone',
        'datetime2'=>(int)$column['scale']>6 ? 'text' : 'timestamp without time zone',
        'datetimeoffset'=>'text', 'date'=>'date', 'time'=>(int)$column['scale']>6 ? 'text' : 'time without time zone',
        'uniqueidentifier'=>'uuid', 'binary','varbinary','image','timestamp'=>'bytea',
        'float'=>'double precision', 'real'=>'real',
        'char','nchar','varchar','nvarchar','text','ntext','sysname','xml'=>'text',
        default=>throw new RuntimeException('Unmapped source type'),
    };
}
function normalized(array $row, array $columns): string {
    $values = [];
    foreach ($columns as $c) {
        $v = $row[$c['name']];
        if ($v !== null) {
            $v = (string)$v;
            $type = strtolower($c['type_name']);
            if ($type === 'bit') $v = in_array($v, ['1','t','true'], true) ? '1' : '0';
            if ($type === 'uniqueidentifier' || in_array($type,['binary','varbinary','image','timestamp'],true)) $v = strtolower($v);
            if (in_array($type,['datetime','datetime2','smalldatetime','time'],true)) {
                $v = str_replace('T',' ',$v);
                if (str_contains($v,'.')) $v = rtrim(rtrim($v,'0'),'.');
            }
        }
        $values[] = $v;
    }
    return json_encode($values, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}
function source(): PDO {
    $v = values(clubunioLegacyBackendPath('application/.env.prod.local'));
    if (str_contains(strtolower($v['DB_DBNAME']), 'dev')) throw new RuntimeException('DEV source forbidden');
    return new PDO('sqlsrv:Server='.$v['DB_HOST'].','.($v['DB_PORT'] ?? '1433').';Database='.$v['DB_DBNAME'].';Encrypt=yes;TrustServerCertificate=no', $v['DB_USER'], $v['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function target(): PDO {
    $v = values(dirname(__DIR__).'/.env.postgresql.local');
    if ($v['PGDATABASE'] !== 'db_acdf8d_dclubora' || $v['PGHOST'] !== 'pg6001.site4now.net') throw new RuntimeException('Unexpected target');
    $dsn = 'pgsql:host='.$v['PGHOST'].';port='.$v['PGPORT'].';dbname='.$v['PGDATABASE'].';sslmode=verify-full';
    if (!empty($v['PGSSLROOTCERT'])) $dsn .= ';sslrootcert='.$v['PGSSLROOTCERT'];
    return new PDO($dsn, $v['PGUSER'], $v['PGPASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
}
try {
    $mode = $argv[1] ?? 'inspect';
    if ($mode === 'configure') {
        $path = dirname(__DIR__).'/.env.postgresql.local';
        $website = values('C:/Projects/SVS/website/.env.local');
        $password = rawurldecode(parse_url($website['POSTGRES_DATABASE_URL'], PHP_URL_PASS) ?: '');
        if ($password === '') throw new RuntimeException('No website password');
        if (!empty(values($path)['PGPASSWORD']) && values($path)['PGPASSWORD'] !== $password) throw new RuntimeException('Different target password already set');
        $ca = ini_get('curl.cainfo');
        if (!$ca || !is_file($ca)) throw new RuntimeException('CA bundle not available');
        $ca = str_replace('\\', '/', $ca);
        $v = ['PGHOST'=>'pg6001.site4now.net', 'PGPORT'=>'6432', 'PGDATABASE'=>'db_acdf8d_dclubora', 'PGUSER'=>'acdf8d_dclubora', 'PGPASSWORD'=>$password, 'PGSSLMODE'=>'verify-full', 'PGSSLROOTCERT'=>$ca];
        $lines = ['# Private ClubUnio target. Not loaded by the running Laravel pilot.'];
        foreach ($v as $key=>$value) $lines[] = $key.'="'.str_replace(['\\','"','$'], ['\\\\','\\"','\\$'], $value).'"';
        file_put_contents($path, implode("\n",$lines)."\n");
        echo "Private target configuration prepared.\n";
        exit;
    }
    $pg = target();
    if ($mode === 'verify') {
        $pg->exec('BEGIN READ ONLY');
        $columns=json_decode($pg->query("SELECT metadata FROM migration_audit.catalog WHERE category='columns'")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $manifest=$pg->query('SELECT * FROM migration_audit.tables ORDER BY source_schema,source_table')->fetchAll(PDO::FETCH_ASSOC);
        $total=0;
        foreach ($manifest as $table) {
            $fields=array_values(array_filter($columns,fn($c)=>$c['schema_name']===$table['source_schema'] && $c['table_name']===$table['source_table']));
            $projection=implode(',',array_map(fn($c)=>quoted($c['name']).'::text AS '.quoted($c['name']),$fields));
            $rows=$pg->query('SELECT '.$projection.' FROM legacy_prod.'.quoted($table['target_table']).' ORDER BY "_migration_row"');
            $hash=hash_init('sha256'); $seen=0;
            while ($row=$rows->fetch(PDO::FETCH_ASSOC)) { hash_update($hash,normalized($row,$fields)); ++$seen; }
            if ($seen!==(int)$table['rows'] || hash_final($hash)!==$table['source_sha256']) throw new RuntimeException('Persisted snapshot mismatch');
            $total+=$seen;
        }
        $run=$pg->query('SELECT * FROM migration_audit.run')->fetch(PDO::FETCH_ASSOC);
        $pg->exec('ROLLBACK');
        echo json_encode(['verified_tables'=>count($manifest),'verified_rows'=>$total,'run'=>$run],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
        exit;
    }
    $src = source();
    if ($mode === 'inspect') {
        $result = [
            'target_version'=>$pg->query('SHOW server_version')->fetchColumn(),
            'target_objects'=>$pg->query("SELECT table_schema,table_name FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema') ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC),
            'source'=>$src->query('SELECT DB_NAME() AS database_name, snapshot_isolation_state_desc FROM sys.databases WHERE name=DB_NAME()')->fetch(PDO::FETCH_ASSOC),
            'source_tables'=>$src->query("SELECT s.name AS schema_name,t.name, SUM(p.rows) AS approximate_rows FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.partitions p ON p.object_id=t.object_id AND p.index_id IN (0,1) WHERE t.is_ms_shipped=0 GROUP BY s.name,t.name ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC),
        ];
        echo json_encode($result, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
        exit;
    }
    if ($mode === 'copy-prod') {
        if (($argv[2] ?? '') !== '--new-empty-target') throw new RuntimeException('Explicit empty-target confirmation required');
        if ($src->query('SELECT DB_NAME()')->fetchColumn() !== 'db_acdf8d_mysvs') throw new RuntimeException('Unexpected PROD database');
        if ($pg->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')")->fetchColumn() != 0) throw new RuntimeException('Target is not empty');
        if ($src->query('SELECT snapshot_isolation_state FROM sys.databases WHERE name=DB_NAME()')->fetchColumn() != 1) throw new RuntimeException('Snapshot isolation required');
        $src->exec('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
        $src->beginTransaction();
        $pg->beginTransaction();
        $pg->exec('CREATE SCHEMA legacy_prod');
        $pg->exec('CREATE SCHEMA migration_audit');
        $pg->exec('REVOKE ALL ON SCHEMA legacy_prod, migration_audit FROM PUBLIC');
        $pg->exec('CREATE TABLE migration_audit.catalog (category text PRIMARY KEY, metadata jsonb NOT NULL)');
        $pg->exec('CREATE TABLE migration_audit.tables (source_schema text, source_table text, target_table text, rows bigint, source_sha256 text, target_sha256 text, PRIMARY KEY(source_schema,source_table))');
        $pg->exec("CREATE TABLE migration_audit.run (source_database text, started_at timestamptz, completed_at timestamptz, status text)");
        $pg->exec("INSERT INTO migration_audit.run VALUES ('db_acdf8d_mysvs',clock_timestamp(),NULL,'copying')");
        $catalog = [
            'columns'=>"SELECT s.name AS schema_name,t.name AS table_name,c.column_id,c.name,ty.name AS type_name,c.max_length,c.precision,c.scale,c.is_nullable,c.is_identity,c.is_computed,c.collation_name,dc.definition AS default_definition,cc.definition AS computed_definition FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.columns c ON c.object_id=t.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id LEFT JOIN sys.default_constraints dc ON dc.object_id=c.default_object_id LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id WHERE t.is_ms_shipped=0 ORDER BY s.name,t.name,c.column_id",
            'modules'=>"SELECT s.name AS schema_name,o.name,o.type_desc,m.definition FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id LEFT JOIN sys.sql_modules m ON m.object_id=o.object_id WHERE o.is_ms_shipped=0 AND o.type IN ('V','P','FN','TR','IF','TF') ORDER BY s.name,o.name",
            'foreign_keys'=>"SELECT fk.name,OBJECT_SCHEMA_NAME(fk.parent_object_id) AS source_schema,OBJECT_NAME(fk.parent_object_id) AS source_table,COL_NAME(fk.parent_object_id,fkc.parent_column_id) AS source_column,OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS target_schema,OBJECT_NAME(fk.referenced_object_id) AS target_table,COL_NAME(fk.referenced_object_id,fkc.referenced_column_id) AS target_column,fkc.constraint_column_id,fk.is_not_trusted,fk.is_disabled,fk.delete_referential_action_desc,fk.update_referential_action_desc FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id=fk.object_id ORDER BY fk.name,fkc.constraint_column_id",
            'indexes'=>"SELECT s.name AS schema_name,t.name AS table_name,i.name,i.is_primary_key,i.is_unique,i.type_desc,i.filter_definition,ic.key_ordinal,ic.is_descending_key,ic.is_included_column,c.name AS column_name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.indexes i ON i.object_id=t.object_id JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE t.is_ms_shipped=0 ORDER BY s.name,t.name,i.index_id,ic.index_column_id",
            'checks'=>"SELECT OBJECT_SCHEMA_NAME(parent_object_id) AS schema_name,OBJECT_NAME(parent_object_id) AS table_name,name,definition,is_disabled,is_not_trusted FROM sys.check_constraints",
        ];
        $saveCatalog = $pg->prepare('INSERT INTO migration_audit.catalog VALUES (?,CAST(? AS jsonb))');
        $allColumns = [];
        foreach ($catalog as $category=>$query) {
            $data = $src->query($query)->fetchAll(PDO::FETCH_ASSOC);
            $saveCatalog->execute([$category,json_encode($data,JSON_THROW_ON_ERROR)]);
            if ($category === 'columns') $allColumns=$data;
        }
        $tables=[];
        foreach ($allColumns as $column) $tables[$column['schema_name'].'.'.$column['table_name']][]=$column;
        $total=0;
        foreach ($tables as $columns) {
            $schema=$columns[0]['schema_name']; $name=$columns[0]['table_name'];
            $currentTable=$schema.'.'.$name;
            $targetName=$schema==='dbo' ? $name : $schema.'__'.$name;
            if (strlen($targetName)>63) throw new RuntimeException('Target identifier too long');
            $targetTable='legacy_prod.'.quoted($targetName);
            $defs=[]; $select=[];
            foreach ($columns as $c) {
                $defs[]=quoted($c['name']).' '.pgType($c).($c['is_nullable'] ? '' : ' NOT NULL');
                $col=bracket($c['name']);
                if (in_array(strtolower($c['type_name']),['binary','varbinary','image','timestamp'],true)) {
                    $select[]="CASE WHEN $col IS NULL THEN NULL ELSE N'\\x'+CONVERT(varchar(max),CAST($col AS varbinary(max)),2) END AS $col";
                } else {
                    $style=in_array(strtolower($c['type_name']),['datetime','datetime2','smalldatetime','datetimeoffset','date','time'],true) ? ',126' : '';
                    $select[]="CONVERT(nvarchar(max),$col$style) AS $col";
                }
            }
            // Keep original values and an ordinal for independent round-trip verification.
            $defs[]='"_migration_row" bigint PRIMARY KEY';
            $pg->exec('CREATE TABLE '.$targetTable.' ('.implode(',',$defs).')');
            $stmt=$src->query('SELECT '.implode(',',$select).' FROM '.bracket($schema).'.'.bracket($name));
            $insert=$pg->prepare('INSERT INTO '.$targetTable.' SELECT * FROM json_populate_recordset(NULL::'.$targetTable.', CAST(? AS json))');
            $batch=[]; $count=0; $hash=hash_init('sha256');
            while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ($row as $field=>$value) {
                    if (is_string($value) && str_contains($value,"\0")) throw new RuntimeException('NUL in column '.$field);
                }
                hash_update($hash,normalized($row,$columns));
                $row['_migration_row']=++$count;
                $batch[]=$row;
                if (count($batch)>=250) { $insert->execute([json_encode($batch,JSON_THROW_ON_ERROR)]); $batch=[]; }
            }
            $stmt->closeCursor();
            if ($batch) $insert->execute([json_encode($batch,JSON_THROW_ON_ERROR)]);
            $sourceHash=hash_final($hash); $hash=hash_init('sha256'); $seen=0;
            $projection=implode(',',array_map(fn($c)=>quoted($c['name']).'::text AS '.quoted($c['name']),$columns));
            $read=$pg->query('SELECT '.$projection.' FROM '.$targetTable.' ORDER BY "_migration_row"');
            while ($row=$read->fetch(PDO::FETCH_ASSOC)) { hash_update($hash,normalized($row,$columns)); ++$seen; }
            $read->closeCursor();
            $targetHash=hash_final($hash);
            if ($seen!==$count || $targetHash!==$sourceHash) throw new RuntimeException('Round-trip verification failed for '.$name);
            $pg->prepare('INSERT INTO migration_audit.tables VALUES (?,?,?,?,?,?)')->execute([$schema,$name,$targetName,$count,$sourceHash,$targetHash]);
            $total+=$count;
            echo 'Verified '.$schema.'.'.$name.': '.$count." rows\n";
        }
        $pg->exec("UPDATE migration_audit.run SET completed_at=clock_timestamp(),status='verified_snapshot'");
        $src->rollBack(); // Source read transaction only.
        $pg->commit();
        echo 'Committed verified PROD snapshot: '.count($tables).' tables, '.$total." rows.\n";
        exit;
    }
    throw new RuntimeException('Unknown mode');
} catch (Throwable $e) {
    if (isset($pg) && $pg->inTransaction()) $pg->rollBack();
    if (isset($src) && $src->inTransaction()) $src->rollBack();
    // Never emit database errors: they can contain credentials or imported values.
    fwrite(STDERR, 'Operation failed: '.get_class($e).' code '.$e->getCode()."\n");
    if (isset($currentTable)) fwrite(STDERR, 'Import rolled back at table '.$currentTable."\n");
    if ($e instanceof RuntimeException && !$e instanceof PDOException) fwrite(STDERR, $e->getMessage()."\n");
    if (str_contains($e->getMessage(), '\\u0000')) fwrite(STDERR,"PostgreSQL rejected a zero byte.\n");
    if (in_array($mode ?? '', ['inspect','configure'], true)) {
        $message = $e->getMessage();
        foreach ([dirname(__DIR__).'/.env.postgresql.local', clubunioLegacyBackendPath('application/.env.prod.local')] as $path) {
            foreach (values($path) as $key=>$value) {
                if ($value !== '' && preg_match('/PASSWORD|USER|HOST|DATABASE|DBNAME/', $key)) $message = str_replace($value, '[redacted]', $message);
            }
        }
        fwrite(STDERR, $message."\n");
    }
    exit(1);
}
