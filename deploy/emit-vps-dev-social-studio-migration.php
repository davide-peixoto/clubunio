<?php

// Generate reviewed SQL only. Run it as the DEV schema owner, not the web user.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'clubunio_dev') {
    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';
$migration = require dirname(__DIR__).'/database/clubunio/2026_11_13_000100_social_studio.php';
$ddl = str_replace('clubora.', 'clubunio.', $migration->sql());
echo <<<'SQL'
BEGIN;
DO $$ BEGIN
    IF current_database() <> 'clubunio_dev' OR current_user <> 'postgres' THEN
        RAISE EXCEPTION 'Unexpected DEV migration identity';
    END IF;
END $$;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
SELECT pg_advisory_xact_lock(hashtext('clubunio-dev-social-studio-migration'));

SQL;
echo $ddl."\n";
echo <<<'SQL'
GRANT SELECT, INSERT, UPDATE ON
    clubunio.social_media, clubunio.social_templates, clubunio.social_template_versions,
    clubunio.social_results, clubunio.social_posts, clubunio.social_deliveries
    TO clubunio_dev_app;
INSERT INTO clubunio.migrations(migration,batch)
SELECT '2026_11_13_000100_social_studio', COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;
COMMIT;

SQL;
