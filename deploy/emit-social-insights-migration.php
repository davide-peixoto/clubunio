<?php

if (PHP_SAPI!=='cli' || !in_array($argv[1] ?? '',['clubunio_dev','clubunio_stage'],true)) exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$database=$argv[1]; $role=$database==='clubunio_dev'?'clubunio_dev_app':'userunio';
$name='2026_11_19_020000_social_insights';
$migration=require dirname(__DIR__).'/database/clubunio/'.$name.'.php';
echo "BEGIN;\nSET LOCAL lock_timeout='5s';\nSET LOCAL statement_timeout='30s';\n";
echo "DO \$\$ BEGIN IF current_database()<>'$database' OR current_user<>'postgres' THEN RAISE EXCEPTION 'Unexpected social migration target'; END IF; END \$\$;\n";
echo "SELECT pg_advisory_xact_lock(hashtext('clubunio-social-insights-migration'));\n";
echo $migration->sql()."\n";
echo "GRANT SELECT,INSERT,UPDATE ON clubunio.social_connections,clubunio.social_imported_posts,clubunio.social_metric_snapshots,clubunio.social_account_snapshots TO $role;\n";
echo "GRANT USAGE,SELECT ON SEQUENCE clubunio.social_connections_id_seq,clubunio.social_imported_posts_id_seq TO $role;\n";
echo "INSERT INTO clubunio.migrations(migration,batch) SELECT '$name',coalesce(max(batch),0)+1 FROM clubunio.migrations;\nCOMMIT;\n";
