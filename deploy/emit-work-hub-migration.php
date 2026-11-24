<?php
// Output SQL only. Execute as PostgreSQL owner against the named DEV database.
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='clubunio_dev')exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$migration=require dirname(__DIR__).'/database/clubunio/2026_11_24_010000_work_hub.php';
echo <<<'SQL'
BEGIN;
DO $$ BEGIN
 IF current_database()<>'clubunio_dev' OR current_user<>'postgres' THEN RAISE EXCEPTION 'DEV owner required'; END IF;
 IF EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_010000_work_hub') THEN RAISE EXCEPTION 'Already applied'; END IF;
END $$;
SET LOCAL lock_timeout='5s';
SET LOCAL statement_timeout='30s';
SELECT pg_advisory_xact_lock(hashtext('clubunio-dev-work-hub-migration'));

SQL;
echo str_replace('clubora.','clubunio.',$migration->sql())."\n";
echo <<<'SQL'
GRANT SELECT,INSERT,UPDATE ON clubunio.workspace_tasks TO clubunio_dev_app;
GRANT SELECT,INSERT ON clubunio.workspace_task_events,clubunio.chat_mentions,clubunio.workspace_capture_commands TO clubunio_dev_app;
GRANT USAGE,SELECT ON SEQUENCE clubunio.workspace_tasks_id_seq,clubunio.workspace_task_events_id_seq,clubunio.chat_mentions_id_seq TO clubunio_dev_app;
INSERT INTO clubunio.migrations(migration,batch) SELECT '2026_11_24_010000_work_hub',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;
COMMIT;

SQL;
