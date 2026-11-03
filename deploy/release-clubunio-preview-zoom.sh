#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261103-clubunio-mobile-account-shell
release=$base/releases/20261103-clubunio-preview-zoom
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
tar -xzf "$base/shared/preview-zoom.tar.gz" --no-same-owner
sha256sum -c "$base/shared/preview-zoom.manifest"
cmp --silent .env "$previous/.env"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
cmp --silent composer.lock "$previous/composer.lock"
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell; do
 sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).": ".$e->getMessage().PHP_EOL); exit(1); }' "deploy/$check.php"
done
sudo -u www-data php deploy/check-mobile-account-functional.php --synthetic-rollback
sha256sum -c "$base/shared/preview-zoom.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s; rollback %s\n' "$release" "$previous"
