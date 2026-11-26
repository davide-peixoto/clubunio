<?php
declare(strict_types=1);

// Data-only migration evidence; never boot Laravel or source application code.
if (PHP_SAPI !== 'cli') exit(1);
require getenv('CLUBUNIO_TOOL_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/legacy-source-paths.php';
function sqlName(string $name): string { return '['.str_replace(']', ']]', $name).']'; }
function jsonLine(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"; }

try {
    if (count($argv) !== 3 || $argv[1] !== '--output-dir') throw new LogicException('Use --output-dir <new private directory>');
    $directory = $argv[2];
    if (file_exists($directory) || !is_dir(dirname($directory))) throw new LogicException('New directory beneath a protected existing parent required');
    $settings = Dotenv\Dotenv::parse(file_get_contents(clubunioLegacyBackendPath('application/.env.prod.local')));
    if ($settings['DB_DBNAME'] !== 'db_acdf8d_mysvs') throw new LogicException('Exact SV Sissach source database required');
    $source = new PDO('sqlsrv:Server='.$settings['DB_HOST'].','.($settings['DB_PORT'] ?? '1433').';Database='.$settings['DB_DBNAME'].';Encrypt=yes;TrustServerCertificate=no', $settings['DB_USER'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($source->query('SELECT DB_NAME()')->fetchColumn() !== 'db_acdf8d_mysvs' || (int)$source->query('SELECT snapshot_isolation_state FROM sys.databases WHERE name=DB_NAME()')->fetchColumn() !== 1) throw new LogicException('Verified source identity and SNAPSHOT isolation required');
    $source->exec('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
    $source->beginTransaction();
    mkdir($directory, 0700);
    $started = gmdate('c');
    $tables = $source->query("SELECT s.name AS schema_name,t.name AS table_name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.is_ms_shipped=0 ORDER BY s.name,t.name")->fetchAll(PDO::FETCH_ASSOC);
    $columnQuery = $source->prepare('SELECT c.name,ty.name AS type_name,c.column_id,c.is_nullable,c.max_length,c.precision,c.scale,c.is_identity,c.is_computed FROM sys.columns c JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID(?) ORDER BY c.column_id');
    $keyQuery = $source->prepare('SELECT c.name FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE i.object_id=OBJECT_ID(?) AND i.is_primary_key=1 ORDER BY ic.key_ordinal');
    $manifest = ['format'=>'clubunio-source-v1','source'=>'db_acdf8d_mysvs','started_utc'=>$started,'isolation'=>'SNAPSHOT','tables'=>[]];
    foreach ($tables as $index=>$table) {
        $name=$table['schema_name'].'.'.$table['table_name'];
        $qualified=sqlName($table['schema_name']).'.'.sqlName($table['table_name']);
        $columnQuery->execute([$qualified]); $columns=$columnQuery->fetchAll(PDO::FETCH_ASSOC);
        $keyQuery->execute([$qualified]); $keys=$keyQuery->fetchAll(PDO::FETCH_COLUMN);
        $projection=[];
        foreach ($columns as $column) {
            $field=sqlName($column['name']); $type=strtolower($column['type_name']);
            if (in_array($type,['binary','varbinary','image','timestamp','rowversion'],true)) {
                $projection[]="CONVERT(varchar(max),CAST($field AS varbinary(max)),2) AS $field";
            } else {
                $style=in_array($type,['datetime','datetime2','smalldatetime','datetimeoffset','date','time'],true)?',126':'';
                $projection[]="CONVERT(nvarchar(max),$field$style) AS $field";
            }
        }
        $file=sprintf('table-%04d.jsonl.gz',$index);
        $stream=gzopen($directory.'/'.$file,'wb6');
        if (!$stream) throw new RuntimeException('Cannot create snapshot stream');
        $hash=hash_init('sha256'); $count=0;
        $cursor=$source->query('SELECT '.implode(',',$projection).' FROM '.$qualified.($keys?' ORDER BY '.implode(',',array_map('sqlName',$keys)):''));
        while ($row=$cursor->fetch(PDO::FETCH_ASSOC)) {
            $line=jsonLine($row); hash_update($hash,$line);
            if (gzwrite($stream,$line)!==strlen($line)) throw new RuntimeException('Incomplete snapshot write');
            ++$count;
        }
        gzclose($stream); $cursor->closeCursor();
        $manifest['tables'][$name]=['file'=>$file,'rows'=>$count,'primary_key'=>$keys,'columns'=>$columns,'row_stream_sha256'=>hash_final($hash),'file_sha256'=>hash_file('sha256',$directory.'/'.$file)];
    }
    // Archive definitions as inert evidence only, never execute them in PostgreSQL.
    $definitions=$source->query("SELECT s.name AS schema_name,o.name,o.type_desc,m.definition FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id LEFT JOIN sys.sql_modules m ON m.object_id=o.object_id WHERE o.is_ms_shipped=0 AND o.type IN ('V','P','FN','TR','IF','TF') ORDER BY s.name,o.name")->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($directory.'/definitions.json',jsonLine($definitions));
    $manifest['definitions_sha256']=hash_file('sha256',$directory.'/definitions.json');
    $source->rollBack();
    $manifest['completed_utc']=gmdate('c');
    $manifest['status']='complete';
    file_put_contents($directory.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo jsonLine(['status'=>'complete','tables'=>count($tables),'rows'=>array_sum(array_column($manifest['tables'],'rows')),'directory'=>$directory]);
} catch (Throwable $error) {
    if (isset($source) && $source->inTransaction()) $source->rollBack();
    // No manifest means incomplete, never eligible as an import baseline.
    fwrite(STDERR,'Snapshot failed: '.get_class($error).'. No source values logged.'.PHP_EOL);
    exit(1);
}
