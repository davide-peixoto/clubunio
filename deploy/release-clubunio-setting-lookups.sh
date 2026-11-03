#!/usr/bin/env bash
set -euo pipefail
base=/var/www/svs-laravel
previous="$base/releases/20261103-clubunio-finance-season-parameter"
release="$base/releases/20261103-clubunio-setting-lookups"
test "$(readlink -f "$base/current")" = "$previous"
if [ ! -e "$release" ]; then
 sudo cp -a "$previous" "$release"
fi
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-setting-lookups.tar.gz" -C "$release" --no-same-owner
cd "$release"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
php -l app/ClubUnio/Resources/SettingResource.php
php -l app/ClubUnio/Services/SettingsService.php
php -l app/ClubUnio/Services/SettingOptions.php
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
for check in check-clubunio check-access-profiles check-impersonation check-mobile-season-scope; do
 sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e)."\n"); exit(1); }' "deploy/$check.php"
done
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
echo "Activated $release"
