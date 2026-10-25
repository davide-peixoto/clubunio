#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="$base/releases/20261026-clubunio-api-v1"
release="$base/releases/20261026-clubunio-api-v1-final"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-api-final.tar.gz" -C "$release"
cd "$release"
php -l bootstrap/app.php
php -l app/ClubUnio/Http/ProtectApiAdminResponse.php
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
printf 'Deployed %s; API v1 preserved, UI/no-store followup only\n' "$release"
