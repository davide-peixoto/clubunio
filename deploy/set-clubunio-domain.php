<?php

$path = '/var/www/svs-laravel/shared/.env.clubora';
$text = file_get_contents($path);
if (preg_match_all('/^APP_URL=.*$/m', $text) !== 1) {
    throw new RuntimeException('Expected one APP_URL');
}
$text = preg_replace('/^APP_URL=.*$/m', 'APP_URL=https://dev.clubunio.ch', $text);
if (file_put_contents($path, $text, LOCK_EX) === false) {
    throw new RuntimeException('Cannot update APP_URL');
}
echo "APP_URL updated\n";
