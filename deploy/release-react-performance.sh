#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old=$(readlink -f "$base/current")
next="$base/releases/20261125-clubunio-react-performance-v1"
[[ "$old" == "$base/releases/"* ]]
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar -xzf "$base/shared/react-performance-20261125.tar.gz" -C "$next"
cd "$next"
sha256sum -c deploy/react-performance.manifest
cmp --silent .env "$old/.env"
cmp --silent composer.lock "$old/composer.lock"
test "$(readlink -f storage)" = "$base/shared/storage"
php -r '$p="bootstrap/cache/config.php";$s=file_get_contents($p);file_put_contents($p,str_replace($argv[1]."/",$argv[2]."/",$s));' "$old" "$next"
sudo chgrp -R www-data app resources public deploy bootstrap/cache
sudo chmod -R g+rX app resources public deploy bootstrap/cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php artisan view:cache
printf '%s\n' "$old" > deploy/react-performance.previous
printf 'Inactive candidate prepared. Verify benchmarks and renders before activation: %s\n' "$next"
