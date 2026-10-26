#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass verified current release path}"
revision="${2:?Pass archived Git revision}"
archive="$base/shared/clubunio-tenant-api.tar.gz"
release="$base/releases/20261026-clubunio-tenant-api"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
test -f "$archive"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$archive" -C "$release" --no-same-owner
cd "$release"
test "$(readlink -f .env)" = "$(readlink -f "$previous/.env")"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
# Existing vendor is reusable only for the exact same reviewed dependency lock.
test "$(sha256sum composer.lock | cut -d' ' -f1)" = "$(sha256sum "$previous/composer.lock" | cut -d' ' -f1)"
composer check-platform-reqs --no-dev
sudo chgrp -R www-data "$release"
sudo chmod -R g+rX "$release"
test ! -L bootstrap/cache
sudo chmod -R g+rwX bootstrap/cache
php artisan config:clear
php artisan route:clear
php artisan package:discover --no-interaction
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-tenant-api-release; do
    sudo -u www-data php "deploy/$check.php"
done
sudo -u www-data php tools/clubunio-audit-tenant-boundaries.php
printf '%s\n' "$revision" > CLUBUNIO-REVISION
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s revision %s; previous %s retained\n' "$release" "$revision" "$previous"
