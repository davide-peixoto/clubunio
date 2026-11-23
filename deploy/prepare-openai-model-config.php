<?php

// Patch only the reviewed AI keys in an existing private release cache. This
// preserves instance-specific gates while source defaults cover future builds.
require __DIR__.'/../vendor/autoload.php';
$root = realpath(__DIR__.'/..');
$previousRoot = realpath($argv[1] ?? '');
$output = $argv[2] ?? '';
if (PHP_SAPI !== 'cli' || !$previousRoot || !is_file($previousRoot.'/bootstrap/cache/config.php')
    || $output !== $root.'/bootstrap/cache/config.php') throw new RuntimeException('Explicit release cache paths required');
$config = require $previousRoot.'/bootstrap/cache/config.php';
if ($root !== $previousRoot) {
    $replace = function ($value) use (&$replace, $previousRoot, $root) {
        return is_array($value) ? array_map($replace, $value) : (is_string($value) ? str_replace($previousRoot, $root, $value) : $value);
    };
    $config = $replace($config);
}
$config['clubunio-assistant']['model'] = App\ClubUnio\Services\OpenAiModels::BACKOFFICE;
$config['clubunio-assistant']['request_reservation_microusd'] = 1_200_000;
$config['clubunio-mobile']['ai']['openai']['model'] = App\ClubUnio\Services\OpenAiModels::TEXT;
$config['clubunio-support']['model'] = App\ClubUnio\Services\OpenAiModels::TEXT;
$config['clubunio-mobile']['training_ai']['models'] = [App\ClubUnio\Services\OpenAiModels::TRAINING];
$temporary = $output.'.openai-next';
if (file_exists($temporary)) throw new RuntimeException('Candidate config already exists');
if (file_put_contents($temporary, '<?php return '.var_export($config, true).';'.PHP_EOL, LOCK_EX) === false
    || !chmod($temporary, 0640) || !rename($temporary, $output)) throw new RuntimeException('Config publication failed');
echo "AI model configuration updated; other configuration and feature gates preserved.\n";
