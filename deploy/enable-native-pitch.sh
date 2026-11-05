#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261105-clubunio-training-halves
release=$base/releases/20261105-clubunio-native-pitch
package=$base/shared/native-pitch-20261105
test "$#" = 1
[[ "$1" =~ ^--(stage|activate)$ ]]
test "$(readlink -f "$base/current")" = "$previous"
if test "$1" = --stage; then
    test ! -e "$release" && test ! -L "$release"
    sudo cp -a "$previous" "$release"
    cd "$release"
    test ! -L .env
    cmp --silent .env "$previous/.env"
    test "$(readlink -f storage)" = "$base/shared/storage"
    test "$(readlink -f bootstrap/cache)" = "$release/bootstrap/cache"
    test -z "$(find bootstrap/cache -type l -print)"
    install -m 0640 "$package/check-native-pitch-activation.php" deploy/check-native-pitch-activation.php
    php deploy/check-native-pitch-activation.php --closed
    # Change exactly one release-private feature flag; preserve all other settings.
    php <<'PHP'
<?php
$file = '.env';
$before = file_get_contents($file);
$key = 'CLUBUNIO_MOBILE_NATIVE_PITCH_PLANNING_ENABLED';
$count = preg_match_all('/^'.preg_quote($key, '/').'=(.*)$/m', $before, $matches);
if ($count > 1 || ($count === 1 && !in_array(trim($matches[1][0]), ['', '0', 'false'], true))) throw new LogicException('Unexpected flag');
$after = $count === 1 ? preg_replace('/^'.preg_quote($key, '/').'=[^\r\n]*/m', $key.'=true', $before) : rtrim($before, "\r\n")."\n".$key."=true\n";
if (file_put_contents($file, $after, LOCK_EX) !== strlen($after)) throw new LogicException('Incomplete flag update');
chmod($file, 0640);
PHP
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    mkdir -p bootstrap/cache/training-half-views
    VIEW_COMPILED_PATH="$release/bootstrap/cache/training-half-views" php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    sudo chgrp -R www-data bootstrap/cache deploy/check-native-pitch-activation.php
    sudo chmod -R g+rwX bootstrap/cache
    sudo -u www-data php artisan view:cache >/dev/null
    php <<'PHP'
<?php
$old = '/var/www/svs-laravel/releases/20261105-clubunio-training-halves';
$new = getcwd();
$before = require $old.'/bootstrap/cache/config.php';
$after = require $new.'/bootstrap/cache/config.php';
if ($before['clubunio-mobile']['native_pitch_planning_enabled'] !== false || $after['clubunio-mobile']['native_pitch_planning_enabled'] !== true) throw new LogicException('Flag mismatch');
$after['clubunio-mobile']['native_pitch_planning_enabled'] = false;
if (str_replace($old, 'RELEASE_ROOT', json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== str_replace($new, 'RELEASE_ROOT', json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) throw new LogicException('Unexpected configuration change');
echo "Only native pitch flag and release-local paths changed.\n";
PHP
    cmp --silent composer.lock "$previous/composer.lock"
    sudo -u www-data php deploy/check-native-pitch-activation.php --enabled
    echo 'Inactive native pitch candidate ready.'
    exit 0
fi
cd "$release"
sudo -u www-data php deploy/check-native-pitch-activation.php --enabled
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
sudo -u www-data php deploy/check-native-pitch-activation.php --enabled
echo 'Native pitch enabled on ClubUnio only; previous release retains disabled flag.'
