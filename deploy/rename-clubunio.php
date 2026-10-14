<?php

// One-time, source-only mechanical migration. Never traverses secrets or runtime data.
$root = realpath($argv[1] ?? dirname(__DIR__));
if (!$root || !is_file($root.'/artisan') || !is_dir($root.'/app/Clubora')) {
    throw new RuntimeException('Expected pre-migration Laravel source tree');
}
$files = [];
foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests', 'tools', 'deploy', 'public/css', 'public/js'] as $dir) {
    if (!is_dir($root.'/'.$dir)) { continue; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->isLink() || str_contains($path, '/bootstrap/cache/') || str_contains($path, '/filament/') || str_contains($path, '/pdfjs/') || $file->getFilename() === 'rename-clubunio.php') {
            continue;
        }
        if (in_array($file->getExtension(), ['php', 'css', 'js', 'md', 'sh', 'service', 'timer'], true)) {
            $files[] = $path;
        }
    }
}
$files[] = $root.'/README.md';
$names = [];
foreach ($files as $file) {
    $name = basename($file);
    $renamed = str_replace(['Clubora', 'CLUBORA', 'clubora'], ['ClubUnio', 'CLUBUNIO', 'clubunio'], $name);
    if ($name !== $renamed) {
        $names[$name] = $renamed;
    }
}
$changed = 0;
foreach ($files as $file) {
    if (!is_file($file)) { continue; }
    $old = file_get_contents($file);
    $protected = [];
    $text = preg_replace_callback('~Clubora Sandbox|/clubora(?:-[a-z-]+)?(?=/)|clubora-sandbox[-:]|clubora_staging_session|-//Clubora//|\.env\.clubora(?:-integrations\.local)?|[0-9]{8}-clubora-[a-z0-9-]+~', function ($m) use (&$protected) {
        $key = '__REBRAND_KEEP_'.count($protected).'__';
        $protected[$key] = $m[0];
        return $key;
    }, $old);
    $text = strtr($text, $names);
    $text = str_replace(['Clubora', 'CLUBORA'], ['ClubUnio', 'CLUBUNIO'], $text);
    $text = str_replace(["'clubora'", '"clubora"', 'auth:clubora', 'filament.clubora.', 'database.connections.clubora', 'clubora-integrations.', 'clubora.connection', 'clubora.outbound_enabled', 'clubora_test', 'clubora_users', 'clubora_training', 'clubora:'], ["'clubunio'", '"clubunio"', 'auth:clubunio', 'filament.clubunio.', 'database.connections.clubunio', 'clubunio-integrations.', 'clubunio.connection', 'clubunio.outbound_enabled', 'clubunio_test', 'clubunio_users', 'clubunio_training', 'clubunio:'], $text);
    // Only application route/view prefixes; SQL-qualified table names stay unchanged.
    $text = preg_replace('~clubora\.(?=portal\.|planning\.|training\.|bus\.|bexio[.-]|documents\.|messages\.|columns\.|views\.|signage[.-]|sfv[.-]|invoice\.|document\.|invoices\.(?:pdf|preview|viewer)|expenses\.document|registrations\.document|contribution-preview|registration-documents|registration-messages|planning-entry|club-logo)~', 'clubunio.', $text);
    $text = str_replace('clubora.clubora.', 'clubunio.clubora.', $text);
    $text = preg_replace('~(Rule::(?:unique|exists)\()\x27clubora\.\x27~', "$1'clubunio.'", $text);
    foreach (['app', 'resources/views', 'database', 'resources/css', 'resources/js'] as $prefix) {
        $text = str_replace($prefix.'/clubora', $prefix.'/clubunio', $text);
    }
    // CSS names and DOM IDs are internal; public legacy URL paths were protected above.
    $text = preg_replace('~clubora-(?!sandbox)~', 'clubunio-', $text);
    $text = str_replace("'search_path' => 'clubunio'", "'search_path' => 'clubora'", $text);
    $text = strtr($text, $protected);
    if ($old !== $text) {
        file_put_contents($file, $text);
        $changed++;
    }
}
// Deepest-first rename; all targets are validated inside this source tree.
$paths = [];
foreach (['app', 'config', 'database', 'resources', 'routes', 'tests', 'tools', 'deploy', 'public/css', 'public/js'] as $dir) {
    if (!is_dir($root.'/'.$dir)) { continue; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if (!$item->isLink() && preg_match('/clubora/i', $item->getFilename())) { $paths[] = $item->getPathname(); }
    }
}
usort($paths, fn ($a, $b) => strlen($b) <=> strlen($a));
foreach ($paths as $path) {
    $target = dirname($path).'/'.str_replace(['Clubora', 'CLUBORA', 'clubora'], ['ClubUnio', 'CLUBUNIO', 'clubunio'], basename($path));
    if (file_exists($target)) { throw new RuntimeException('Rename target already exists: '.$target); }
    if (!rename($path, $target)) { throw new RuntimeException('Rename failed: '.$path); }
}
echo 'Updated '.$changed.' files; renamed '.count($paths)." paths.\n";
