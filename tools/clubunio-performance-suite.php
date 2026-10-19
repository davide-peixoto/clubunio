<?php

use Symfony\Component\Process\Process;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$runs = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 3;
if ($runs === false || $runs < 3 || $runs > 5) {
    fwrite(STDERR, "Use 3 to 5 repetitions.\n");
    exit(1);
}
$scope = $argv[2] ?? 'all';
if (! in_array($scope, ['all', 'read', 'synthetic'], true)) {
    fwrite(STDERR, "Use all, read or synthetic scope.\n");
    exit(1);
}
$php = PHP_OS_FAMILY === 'Windows' ? [PHP_BINARY, '-d', 'extension=pdo_pgsql'] : [PHP_BINARY];
$scenarios = [
    ['members-list', '/members', 'page'],
    ['members-filter-search', '/members', 'filter-search'],
    ['invoices-list', '/invoices', 'page'],
    ['expenses-list', '/expenses', 'page'],
    ['registrations-list', '/registrations', 'page'],
    ['member-report', '/member-reports', 'page'],
    ['invoice-detail', '/invoices/detail', 'page'],
    ['invoice-refresh', '/invoices/detail', 'detail-refresh'],
    ['member-detail', '/members/detail', 'page'],
    ['member-relations', '/members/dossier', 'relations'],
    ['planning-calendar', '/planning/calendar', 'page'],
    ['dashboard-widgets-sequential', '/', 'widgets'],
];
$median = function (array $values): float {
    sort($values);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
};
foreach ($scope === 'synthetic' ? [] : $scenarios as [$name, $path, $mode]) {
    $samples = [];
    for ($i = 0; $i < $runs; $i++) {
        $process = new Process([...$php, __DIR__.'/clubunio-performance-check.php', $path, $mode], dirname(__DIR__));
        $process->setTimeout(90);
        $process->run();
        $sample = null;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $candidate = json_decode($line, true);
            if (is_array($candidate) && isset($candidate['elapsed_ms'], $candidate['query_count'])) {
                $sample = $candidate;
            }
        }
        if (! $process->isSuccessful() || ($sample['status'] ?? 0) !== 200) {
            // Never emit HTML, database bindings, provider responses or identity details.
            fwrite(STDERR, 'Measurement failed: '.$name.PHP_EOL);
            exit(1);
        }
        $samples[] = $sample;
    }
    echo json_encode(['scenario' => $name, 'runs' => $runs, 'median_ms' => $median(array_column($samples, 'elapsed_ms')),
        'min_ms' => min(array_column($samples, 'elapsed_ms')), 'max_ms' => max(array_column($samples, 'elapsed_ms')),
        'median_queries' => $median(array_column($samples, 'query_count')), 'median_db_ms' => $median(array_column($samples, 'query_ms')),
        'max_peak_mb' => max(array_column($samples, 'peak_mb')), 'scope' => 'read-only-application-lifecycle'], JSON_THROW_ON_ERROR).PHP_EOL;
}

// Writes and PDF generation use only synthetic records, always rolled back by the child tool.
foreach ($scope === 'read' ? [] : ['clubunio-form-performance-check.php', 'clubunio-training-performance-check.php', 'clubunio-history-performance-check.php'] as $script) {
    $groups = [];
    for ($i = 0; $i < $runs; $i++) {
        $process = new Process([...$php, __DIR__.'/'.$script], dirname(__DIR__));
        $process->setTimeout(90);
        $process->run();
        $found = false;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $sample = json_decode($line, true);
            if (! is_array($sample) || ! isset($sample['elapsed_ms'], $sample['scenario'])) {
                continue;
            }
            if (($sample['rollback'] ?? false) !== true) {
                fwrite(STDERR, "Missing rollback declaration.\n");
                exit(1);
            }
            $groups[$sample['scenario']][] = $sample;
            $found = true;
        }
        if (! $process->isSuccessful() || ! $found) {
            fwrite(STDERR, 'Synthetic measurement failed: '.$script.PHP_EOL);
            exit(1);
        }
    }
    foreach ($groups as $name => $samples) {
        if (count($samples) !== $runs) {
            fwrite(STDERR, "Incomplete measurements.\n");
            exit(1);
        }
        echo json_encode(['scenario' => str_starts_with($name, 'synthetic-') ? $name : 'synthetic-'.$name, 'runs' => $runs,
            'median_ms' => $median(array_column($samples, 'elapsed_ms')), 'min_ms' => min(array_column($samples, 'elapsed_ms')),
            'max_ms' => max(array_column($samples, 'elapsed_ms')), 'median_queries' => $median(array_column($samples, 'queries')),
            'median_db_ms' => $median(array_column($samples, 'query_ms')), 'scope' => 'synthetic-rollback-lifecycle'], JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
