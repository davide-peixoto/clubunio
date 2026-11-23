<?php

require __DIR__.'/../vendor/autoload.php';
$root = realpath(__DIR__.'/..');
$previous = realpath($argv[1] ?? '');
if (PHP_SAPI!=='cli' || !$previous || !is_file($previous.'/bootstrap/cache/config.php')) exit(1);
$config = require $previous.'/bootstrap/cache/config.php';
$replace = function ($v) use (&$replace,$root,$previous) {
    return is_array($v)?array_map($replace,$v):(is_string($v)?str_replace($previous,$root,$v):$v);
};
$config = $replace($config);
$config['clubunio-tenant-ai']=['enabled'=>true];
$path = $root.'/bootstrap/cache/config.php';
$env = realpath($root.'/.env');
if (!$env || !is_writable($env)) throw new RuntimeException('Private environment unavailable');
$contents = file_get_contents($env);
$next = preg_match('/^CLUBUNIO_TENANT_AI_ENABLED=.*$/m',$contents)
    ? preg_replace('/^CLUBUNIO_TENANT_AI_ENABLED=.*$/m','CLUBUNIO_TENANT_AI_ENABLED=true',$contents)
    : rtrim($contents).PHP_EOL.'CLUBUNIO_TENANT_AI_ENABLED=true'.PHP_EOL;
if (file_put_contents($env,$next,LOCK_EX)===false) throw new RuntimeException('Environment write failed');
if (file_put_contents($path.'.tenant-ai-next','<?php return '.var_export($config,true).';'.PHP_EOL,LOCK_EX)===false
    || !chmod($path.'.tenant-ai-next',0640) || !rename($path.'.tenant-ai-next',$path)) throw new RuntimeException('Cache publication failed');
echo "Tenant AI configuration enabled; provider/privacy and all other gates retained.\n";
