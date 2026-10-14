#!/usr/bin/env bash
set -euo pipefail
umask 027
name="${1:?Release name required}"
[[ "$name" =~ ^[0-9]{8}-[a-z0-9-]+$ ]]
base=/var/www/svs-laravel
release="$base/releases/$name"
previous=$(readlink -f "$base/current")
[[ "$previous" == "$base/releases/"* ]]
test ! -e "$release"
test -f "$base/shared/.env.clubora"
test -f "$base/shared/.env.postgresql.local"
test -f "$base/shared/.env.clubora-integrations.local"
test ! -e "$base/current.next"
sudo install -d -o admin -g www-data -m 2750 "$release"
tar -xzf "$base/shared/$name.tar.gz" -C "$release"
cd "$release"
mkdir -p bootstrap/cache
ln -s "$base/shared/storage" storage
ln -s "$base/shared/.env.clubora" .env
ln -s "$base/shared/.env.postgresql.local" .env.postgresql.local
ln -s "$base/shared/.env.clubora-integrations.local" .env.clubora-integrations.local
cp -a "$previous/vendor" vendor
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
chmod 2770 bootstrap/cache
php artisan package:discover
php artisan filament:assets
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan clubunio:status
sudo -u www-data php deploy/check-clubunio.php
sudo chgrp -R www-data bootstrap/cache
chmod -R g+rX bootstrap/cache
test "$(readlink -f "$base/current")" = "$previous"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback release: %s\n' "$name" "$previous"
