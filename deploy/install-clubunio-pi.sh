#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
release="$base/releases/20261011-clubora-01"
test ! -e "$release"
chmod 600 "$base/shared/clubunio-private-20261011.json"
sudo install -d -o admin -g www-data -m 2750 "$release"
tar -xzf "$base/shared/clubunio-20261011.tar.gz" -C "$release"
cd "$release"
mkdir -p bootstrap/cache
ln -s "$base/shared/storage" storage
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
php deploy/install-clubunio-env.php "$base/shared/clubunio-private-20261011.json"
ln -s "$base/shared/.env.clubora" .env
ln -s "$base/shared/.env.postgresql.local" .env.postgresql.local
sudo chgrp -R www-data "$release"
sudo chgrp www-data "$base/shared/.env.clubora" "$base/shared/.env.postgresql.local"
chmod -R g+rX "$release"
chmod 770 bootstrap/cache
php artisan package:discover
php artisan filament:assets
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan clubunio:status
sudo chgrp -R www-data bootstrap/cache
chmod -R g+rX bootstrap/cache
rm "$base/shared/clubunio-private-20261011.json"
echo 'Release prepared; current has not been switched.'
