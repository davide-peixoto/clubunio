<?php

// Build test-only fixture adapters; original tests and runtime configuration stay unchanged.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$directory = $root.'/storage/app/private/qa/bexio-20261124';
if (! is_dir($directory)) { mkdir($directory, 0700, true); }
$configuration = getenv('CLUBUNIO_BEXIO_QA_CONFIG') ?: $directory.'/connection.json';
$owned = !getenv('CLUBUNIO_BEXIO_QA_CONFIG');
if ($owned) {
    $values = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.stage-migration.local'));
    file_put_contents($configuration, json_encode(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 16439,
        'database' => 'clubunio_bexio_qa_20261124', 'username' => $values['PGUSER'], 'password' => $values['PGPASSWORD'],
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'clubora', 'sslmode' => 'disable',
        'options' => [PDO::PGSQL_ATTR_DISABLE_PREPARES => true]], JSON_THROW_ON_ERROR));
    unset($values);
}
$paths = array_merge(glob($root.'/tests/Feature/Bexio*Test.php'), array_map(fn ($name) => $root.'/tests/Feature/'.$name.'.php',
    ['CustomerCreditTest', 'CreditNoteTest', 'InvoiceDirectSandboxTest', 'InvoicePdfPerformanceTest', 'SyncWorkTest']));
try {
    foreach ($paths as $path) {
        if (! is_file($path)) { throw new LogicException('Missing selected test'); }
        $source = file_get_contents($path);
        $source = str_replace(['use Tests\\TestCase;', "'db_acdf8d_dclubora'"],
            ['use Tests\\Support\\BexioPostgresTestCase as TestCase;', "'clubunio_bexio_qa_20261124'"], $source);
        file_put_contents($directory.'/'.basename($path), $source);
    }
    putenv('CLUBUNIO_TEST_DB=1');
    putenv('CLUBUNIO_BEXIO_QA_CONFIG='.$configuration);
    putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
    putenv('APP_LOCALE=de');
    putenv('APP_FALLBACK_LOCALE=de');
    $command = [PHP_BINARY, $root.'/vendor/phpunit/phpunit/phpunit',
        '--configuration', $root.'/phpunit.xml', '--no-progress', '--log-junit', $directory.'/result.xml', $directory];
    if (in_array('--fail-fast', $argv, true)) { $command[] = '--stop-on-error'; $command[] = '--stop-on-failure'; }
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (! is_resource($process)) { throw new RuntimeException('Test runner could not start'); }
    $code = proc_close($process);
} finally {
    if ($owned && is_file($configuration)) { unlink($configuration); }
}
exit($code);
