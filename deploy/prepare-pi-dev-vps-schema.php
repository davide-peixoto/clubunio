<?php

if (PHP_SAPI !== 'cli' || ! preg_match('~^/var/www/svs-laravel/releases/[0-9]+-clubunio-vps-db$~D', getcwd())
    || getcwd() === realpath('/var/www/svs-laravel/current')) {
    exit(1);
}

$changed = 0;
foreach (['app', 'config', 'routes', 'resources', 'database/clubunio'] as $root) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink() || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $old = file_get_contents($path);
        $new = preg_replace('/\bclubora\./', 'clubunio.', $old);
        $new = preg_replace('/\b((?:search_path\s+(?:TO|=)|(?:CREATE|ALTER|DROP) SCHEMA(?: IF (?:NOT )?EXISTS)?|ON SCHEMA)\s+)clubora\b/i', '$1clubunio', $new);
        $new = preg_replace('/(getTableListing\(|search_path\x27\s*=>\s*)\x27clubora\x27/', '$1\'clubunio\'', $new);
        $new = preg_replace('/(table_schema\x27\s*,\s*|nspname\s*=\s*)\x27clubora\x27/', '$1\'clubunio\'', $new);
        if ($path === 'config/clubunio.php') {
            $new = str_replace("'host' => \$v['PGHOST'] ?? ''", "'host' => (\$v['PGDATABASE'] ?? '') === 'clubunio_dev' && (\$v['PGHOSTADDR'] ?? '') === '127.0.0.1' && (\$v['PGHOST'] ?? '') === 'clubunio.com' ? 'clubunio.com;hostaddr=127.0.0.1' : (\$v['PGHOST'] ?? '')", $new);
        }
        if ($new !== $old) {
            if (file_put_contents($path, $new) === false) {
                exit(1);
            }
            echo $path.PHP_EOL;
            $changed++;
        }
    }
}

exit($changed > 100 ? 0 : 1);
