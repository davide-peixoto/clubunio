#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old="$(readlink -f "$base/current")"
case "$old" in "$base/releases/20261124-clubunio-personal-workflows-v4"|"$base/releases/20261124-clubunio-work-hub-v1") ;; *) exit 1;; esac
name=${1:-20261124-clubunio-work-hub-v2}
next="$base/releases/$name"
test "$(readlink -f "$base/current")" = "$old"
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/$name.tar.gz" -C "$next"
cd "$next"
sha256sum -c deploy/work-hub.manifest
test "$(readlink -f storage)" = "$base/shared/storage"
php -r '$f="bootstrap/cache/config.php";$s=file_get_contents($f);$s=str_replace($argv[1],$argv[2],$s);if(file_put_contents($f,$s)===false)exit(1);' "$old/" "$next/"
sudo chgrp -R www-data app lang resources public bootstrap/cache deploy database/clubunio
sudo chmod -R g+rX app lang resources public bootstrap/cache deploy database/clubunio
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
# Run the reviewed emit-work-hub-migration.php output on the VPS as postgres,
# after an encrypted owner backup. The web login deliberately cannot create tables.
printf 'Inactive candidate prepared; owner migration and acceptance required: %s\n' "$next"
