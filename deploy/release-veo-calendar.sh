#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old=$(readlink -f "$base/current")
name=20261124-clubunio-veo-calendar-v5
next="$base/releases/$name"
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/$name.tar.gz" -C "$next"
cd "$next"
sha256sum -c deploy/veo-calendar.manifest
test "$(readlink -f storage)" = "$base/shared/storage"
cmp --silent .env "$old/.env"
cmp --silent composer.lock "$old/composer.lock"
php deploy/prepare-pi-dev-vps-schema.php
php -r '$p="deploy/check-veo-calendar.php";if(file_put_contents($p,str_replace("clubora.","clubunio.",file_get_contents($p)))===false)exit(1);'
php -r '$f="bootstrap/cache/config.php";$s=file_get_contents($f);$s=str_replace($argv[1]."/",$argv[2]."/",$s);if(file_put_contents($f,$s)===false)exit(1);' "$old" "$next"
sudo chgrp -R www-data app resources lang public deploy database/clubunio bootstrap/cache
sudo chmod -R g+rX app resources lang public deploy database/clubunio bootstrap/cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-veo-calendar.php | tee /tmp/clubunio-veo-calendar-check.log
grep -q 'VEO_CALENDAR_ACCEPTANCE_OK' /tmp/clubunio-veo-calendar-check.log
test "$(readlink -f "$base/current")" = "$old"
sudo ln -s "$next" "$base/current.veo-next"
sudo mv -Tf "$base/current.veo-next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s; previous %s\n' "$next" "$old"
