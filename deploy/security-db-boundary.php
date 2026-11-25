<?php

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['stage','prod'], true)) exit(1);
$env = $argv[1];
$root = '/home/runcloud/webapps/ClubUnio'.($env === 'stage' ? 'Stage' : 'Prod');
require $root.'/vendor/autoload.php';
$settings = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.postgresql.local'));
if (($settings['PGUSER'] ?? null) !== 'clubunio_'.$env.'_app' || ($settings['PGDATABASE'] ?? null) !== 'clubunio_'.$env) exit(1);
$results = [];
foreach ([$env === 'stage' ? 'clubunio_prod' : 'clubunio_stage', 'keycloak'] as $database) {
    try {
        $connection = new PDO('pgsql:host=127.0.0.1;port=5432;sslmode=require;dbname='.$database,
            $settings['PGUSER'], $settings['PGPASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $results[$database] = false;
        $connection = null;
    } catch (PDOException) {
        $results[$database] = true;
    }
}
$own = new PDO('pgsql:host=127.0.0.1;port=5432;sslmode=require;dbname=clubunio_'.$env,
    $settings['PGUSER'], $settings['PGPASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$row = $own->query("SELECT has_schema_privilege(current_user,'clubunio','CREATE') AS schema_create,
    has_database_privilege(current_user,current_database(),'CREATE') AS database_create")->fetch(PDO::FETCH_ASSOC);
$results['no_permanent_ddl'] = !$row['schema_create'] && !$row['database_create'];
$ok = !in_array(false, $results, true);
echo json_encode(['environment' => $env, 'boundaries_passed' => $ok, 'checks' => $results], JSON_THROW_ON_ERROR).PHP_EOL;
exit($ok ? 0 : 1);
