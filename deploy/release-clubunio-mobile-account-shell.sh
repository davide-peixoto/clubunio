#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261103-clubunio-member-access
release=$base/releases/20261103-clubunio-mobile-account-shell
archive=$base/shared/mobile-account-shell.tar.gz
manifest=$base/shared/mobile-account-shell.manifest
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
tar -xzf "$archive" --no-same-owner
sha256sum -c "$manifest"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
test "$(sha256sum composer.lock | cut -d' ' -f1)" = "$(sha256sum "$previous/composer.lock" | cut -d' ' -f1)"
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell; do
 sudo -u www-data php "deploy/$check.php"
done
sudo -u www-data php deploy/check-mobile-account-functional.php --synthetic-rollback
sha256sum -c "$manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s; rollback %s\n' "$release" "$previous"
