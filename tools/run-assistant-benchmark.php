<?php

// Standalone synthetic harness: deliberately never load bootstrap/app.php or .env.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/assistant-benchmark/Benchmark.php';

use ClubUnio\Tools\AssistantBenchmark\Benchmark;
use GuzzleHttp\Client;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

$directory = null;
try {
    if (PHP_SAPI !== 'cli') exit(1);
    $cases = require __DIR__.'/assistant-benchmark/cases.php';
    $run = in_array('--run', $argv, true);
    // Overall experiment cap includes the discarded pilot and one uncertain in-flight call.
    $pilotDirectory = null;
    foreach (array_slice($argv, 1) as $arg) if (str_starts_with($arg, '--prior-run=')) {
        $name = substr($arg, 12);
        if (!preg_match('/^assistant-benchmark-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/D', $name)) throw new RuntimeException('Invalid prior run');
        $pilotDirectory = __DIR__.'/../storage/app/private/'.$name;
        if (!is_dir($pilotDirectory)) throw new RuntimeException('Prior run missing');
    }
    $pilotSpent = 0;
    foreach ($pilotDirectory ? glob($pilotDirectory.'/task-*.json') : [] as $file) $pilotSpent += json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['cost_microusd'];
    $budget = 3000000 - $pilotSpent - ($pilotDirectory ? 200000 : 0);
    $key = getenv('CLUBUNIO_BENCHMARK_OPENAI_KEY') ?: '';
    if (in_array('--key-stdin', $argv, true)) $key = trim(stream_get_contents(STDIN, 4096));
    $container = new Container;
    Container::setInstance($container);
    $translator = new Translator(new FileLoader(new Filesystem, __DIR__.'/../lang'), 'en');
    $container->instance('translator', $translator);
    $toolSets = [];
    foreach (['de', 'en'] as $locale) {
        $translator->setLocale($locale);
        $toolSets[$locale] = array_merge((new App\ClubUnio\Assistant\AssistantReadTools)->definitions(), (new App\ClubUnio\Assistant\AssistantActionTools)->definitions());
    }
    if (!$run) {
        echo json_encode(['mode' => 'dry-run', 'cases' => count($cases), 'tasks' => count($cases)*6, 'models' => array_keys(Benchmark::MODELS), 'reasoning' => 'low', 'budget_usd' => 3, 'database_access' => false, 'key_present' => $key !== ''], JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }
    if ($key === '') throw new RuntimeException('Benchmark API key missing');
    $directory = __DIR__.'/../storage/app/private/assistant-benchmark-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (!mkdir($directory, 0700, true)) throw new RuntimeException('Private result directory unavailable');
    $write = function (string $file, array $data, bool $append = false) use ($directory, $key) {
        $bytes = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (str_contains($bytes, $key)) throw new RuntimeException('Credential output blocked');
        if (file_put_contents($directory.'/'.$file, $bytes, $append ? FILE_APPEND : 0) === false) throw new RuntimeException('Result persistence failed');
    };
    $write('metadata.json', ['synthetic_only' => true, 'created_utc' => gmdate('c'), 'models' => Benchmark::MODELS,
        'case_sha256' => hash_file('sha256', __DIR__.'/assistant-benchmark/cases.php'), 'harness_sha256' => hash_file('sha256', __FILE__),
        'scorer_sha256' => hash_file('sha256', __DIR__.'/assistant-benchmark/Benchmark.php'),
        'tool_schemas_sha256' => hash('sha256', json_encode($toolSets)), 'reasoning' => 'low', 'repetitions' => 3,
        'max_output_tokens' => 2000, 'max_rounds' => 4, 'budget_microusd' => $budget, 'excluded_pilot_microusd' => $pilotSpent,
        'excluded_pilot_uncertain_reserve_microusd' => $pilotDirectory ? 200000 : 0, 'provider_flags_changed' => false]);
    $client = new Client(['connect_timeout' => 5, 'timeout' => 40, 'http_errors' => false, 'allow_redirects' => false]);
    $spent = 0; $reservedUnknown = 0; $records = []; $disabled = []; $halt = false;
    for ($repeat = 1; $repeat <= 3 && !$halt; $repeat++) foreach ($cases as $index => $case) {
        $models = array_keys(Benchmark::MODELS);
        if (($repeat + $index) % 2 === 0) $models = array_reverse($models);
        foreach ($models as $model) {
            if (isset($disabled[$model]) || $halt) continue;
            $input = [['role' => 'user', 'content' => $case['prompt']]];
            $calls = []; $text = ''; $complete = false; $usage = []; $error = null; $taskCost = 0; $start = hrtime(true);
            for ($round = 0; $round < 4; $round++) {
                $payload = ['model' => $model, 'service_tier' => 'default', 'store' => false, 'reasoning' => ['effort' => 'low'],
                    'max_output_tokens' => 2000, 'parallel_tool_calls' => false, 'include' => ['reasoning.encrypted_content'], 'instructions' => Benchmark::instructions($case['locale']),
                    'tools' => $toolSets[$case['locale']], 'input' => $input];
                if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 60000) { $error = 'payload_limit'; break; }
                if ($spent + $reservedUnknown + 300000 > $budget) { $error = 'budget_limit'; $halt = true; break; }
                // Reserve before sending; uncertain HTTP outcomes are not retried or unreserved.
                $reservedUnknown += 300000;
                try {
                    $response = $client->post('https://api.openai.com/v1/responses', ['headers' => ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'], 'json' => $payload]);
                    if ($response->getStatusCode() !== 200) {
                        $error = 'http_'.$response->getStatusCode();
                        $details = json_decode((string)$response->getBody(), true)['error'] ?? [];
                        $safe = [];
                        foreach (['type', 'code', 'param'] as $field) if (is_string($details[$field] ?? null) && preg_match('/^[A-Za-z0-9_.\[\]-]{1,100}$/D', $details[$field])) $safe[$field] = $details[$field];
                        $usage[] = ['http_error' => $safe];
                        if (in_array($response->getStatusCode(), [400,401,403,404,429], true)) $disabled[$model] = $error;
                        if ($response->getStatusCode() === 401) $halt = true;
                        break;
                    }
                    $body = json_decode((string)$response->getBody(), true, 128, JSON_THROW_ON_ERROR);
                    $u = Benchmark::usage($body['usage'] ?? [], $model);
                    $reservedUnknown -= 300000; $spent += $u['cost_microusd']; $taskCost += $u['cost_microusd'];
                    $usage[] = $u + ['reported_model' => $body['model'] ?? null, 'status' => $body['status'] ?? null];
                    if (($body['status'] ?? null) !== 'completed') { $error = 'incomplete'; break; }
                    $toolCalls = [];
                    foreach ($body['output'] ?? [] as $item) {
                        if (($item['type'] ?? '') === 'function_call') $toolCalls[] = $item;
                        elseif (($item['type'] ?? '') === 'message') foreach ($item['content'] ?? [] as $part) {
                            if (($part['type'] ?? '') === 'output_text') $text .= $part['text'];
                        }
                    }
                    if (!$toolCalls) { $complete = true; break; }
                    if (count($toolCalls) > 4) throw new RuntimeException('Tool limit');
                    // Preserve opaque reasoning items when continuing stateless reasoning-model tool calls.
                    foreach ($body['output'] ?? [] as $item) $input[] = $item;
                    foreach ($toolCalls as $call) {
                        $args = json_decode($call['arguments'], true, 32, JSON_THROW_ON_ERROR);
                        $schema = array_values(array_filter($toolSets[$case['locale']], fn ($t) => $t['name'] === $call['name']))[0] ?? null;
                        $valid = is_array($args) && $schema && Benchmark::valid($args, $schema['parameters']);
                        $calls[] = ['name' => $call['name'], 'arguments' => $args, 'schema_valid' => (bool)$valid];
                        $result = $valid ? Benchmark::fixture($case, $call['name'], $args) : ['error' => 'invalid_tool'];
                        $input[] = ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => json_encode($result, JSON_THROW_ON_ERROR)];
                    }
                } catch (Throwable $e) { $error = 'transport_or_response_failure'; break; }
            }
            $record = ['case' => $case['id'], 'repeat' => $repeat, 'locale' => $case['locale'], 'model' => $model,
                'elapsed_ms' => round((hrtime(true)-$start)/1e6, 1), 'calls' => $calls, 'text' => $text, 'usage' => $usage,
                'cost_microusd' => $taskCost, 'error' => $error, 'complete' => $complete, 'grade' => Benchmark::grade($case, $calls, $text, $complete)];
            $records[] = $record;
            $write('task-'.count($records).'.json', $record);
            echo $model.' '.$case['id'].' #'.$repeat.' '.($record['grade']['pass'] ? 'PASS' : 'FAIL').' '.round($record['elapsed_ms']).'ms '.($error ?? 'completed').PHP_EOL;
        }
    }
    $summary = ['spent_estimate_usd' => $spent/1e6, 'uncertain_reservation_usd' => $reservedUnknown/1e6, 'disabled_models' => $disabled, 'models' => []];
    foreach (array_keys(Benchmark::MODELS) as $model) {
        $rows = array_values(array_filter($records, fn ($r) => $r['model'] === $model));
        $success = array_values(array_filter($rows, fn ($r) => $r['grade']['pass']));
        $summary['models'][$model] = ['attempted' => count($rows), 'passed' => count($success),
            'safety_passed' => count(array_filter($rows, fn ($r) => $r['grade']['safety'])),
            'median_ms' => Benchmark::percentile(array_column($rows, 'elapsed_ms'), .5),
            'p95_ms' => Benchmark::percentile(array_column($rows, 'elapsed_ms'), .95),
            'cost_usd' => array_sum(array_column($rows, 'cost_microusd'))/1e6,
            'cost_per_pass_usd' => count($success) ? array_sum(array_column($rows, 'cost_microusd'))/1e6/count($success) : null];
    }
    $write('summary.json', $summary);
    echo json_encode(['result_directory' => realpath($directory), 'summary' => $summary], JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Benchmark stopped: '.($e instanceof RuntimeException ? $e->getMessage() : get_class($e)).PHP_EOL);
    exit(1);
}
