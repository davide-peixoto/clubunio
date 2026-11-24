<?php

// Emit only; database execution is restricted to the reviewed DEV owner step.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'clubunio_dev') { exit(1); }
require dirname(__DIR__).'/vendor/autoload.php';
$migration = require dirname(__DIR__).'/database/clubunio/2026_11_24_020000_veo_recording_adjustments.php';
echo <<<'SQL'
BEGIN;
SET LOCAL lock_timeout='5s';
SET LOCAL statement_timeout='30s';
DO $$ BEGIN
 IF current_database()<>'clubunio_dev' OR current_user<>'postgres' THEN RAISE EXCEPTION 'DEV owner required'; END IF;
 IF EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_020000_veo_recording_adjustments') THEN RAISE EXCEPTION 'Already applied'; END IF;
END $$;
SELECT pg_advisory_xact_lock(hashtext('clubunio-dev-veo-calendar'));

SQL;
echo str_replace('clubora.', 'clubunio.', $migration->sql())."\n";
echo <<<'SQL'
GRANT SELECT,INSERT,UPDATE,DELETE ON clubunio.veo_recording_adjustments TO clubunio_dev_app;
INSERT INTO clubunio.migrations(migration,batch) SELECT '2026_11_24_020000_veo_recording_adjustments',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;
COMMIT;

SQL;
