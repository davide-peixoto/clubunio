<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$options = getopt('', ['root:', 'tenant:']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if (! $root || ! is_file($root.'/bootstrap/app.php')) { throw new LogicException('Application root required'); }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../app/ClubUnio/Services/SeasonReferenceAudit.php';
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected audit database'); }
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $tenant = $db->table('clubora.tenants')->where('slug', $options['tenant'] ?? 'sv-sissach')->value('id');
    if (! $tenant) { throw new LogicException('Unknown audit tenant'); }
    $report = (new App\ClubUnio\Services\SeasonReferenceAudit)->inspect($db, (int) $tenant);
    $report['read_only'] = $db->selectOne("SELECT current_setting('transaction_read_only') AS value")->value === 'on';
    $report['basis'] = 'clubora versus legacy_prod imported snapshot; not live DEV/PROD';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally { $db->rollBack(); }
