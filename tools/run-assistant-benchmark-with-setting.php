<?php

// Credential broker only: one scoped setting read, never business records or live assistant activation.
try {
    if (PHP_SAPI !== 'cli' || !in_array($argc, [2,3], true) || $argv[1] !== '--run') throw new LogicException('Explicit --run required');
    if ($argc === 3 && !preg_match('/^--prior-run=assistant-benchmark-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/D', $argv[2])) throw new LogicException('Invalid prior run');
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('ClubUnio database required');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION READ ONLY');
        $db->statement("SET LOCAL statement_timeout='10s'");
        $row = $db->table('clubora.tenant_settings')->where('tenant_id', 4)->where('key', 'OPENAI_API_KEY')->sole(['encrypted_value', 'is_secret']);
        if (!$row->is_secret) throw new LogicException('Encrypted credential required');
        $key = trim(Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_value));
        if (strlen($key) < 20 || strlen($key) > 2048 || preg_match('/\s/', $key)) throw new LogicException('Invalid credential');
    } finally { $db->rollBack(); $db->disconnect(); }
    $process = proc_open(array_merge([PHP_BINARY, __DIR__.'/run-assistant-benchmark.php', '--run', '--key-stdin'], array_slice($argv,2)),
        [['pipe', 'r'], STDOUT, STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new LogicException('Isolated benchmark process unavailable');
    fwrite($pipes[0], $key); fclose($pipes[0]); unset($key);
    exit(proc_close($process));
} catch (Throwable $e) {
    fwrite(STDERR, 'Credential broker stopped: '.($e instanceof LogicException ? $e->getMessage() : get_class($e)).PHP_EOL);
    exit(1);
}
