<?php

if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['clubunio_dev', 'clubunio_stage', 'clubunio_prod'], true)) {
    exit(1);
}
$database = $argv[1];
$role = $database.'_app';
require dirname(__DIR__).'/vendor/autoload.php';
$migration = require dirname(__DIR__).'/database/clubunio/2026_11_24_030000_clubhouse.php';
echo str_replace(['__DATABASE__', '__LOCK__'], [$database, $database.'-clubhouse-migration'], <<<'SQL'
BEGIN;
SET LOCAL lock_timeout='5s';
SET LOCAL statement_timeout='30s';
SELECT pg_advisory_xact_lock(hashtext('__LOCK__'));
DO $$ BEGIN
 IF current_database()<>'__DATABASE__' OR current_user<>'postgres' THEN RAISE EXCEPTION 'Reviewed database owner required'; END IF;
 IF EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_030000_clubhouse') THEN RAISE EXCEPTION 'Already applied'; END IF;
END $$;

SQL);
echo str_replace('clubora.', 'clubunio.', $migration->sql())."\n";
echo str_replace('__ROLE__', $role, <<<'SQL'
GRANT SELECT,INSERT,UPDATE ON clubunio.clubhouse_settings,clubunio.clubhouse_grants,clubunio.clubhouse_opening_rules,clubunio.clubhouse_menus TO __ROLE__;
GRANT SELECT,INSERT,UPDATE,DELETE ON clubunio.clubhouse_opening_exceptions TO __ROLE__;
GRANT SELECT,INSERT ON clubunio.clubhouse_menu_statuses,clubunio.clubhouse_events TO __ROLE__;
GRANT USAGE,SELECT ON SEQUENCE clubunio.clubhouse_opening_rules_id_seq,clubunio.clubhouse_opening_exceptions_id_seq,clubunio.clubhouse_menus_id_seq,clubunio.clubhouse_events_id_seq TO __ROLE__;
INSERT INTO clubunio.migrations(migration,batch) SELECT '2026_11_24_030000_clubhouse',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;
COMMIT;

SQL);
