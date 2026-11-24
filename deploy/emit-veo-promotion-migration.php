<?php

if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['clubunio_stage', 'clubunio_prod'], true)) { exit(1); }
require dirname(__DIR__).'/vendor/autoload.php';
$database = $argv[1];
echo "BEGIN;\nSET LOCAL lock_timeout='5s';\nSET LOCAL statement_timeout='30s';\n";
echo "DO \$\$ BEGIN IF current_database()<>'$database' OR current_user<>'postgres' THEN RAISE EXCEPTION 'Wrong Veo owner/target'; END IF; END \$\$;\n";
echo "SELECT pg_advisory_xact_lock(hashtext('clubunio-veo-promotion'));\n";
foreach (['2026_11_24_020000_veo_recording_adjustments', '2026_11_24_021000_veo_fixture_anchor'] as $name) {
    $migration = require dirname(__DIR__).'/database/clubunio/'.$name.'.php';
    echo "DO \$\$ BEGIN IF NOT EXISTS(SELECT 1 FROM clubunio.migrations WHERE migration='$name') THEN\n";
    echo str_replace('clubora.', 'clubunio.', $migration->sql())."\n";
    echo "INSERT INTO clubunio.migrations(migration,batch) SELECT '$name',COALESCE(MAX(batch),0)+1 FROM clubunio.migrations;\nEND IF; END \$\$;\n";
}
echo "GRANT SELECT,INSERT,UPDATE,DELETE ON clubunio.veo_recording_adjustments TO userunio;\nCOMMIT;\n";
