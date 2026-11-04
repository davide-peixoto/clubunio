<?php
// Read-only v2 recovery. No export, DDL, migrations, writes or provider calls.
use Illuminate\Support\Facades\{Crypt, DB};

try {
    $release = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-functional-v2';
    $previous = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-modules-performance-v1';
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__FILE__) !== $release.'/deploy/verify-chat-experience-schema.php') throw new LogicException('Fixed Linux release required');
    $arguments = array_slice($argv, 1);
    $file = '/var/www/svs-laravel/shared/chat-experience-backups/before-1100-20261104103950-bce2422c1f47058bbd309bf15e70aea8.cuenc';
    if ($arguments !== ['--backup-file='.$file]) throw new LogicException('Pinned v1 backup required');
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
    $parents = ['tenants','accounts','chat_messages','migrations'];
    $snapshot = function () use ($db, $parents): array {
        $data = [];
        foreach ($parents as $table) {
            $rows = $db->table('clubora.'.$table)->when($table === 'migrations', fn ($q) => $q->where('migration', '<>', '2026_11_04_001100_chat_experience'))->orderBy('id')->limit(20001)->get()->all();
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
    if (is_link($migration) || !hash_equals($expectedHash, hash_file('sha256', $migration))) throw new LogicException('Fixed migration source required');
    $directory = dirname($file);
    if (is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700 || fileowner($directory) !== posix_geteuid()
        || !is_file($file) || is_link($file) || realpath($file) !== $file || (fileperms($file) & 0777) !== 0600
        || fileowner($file) !== posix_geteuid() || lstat($file)['nlink'] !== 1 || filesize($file) > 100000000) throw new LogicException('Private pinned backup required');
    $backup = json_decode(Crypt::decryptString(file_get_contents($file)), false, 512, JSON_THROW_ON_ERROR);
    if ($backup->release !== '/var/www/svs-laravel/releases/20261104-clubunio-mobile-functional-v1'
        || $backup->migration_hash !== $expectedHash || $backup->created_at !== 1793788790) throw new LogicException('Pinned v1 backup header mismatch');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout TO '30s'");
        if ($json($snapshot()) !== $json($backup->parents)) throw new LogicException('Parent rows or metadata changed since v1 backup');
        $ledger = $db->table('clubora.migrations')->where('migration', $name)->get();
        $oldBatches = array_map(fn ($row) => (int) $row->batch, $backup->parents->migrations->rows);
        if ($ledger->count() !== 1 || (int) $ledger[0]->batch !== max([0, ...$oldBatches]) + 1) throw new LogicException('Exact additive1100 ledger required');
        $expected = [
            'chat_preferences' => [
                'columns' => [['tenant_id','bigint',null],['account_id','bigint',null],['translation_enabled','boolean','false'],['target_language','character varying(10)',"'de'::character varying"],['revision','integer','1'],['updated_at','timestamp with time zone','now()']],
                'pk' => ['tenant_id','account_id'],
                'fk' => [['tenant_id','tenants','id'],['account_id','accounts','id']],
                'checks' => ['revision>0',"target_language=ANYARRAY['de','en','fr','it','pt-PT']"],
            ],
            'chat_reactions' => [
                'columns' => [['tenant_id','bigint',null],['message_id','bigint',null],['account_id','bigint',null],['emoji','character varying(16)',null],['updated_at','timestamp with time zone','now()']],
                'pk' => ['message_id','account_id'],
                'fk' => [['tenant_id,message_id','chat_messages','tenant_id,id'],['account_id','accounts','id']],
                'checks' => ["emoji=ANYARRAY['👍','❤️','😂','😮','😢','🙏']"],
            ],
        ];
        foreach ($expected as $table => $contract) {
            $relation = 'clubora.'.$table;
            $kind = $db->selectOne('SELECT relkind,relpersistence,relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=to_regclass(?)', [$relation]);
            if (!$kind || $kind->relkind !== 'r' || $kind->relpersistence !== 'p' || $kind->relrowsecurity || $kind->relforcerowsecurity) throw new LogicException('Exact ordinary table required');
            $columns = $db->select("SELECT attname,format_type(atttypid,atttypmod) AS type,attnotnull,attidentity,attgenerated,pg_get_expr(d.adbin,d.adrelid) AS default_expression FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum", [$relation]);
            if (count($columns) !== count($contract['columns'])) throw new LogicException('Exact column inventory required');
            foreach ($columns as $i => $column) {
                if ([$column->attname,$column->type,$column->default_expression] !== $contract['columns'][$i] || !$column->attnotnull || $column->attidentity !== '' || $column->attgenerated !== '') throw new LogicException('Exact column contract required');
            }
            $constraints = $db->select("SELECT contype,convalidated,condeferrable,confupdtype,confdeltype,confmatchtype,pg_get_expr(conbin,conrelid) AS expression,
                (SELECT string_agg(a.attname,',' ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.num) AS columns,
                (SELECT n.nspname||'.'||r.relname FROM pg_class r JOIN pg_namespace n ON n.oid=r.relnamespace WHERE r.oid=c.confrelid) AS target,
                (SELECT string_agg(a.attname,',' ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.num) AS target_columns
                FROM pg_constraint c WHERE conrelid=to_regclass(?) AND contype <> 'n'", [$relation]);
            $pk=[]; $fk=[]; $checks=[];
            foreach ($constraints as $constraint) {
                if (!$constraint->convalidated || $constraint->condeferrable) throw new LogicException('Validated immediate constraints required');
                if ($constraint->contype === 'p') $pk[] = $constraint->columns;
                elseif ($constraint->contype === 'f') {
                    if ($constraint->confupdtype !== 'a' || $constraint->confdeltype !== 'a' || $constraint->confmatchtype !== 's' || !str_starts_with($constraint->target, 'clubora.')) throw new LogicException('Exact FK policy required');
                    $fk[] = [$constraint->columns,substr($constraint->target,8),$constraint->target_columns];
                } elseif ($constraint->contype === 'c') {
                    $checks[] = preg_replace(['/::(?:character varying|text)(?:\[\])?/','/[\s()]/u'], '', $constraint->expression);
                } else throw new LogicException('Unexpected constraint');
            }
            $wantFk=$contract['fk']; sort($fk); sort($wantFk); sort($checks); $wantChecks=$contract['checks']; sort($wantChecks);
            if ($pk !== [implode(',', $contract['pk'])] || $fk !== $wantFk || $checks !== $wantChecks) throw new LogicException('Exact PK/FK/check contract required');
            $public = $db->selectOne("SELECT (SELECT count(*) FROM pg_class c CROSS JOIN LATERAL aclexplode(coalesce(c.relacl,acldefault('r',c.relowner))) acl WHERE c.oid=to_regclass(?) AND acl.grantee=0) + (SELECT count(*) FROM pg_attribute a CROSS JOIN LATERAL aclexplode(a.attacl) acl WHERE a.attrelid=to_regclass(?) AND acl.grantee=0) AS n", [$relation,$relation]);
            if ((int) $public->n !== 0 || $db->table($relation)->exists()) throw new LogicException('Private empty new tables required');
        }
        echo "Read-only v2 schema1100 and pinned v1 parent backup verified; no schema reapplied.\n";
    } finally { $db->rollBack(); }
} catch (Throwable $error) { fwrite(STDERR, 'Chat schema verification: '.get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); }
