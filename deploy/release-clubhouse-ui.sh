#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old="$base/releases/20261124-clubunio-clubhouse-v2"
next="$base/releases/20261125-clubunio-clubhouse-ui-v1"
test "$(readlink -f "$base/current")" = "$old"
cd "$old"
sha256sum -c "$base/shared/clubhouse-ui.baseline"
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/clubhouse-ui.tar.gz" -C "$next"
cd "$next"
sha256sum -c deploy/clubhouse-ui.manifest
cmp .env "$old/.env"
cmp composer.lock "$old/composer.lock"
test "$(readlink -f storage)" = "$base/shared/storage"
php -r '$f="bootstrap/cache/config.php";$s=str_replace($argv[1],$argv[2],file_get_contents($f));if(file_put_contents($f,$s)===false)exit(1);' "$old/" "$next/"
sudo chgrp -R www-data app lang resources public bootstrap/cache deploy
sudo chmod -R g+rX app lang resources public bootstrap/cache deploy
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php deploy/check-clubhouse.php
sudo -u www-data php artisan view:cache
test "$(readlink -f "$base/current")" = "$old"
sudo ln -s "$next" "$base/clubhouse-ui-next"
sudo mv -Tf "$base/clubhouse-ui-next" "$base/current"
sudo systemctl reload php8.3-fpm
if ! curl --fail --silent https://dev.clubunio.ch/up > /dev/null; then
    sudo ln -s "$old" "$base/clubhouse-ui-rollback"
    sudo mv -Tf "$base/clubhouse-ui-rollback" "$base/current"
    sudo systemctl reload php8.3-fpm
    exit 1
fi
echo 'Clubhouse UI corrections activated on DEV; no migration.'
