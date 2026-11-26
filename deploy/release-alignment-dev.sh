#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old=$(readlink -f "$base/current")
next="$base/releases/20261126-clubunio-alignment-v1"
[[ "$old" == "$base/releases/"* ]]
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/release-alignment-20261126.tar.gz" -C "$next"
cd "$next"
cmp --silent .env "$old/.env"
cmp --silent composer.lock "$old/composer.lock"
test "$(readlink -f storage)" = "$base/shared/storage"
php -r '$p="bootstrap/cache/config.php";$s=file_get_contents($p);file_put_contents($p,str_replace($argv[1]."/",$argv[2]."/",$s));' "$old" "$next"
sudo chgrp -R www-data app resources public lang deploy bootstrap/cache
sudo chmod -R g+rX app resources public lang deploy bootstrap/cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/measure-react-performance.php > "$base/shared/alignment-dev-check-20261126.json"
test "$(readlink -f "$base/current")" = "$old"
sudo ln -s "$next" "$base/alignment-next"
sudo mv -Tf "$base/alignment-next" "$base/current"
sudo systemctl reload php8.3-fpm
if ! curl --max-time 30 --fail --silent https://dev.clubunio.ch/up > /dev/null; then
    sudo ln -s "$old" "$base/alignment-rollback"
    sudo mv -Tf "$base/alignment-rollback" "$base/current"
    sudo systemctl reload php8.3-fpm
    exit 1
fi
echo 'DEV alignment activated; native invoice comparison and rollback-only metrics passed.'
