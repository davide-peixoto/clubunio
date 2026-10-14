#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$(readlink -f "$base/current")
test "$previous" = "$base/releases/20261014-clubunio-49"
release="$base/releases/20261014-clubunio-50"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
php "$base/shared/rename-clubunio.php" "$release"
test -d "$release/public/clubora"
test ! -e "$release/public/clubunio"
mv "$release/public/clubora" "$release/public/clubunio"
tar -xzf "$base/shared/clubunio-technical-patch.tar.gz" -C "$release"
cd "$release"
# Do not boot cached routes with class names from the preceding release.
find bootstrap/cache -maxdepth 1 -type f -name '*.php' -delete
composer dump-autoload --no-dev --optimize --no-scripts
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
php artisan package:discover
php artisan filament:assets
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
printf 'Deployed ClubUnio technical rename; rollback: %s\n' "$previous"
