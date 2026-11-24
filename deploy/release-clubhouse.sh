#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old="$(readlink -f "$base/current")"
expected=${1:?expected current release}
test "$old" = "$base/releases/$expected"
name=${2:-20261124-clubunio-clubhouse-v2}
case "$name" in 20261124-clubunio-clubhouse-v1|20261124-clubunio-clubhouse-v2) ;; *) exit 1;; esac
next="$base/releases/$name"
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/clubhouse-package.tar.gz" -C "$next"
cd "$next"
sha256sum -c deploy/clubhouse.manifest
test "$(readlink -f storage)" = "$base/shared/storage"
cmp .env "$old/.env"
cmp composer.lock "$old/composer.lock"
php -r '$f="bootstrap/cache/config.php";$s=str_replace($argv[1],$argv[2],file_get_contents($f));if(file_put_contents($f,$s)===false)exit(1);' "$old/" "$next/"
sudo chgrp -R www-data app lang resources public bootstrap/cache deploy database/clubunio routes
sudo chmod -R g+rX app lang resources public bootstrap/cache deploy database/clubunio routes
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
printf 'Inactive candidate prepared: %s\n' "$next"
