<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['root:', 'actor:', 'preview', 'rollback-test', 'apply']);
if (count(array_intersect(['preview', 'rollback-test', 'apply'], array_keys($options))) !== 1) { throw new LogicException('Choose exactly one explicit execution mode'); }
$root = realpath($options['root'] ?? dirname(__DIR__));
if (! $root || ! is_file($root.'/bootstrap/app.php')) { throw new LogicException('Application root required'); }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../app/ClubUnio/Services/ExpenseSeasonTeamCorrection.php';
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') { throw new LogicException('Unexpected correction database'); }
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if (isset($options['preview'])) { $db->statement('SET TRANSACTION READ ONLY'); }
    $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
    if (! $tenant) { throw new LogicException('Expected SV Sissach tenant'); }
    $repair = new App\ClubUnio\Services\ExpenseSeasonTeamCorrection;
    if (isset($options['preview'])) {
        $snapshot = $repair->snapshot($db, (int) $tenant);
        echo json_encode(['preview' => true, 'already_applied' => $snapshot['already_applied'], 'changes' => $snapshot['changes']], JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        $actor = $db->table('clubora.accounts as a')->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
            ->where('ta.tenant_id', $tenant)->where('ta.role', 'admin')->where('a.active', true)->where('a.api_only', false)
            ->where('a.username', $options['actor'] ?? '')->value('a.id');
        if (! $actor) { throw new LogicException('An existing active tenant admin must be named'); }
        $backupFile = null;
        $result = $repair->apply($db, (int) $tenant, (int) $actor, function ($snapshot) use ($options, $tenant, $actor, &$backupFile) {
            if (isset($options['rollback-test'])) { return; }
            $directory = '/var/www/svs-laravel/shared/backups';
            if (! is_dir($directory) || realpath($directory) !== $directory) { throw new LogicException('Existing private backup directory required'); }
            $backupFile = $directory.'/expense-season-teams-20261103-'.bin2hex(random_bytes(8)).'.cuenc';
            $payload = ['correction_key' => App\ClubUnio\Services\ExpenseSeasonTeamCorrection::KEY,
                'tenant_id' => $tenant, 'actor_id' => $actor, 'snapshot' => $snapshot];
            $plain = json_encode($payload, JSON_THROW_ON_ERROR);
            $sealed = Illuminate\Support\Facades\Crypt::encryptString($plain);
            $oldMask = umask(0077);
            try {
                $handle = fopen($backupFile, 'x');
                if (! $handle) { throw new LogicException('Cannot create exclusive private backup'); }
                try { if (fwrite($handle, $sealed) !== strlen($sealed) || ! fflush($handle) || ! fsync($handle)) { throw new LogicException('Incomplete backup'); } }
                finally { fclose($handle); }
            } finally { umask($oldMask); }
            if (is_link($backupFile) || (fileperms($backupFile) & 0077) !== 0
                || ! hash_equals($plain, Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($backupFile)))) {
                throw new LogicException('Private backup verification failed');
            }
        });
        if (isset($options['apply'])) { $db->commit(); } else { $db->rollBack(); }
        echo json_encode($result + ['committed' => isset($options['apply']), 'backup_file' => $backupFile,
            'backup_sha256' => $backupFile ? hash_file('sha256', $backupFile) : null], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} finally { if ($db->transactionLevel() > 0) { $db->rollBack(); } }
