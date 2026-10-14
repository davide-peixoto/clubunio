#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$(readlink -f "$base/current")
test "$previous" = "$base/releases/20261014-clubora-47"
release="$base/releases/20261014-clubunio-brand"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-brand.tar.gz" -C "$release"
cd "$release"
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan clubunio:status
sudo -u www-data php deploy/check-clubunio.php
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'ClubUnio branding deployed; rollback: %s\n' "$previous"
