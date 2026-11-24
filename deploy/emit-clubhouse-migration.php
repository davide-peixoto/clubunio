<?php

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'clubunio_dev') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$migration = require dirname(__DIR__).'/database/clubunio/2026_11_24_030000_clubhouse.php';
echo <<<'SQL'
BEGIN;
SET LOCAL lock_timeout='5s';
SET LOCAL statement_timeout='30s';
SELECT pg_advisory_xact_lock(hashtext('clubunio-dev-clubhouse-migration'));
DO $$ BEGIN
 IF current_database()<>'clubunio_dev' OR current_user<>'postgres' THEN RAISE EXCEPTION 'DEV owner required'; END IF;
 IF EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_030000_clubhouse') THEN RAISE EXCEPTION 'Already applied'; END IF;
END $$;

SQL;
echo str_replace('clubora.', 'clubunio.', $migration->sql())."\n";
echo <<<'SQL'
GRANT SELECT,INSERT,UPDATE ON clubunio.clubhouse_settings,clubunio.clubhouse_grants,clubunio.clubhouse_opening_rules,clubunio.clubhouse_menus TO clubunio_dev_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON clubunio.clubhouse_opening_exceptions TO clubunio_dev_app;
GRANT SELECT,INSERT ON clubunio.clubhouse_menu_statuses,clubunio.clubhouse_events TO clubunio_dev_app;
GRANT USAGE,SELECT ON SEQUENCE clubunio.clubhouse_opening_rules_id_seq,clubunio.clubhouse_opening_exceptions_id_seq,clubunio.clubhouse_menus_id_seq,clubunio.clubhouse_events_id_seq TO clubunio_dev_app;
INSERT INTO clubunio.migrations(migration,batch) SELECT '2026_11_24_030000_clubhouse',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;
COMMIT;

SQL;
