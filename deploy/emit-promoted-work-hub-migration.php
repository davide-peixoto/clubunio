<?php
// Emit only the reviewed additive migration; never connect or execute here.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['clubunio_stage', 'clubunio_prod'], true)) exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$database=$argv[1];
$migration=require dirname(__DIR__).'/database/clubunio/2026_11_24_010000_work_hub.php';
echo "BEGIN;\nSET LOCAL lock_timeout='5s';\nSET LOCAL statement_timeout='30s';\n";
echo "DO \$\$ BEGIN IF current_database()<>'$database' OR current_user<>'postgres' THEN RAISE EXCEPTION 'Wrong owner/target'; END IF; IF EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_010000_work_hub') THEN RAISE EXCEPTION 'Already applied'; END IF; END \$\$;\n";
echo "SELECT pg_advisory_xact_lock(hashtext('clubunio-work-hub-promotion'));\n";
echo str_replace('clubora.','clubunio.',$migration->sql())."\n";
echo "GRANT SELECT,INSERT,UPDATE ON clubunio.workspace_tasks TO userunio;\n";
echo "GRANT SELECT,INSERT ON clubunio.workspace_task_events,clubunio.chat_mentions,clubunio.workspace_capture_commands TO userunio;\n";
echo "GRANT USAGE,SELECT ON SEQUENCE clubunio.workspace_tasks_id_seq,clubunio.workspace_task_events_id_seq,clubunio.chat_mentions_id_seq TO userunio;\n";
echo "INSERT INTO clubunio.migrations(migration,batch) SELECT '2026_11_24_010000_work_hub',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;\nCOMMIT;\n";
