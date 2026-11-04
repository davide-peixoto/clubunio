#!/usr/bin/env bash
set -euo pipefail
base=/var/www/svs-laravel
previous="$base/releases/20261104-clubunio-languages-reviewed-v2"
release="$base/releases/20261104-clubunio-language-autoswitch"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-language-autoswitch.tar.gz" -C "$release" --no-same-owner
cd "$release"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
php tools/check-translations.php
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
echo "Activated $release"
