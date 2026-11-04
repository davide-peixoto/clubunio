#!/usr/bin/env bash
set -euo pipefail
base=/var/www/svs-laravel
previous="$base/releases/20261104-clubunio-languages-complete"
release="$base/releases/20261104-clubunio-languages-reviewed"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-language-review.tar.gz" -C "$release" --no-same-owner
cd "$release"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
php tools/check-translations.php
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
for check in check-clubunio check-access-profiles check-impersonation; do
 sudo -u www-data php "deploy/$check.php"
done
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
echo "Activated $release"
