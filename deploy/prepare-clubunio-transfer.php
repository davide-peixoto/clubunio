<?php
// Private transport data for the Pi; never print credentials.
require dirname(__DIR__).'/vendor/autoload.php';
$target=$argv[1] ?? throw new RuntimeException('Output path required');
if (file_exists($target)) throw new RuntimeException('Output already exists');
$local=\Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env'));
$pg=\Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env.postgresql.local'));
if (($pg['PGDATABASE'] ?? '')!=='db_acdf8d_dclubora' || empty($local['APP_KEY'])) throw new RuntimeException('Invalid target');
$pg['PGSSLROOTCERT']='/etc/ssl/certs/ca-certificates.crt';
file_put_contents($target,json_encode(['key'=>$local['APP_KEY'],'pg'=>$pg],JSON_THROW_ON_ERROR));
echo "Private transfer prepared.\n";
