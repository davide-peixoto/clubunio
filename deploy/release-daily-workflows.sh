#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
previous=${2:-20261124-clubunio-work-hub-v3}
[[ "$previous" =~ ^20261124-clubunio-(work-hub-v3|daily-v[0-9]+)$ ]]
old="$base/releases/$previous"
name=${1:-20261124-clubunio-daily-v1}
[[ "$name" =~ ^20261124-clubunio-daily-v[0-9]+$ ]]
next="$base/releases/$name"
test "$(readlink -f "$base/current")" = "$old"
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/$name.tar.gz" -C "$next"
cd "$next"
sha256sum -c deploy/daily.manifest
test "$(readlink -f storage)" = "$base/shared/storage"
cmp --silent .env "$old/.env"
cmp --silent composer.lock "$old/composer.lock"
php -r '$f="bootstrap/cache/config.php";$s=file_get_contents($f);$s=str_replace($argv[1]."/",$argv[2]."/",$s);if(file_put_contents($f,$s)===false)exit(1);' "$old" "$next"
php -r '$norm=fn($p)=>str_replace($p,"RELEASE_ROOT",json_encode(require $p."/bootstrap/cache/config.php",JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));if($norm($argv[1])!==$norm($argv[2]))exit(1);echo "Configuration preserved\n";' "$old" "$next"
sudo chgrp -R www-data app resources lang public deploy bootstrap/cache
sudo chmod -R g+rX app resources lang public deploy bootstrap/cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php artisan view:cache
printf 'Inactive daily candidate prepared: %s; acceptance required before activation.\n' "$next"
