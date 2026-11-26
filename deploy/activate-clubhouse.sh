#!/usr/bin/env bash
set -euo pipefail
base=/var/www/svs-laravel
old="$base/releases/${1:?expected previous release}"
name=${2:-20261124-clubunio-clubhouse-v2}
case "$name" in 20261124-clubunio-clubhouse-v1|20261124-clubunio-clubhouse-v2) ;; *) exit 1;; esac
next="$base/releases/$name"
test "$(hostname -s)" = svs-pi-server
test "$(readlink -f "$base/current")" = "$old"
cd "$next"
sha256sum -c deploy/clubhouse.manifest
cmp .env "$old/.env"
cmp composer.lock "$old/composer.lock"
sudo -u www-data php deploy/check-clubhouse.php
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
test "$(readlink -f "$base/current")" = "$old"
sudo ln -s "$next" "$base/clubhouse-next"
sudo mv -Tf "$base/clubhouse-next" "$base/current"
sudo systemctl reload php8.3-fpm
if ! curl --fail --silent https://dev.clubunio.ch/up > /dev/null; then
    sudo ln -s "$old" "$base/clubhouse-rollback"
    sudo mv -Tf "$base/clubhouse-rollback" "$base/current"
    sudo systemctl reload php8.3-fpm
    exit 1
fi
echo 'Clubhouse DEV activated; previous release remains available.'
