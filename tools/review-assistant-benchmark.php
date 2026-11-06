<?php

// Offline rescoring and aggregation only; no application boot, API key or HTTP client.
require __DIR__.'/assistant-benchmark/Benchmark.php';
use ClubUnio\Tools\AssistantBenchmark\Benchmark;

if (PHP_SAPI !== 'cli' || $argc !== 2 || !preg_match('/^assistant-benchmark-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/D', $argv[1])) exit(1);
$root = __DIR__.'/../storage/app/private/'.$argv[1];
$metadata = json_decode(file_get_contents($root.'/metadata.json'), true, flags: JSON_THROW_ON_ERROR);
if (($metadata['synthetic_only'] ?? null) !== true || !hash_equals($metadata['case_sha256'], hash_file('sha256', __DIR__.'/assistant-benchmark/cases.php'))) throw new RuntimeException('Exact synthetic fixture required');
$cases = array_column(require __DIR__.'/assistant-benchmark/cases.php', null, 'id');
$rows = []; $seen = []; $changes = [];
foreach (glob($root.'/task-*.json') as $file) {
    $row = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if (!isset($cases[$row['case']], Benchmark::MODELS[$row['model']]) || !in_array($row['repeat'], [1,2,3], true)) throw new RuntimeException('Invalid result');
    $key = $row['model'].'/'.$row['case'].'/'.$row['repeat'];
    if (isset($seen[$key])) throw new RuntimeException('Duplicate result');
    $seen[$key] = true;
    $grade = Benchmark::grade($cases[$row['case']], $row['calls'], $row['text'], $row['complete']);
    if ($grade !== $row['grade']) $changes[] = ['task' => $key, 'before' => $row['grade'], 'after' => $grade];
    $row['reviewed_grade'] = $grade; $rows[] = $row;
}
if (count($rows) !== count($cases)*6) throw new RuntimeException('Complete paired run required');
$report = ['scorer_sha256' => hash_file('sha256', __DIR__.'/assistant-benchmark/Benchmark.php'), 'scoring_changes' => $changes, 'models' => []];
foreach (array_keys(Benchmark::MODELS) as $model) {
    $subset = array_values(array_filter($rows, fn ($r) => $r['model'] === $model));
    $usage = array_merge(...array_column($subset, 'usage'));
    $cost = array_sum(array_column($subset, 'cost_microusd'));
    $cold = array_sum(array_map(fn ($u) => (int)ceil($u['input'] * Benchmark::MODELS[$model]['input'] + $u['output'] * Benchmark::MODELS[$model]['output']), $usage));
    $passes = count(array_filter($subset, fn ($r) => $r['reviewed_grade']['pass']));
    $report['models'][$model] = ['tasks' => count($subset), 'raw_passed' => count(array_filter($subset, fn ($r) => $r['grade']['pass'])),
        'reviewed_passed' => $passes, 'safe' => count(array_filter($subset, fn ($r) => $r['reviewed_grade']['safety'])), 'http_requests' => count($usage),
        'input_tokens' => array_sum(array_column($usage, 'input')), 'cached_tokens' => array_sum(array_column($usage, 'cached')),
        'cache_write_tokens' => array_sum(array_column($usage, 'cache_writes')), 'output_tokens' => array_sum(array_column($usage, 'output')),
        'reasoning_tokens' => array_sum(array_column($usage, 'reasoning')), 'observed_cost_usd' => $cost/1e6,
        'per_reviewed_pass_usd' => $passes ? $cost/1e6/$passes : null,
        'uncached_arithmetic_usd_not_measurement' => $cold/1e6,
        'median_ms' => Benchmark::percentile(array_column($subset, 'elapsed_ms'), .5), 'p95_ms' => Benchmark::percentile(array_column($subset, 'elapsed_ms'), .95)];
}
file_put_contents($root.'/review.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
