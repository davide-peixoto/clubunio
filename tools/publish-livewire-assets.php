<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\ConsoleOutput;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
if ($kernel->call('livewire:publish', ['--assets' => true, '--no-interaction' => true], new ConsoleOutput) !== 0) {
    exit(1);
}

$source = $root.'/vendor/livewire/livewire/dist';
$published = $app->publicPath('vendor/livewire');
foreach (['manifest.json', 'livewire.js', 'livewire.min.js', 'livewire.csp.js', 'livewire.csp.min.js'] as $file) {
    if (! is_readable($source.'/'.$file) || ! is_readable($published.'/'.$file)
        || hash_file('sha256', $source.'/'.$file) !== hash_file('sha256', $published.'/'.$file)) {
        fwrite(STDERR, "Missing or stale Livewire asset: $file\n");
        exit(1);
    }
}
echo "Published Livewire assets match the installed package.\n";
