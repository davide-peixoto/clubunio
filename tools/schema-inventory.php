<?php

// Metadata only, through the pilot's SELECT-only DEV connection.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('legacy');
$queries = [
    'visibility' => "SELECT HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'VIEW DEFINITION') AS can_view_definition",
    'objects' => "SELECT type_desc, COUNT(*) AS count FROM sys.objects WHERE is_ms_shipped=0 GROUP BY type_desc ORDER BY type_desc",
    'tables' => "SELECT t.name, (SELECT COUNT(*) FROM sys.columns c WHERE c.object_id=t.object_id) AS columns, (SELECT COUNT(*) FROM sys.indexes i WHERE i.object_id=t.object_id AND i.is_primary_key=1) AS has_pk FROM sys.tables t WHERE t.is_ms_shipped=0 ORDER BY t.name",
    'views' => "SELECT name FROM sys.views WHERE is_ms_shipped=0 ORDER BY name",
    'routines' => "SELECT o.name, o.type_desc, OBJECT_NAME(o.parent_object_id) AS parent_name FROM sys.objects o WHERE o.is_ms_shipped=0 AND o.type IN ('P','FN','TR','IF','TF') ORDER BY o.type_desc, o.name",
    'types' => "SELECT ty.name, COUNT(*) AS count FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE t.is_ms_shipped=0 GROUP BY ty.name ORDER BY ty.name",
    'settings_columns' => "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME LIKE 'ini%' ORDER BY TABLE_NAME, ORDINAL_POSITION",
    'foreign_keys' => "SELECT OBJECT_NAME(parent_object_id) AS source_table, OBJECT_NAME(referenced_object_id) AS target_table, is_disabled, is_not_trusted FROM sys.foreign_keys ORDER BY source_table, target_table",
    'special_columns' => "SELECT t.name AS table_name, c.name, c.is_identity, c.is_computed FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id WHERE t.is_ms_shipped=0 AND (c.is_identity=1 OR c.is_computed=1 OR c.name LIKE '%mandant%' OR c.name LIKE '%tenant%') ORDER BY t.name, c.name",
    'view_dependencies' => "SELECT OBJECT_NAME(d.referencing_id) AS view_name, d.referenced_schema_name, d.referenced_entity_name FROM sys.sql_expression_dependencies d JOIN sys.views v ON v.object_id=d.referencing_id ORDER BY view_name",
];
try {
    $result = [];
    foreach ($queries as $name => $sql) {
        $result[$name] = $db->select($sql);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Metadata inventory failed: '.get_class($e).PHP_EOL);
    exit(1);
}
