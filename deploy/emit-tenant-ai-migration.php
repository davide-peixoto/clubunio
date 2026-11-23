<?php

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '',['clubunio_dev','clubunio_stage','clubunio_prod'],true)) exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$database = $argv[1];
$role = $database === 'clubunio_dev' ? 'clubunio_dev_app' : 'userunio';
$name = '2026_11_23_000200_tenant_ai';
$migration = require dirname(__DIR__).'/database/clubunio/'.$name.'.php';
echo "BEGIN;\nSET LOCAL lock_timeout='5s';\nSET LOCAL statement_timeout='30s';\n";
echo "DO \$\$ BEGIN IF current_database()<>'$database' OR current_user<>'postgres' THEN RAISE EXCEPTION 'Unexpected tenant AI migration target'; END IF; END \$\$;\n";
echo "SELECT pg_advisory_xact_lock(hashtext('clubunio-tenant-ai-migration'));\n";
echo $migration->sql()."\n";
echo "GRANT SELECT,INSERT,UPDATE ON clubunio.tenant_ai_connections,clubunio.tenant_ai_stores,clubunio.tenant_ai_documents,clubunio.tenant_ai_usage,clubunio.tenant_ai_events TO $role;\n";
echo "GRANT USAGE,SELECT ON SEQUENCE clubunio.tenant_ai_stores_id_seq,clubunio.tenant_ai_documents_id_seq,clubunio.tenant_ai_events_id_seq TO $role;\n";
echo "INSERT INTO clubunio.migrations(migration,batch) SELECT '$name',coalesce(max(batch),0)+1 FROM clubunio.migrations;\nCOMMIT;\n";
