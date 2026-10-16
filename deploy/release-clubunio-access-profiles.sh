#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass verified current release}"
release="$base/releases/20261016-clubunio-access-profiles"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-access-profiles.tar.gz" -C "$release"
cd "$release"
php artisan migrate --database=clubunio --path=database/clubunio/2026_10_16_000069_access_profiles.php --path=database/clubunio/2026_10_16_000070_account_access_profiles.php --force
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo -u www-data php deploy/check-clubunio.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
# Activate only after the guarded code is live. Existing accounts are never reassigned.
php artisan migrate --database=clubunio --path=database/clubunio/2026_10_16_000071_enable_access_profiles.php --force
sudo -u www-data php deploy/check-access-profiles.php
printf 'Deployed %s; previous %s (profile access fails closed on old code)\n' "$release" "$previous"
