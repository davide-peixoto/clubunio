#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass verified current release path}"
release="$base/releases/20261026-clubunio-api-v1"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-api-v1.tar.gz" -C "$release"
cd "$release"
composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader
php artisan package:discover --no-interaction
php artisan migrate --database=clubunio --path=database/clubunio/2026_10_25_000076_api_platform.php --force
php artisan migrate --database=clubunio --path=database/clubunio/2026_10_26_000078_api_tenant_boundary.php --force
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo -u www-data php deploy/check-clubunio.php
sudo -u www-data php deploy/check-access-profiles.php
sudo -u www-data php deploy/check-season-planning.php
sudo -u www-data php deploy/check-sponsor-documents.php
sudo -u www-data php deploy/check-api.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; previous %s; API audit and revocations preserved\n' "$release" "$previous"
