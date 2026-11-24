<?php

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'clubunio_dev') { exit(1); }
require dirname(__DIR__).'/vendor/autoload.php';
$migration = require dirname(__DIR__).'/database/clubunio/2026_11_24_021000_veo_fixture_anchor.php';
echo <<<'SQL'
BEGIN;
SET LOCAL lock_timeout='5s';
SET LOCAL statement_timeout='30s';
DO $$ BEGIN
 IF current_database()<>'clubunio_dev' OR current_user<>'postgres' THEN RAISE EXCEPTION 'DEV owner required'; END IF;
 IF EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_021000_veo_fixture_anchor') THEN RAISE EXCEPTION 'Already applied'; END IF;
END $$;
SELECT pg_advisory_xact_lock(hashtext('clubunio-dev-veo-follow-fixture'));

SQL;
echo str_replace('clubora.', 'clubunio.', $migration->sql())."\n";
echo <<<'SQL'
INSERT INTO clubunio.migrations(migration,batch) SELECT '2026_11_24_021000_veo_fixture_anchor',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;
COMMIT;

SQL;
