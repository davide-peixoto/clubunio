<?php

// Read-only metadata audit; never import, repair or print customer rows/secrets.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Reviewed ClubUnio database required');
}
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $tables = $db->select("SELECT table_name FROM information_schema.columns WHERE table_schema='clubora' AND column_name='tenant_id' ORDER BY table_name");
    $missing = $db->select(<<<'SQL'
SELECT c.conname, s.relname AS source_table, t.relname AS target_table,
 pg_get_constraintdef(c.oid) AS definition
FROM pg_constraint c
JOIN pg_class s ON s.oid=c.conrelid
JOIN pg_class t ON t.oid=c.confrelid
JOIN pg_namespace n ON n.oid=s.relnamespace
WHERE c.contype='f' AND n.nspname='clubora'
 AND EXISTS(SELECT 1 FROM pg_attribute a WHERE a.attrelid=s.oid AND a.attname='tenant_id' AND NOT a.attisdropped)
 AND EXISTS(SELECT 1 FROM pg_attribute a WHERE a.attrelid=t.oid AND a.attname='tenant_id' AND NOT a.attisdropped)
 AND NOT EXISTS(SELECT 1 FROM pg_attribute a WHERE a.attrelid=s.oid AND a.attname='tenant_id' AND a.attnum=ANY(c.conkey))
 AND NOT EXISTS(SELECT 1 FROM pg_constraint stronger
   JOIN pg_attribute a ON a.attrelid=stronger.conrelid AND a.attname='tenant_id'
   WHERE stronger.contype='f' AND stronger.conrelid=c.conrelid AND stronger.confrelid=c.confrelid
    AND c.conkey <@ stronger.conkey AND a.attnum=ANY(stronger.conkey) AND stronger.convalidated)
ORDER BY source_table, conname
SQL);
    echo json_encode(['tenant_tables' => count($tables), 'unpaired_tenant_foreign_keys' => $missing,
        'limits' => 'Metadata only. Polymorphic/raw joins and application authorization require tests/review. No RLS certification.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    $db->rollBack();
}
