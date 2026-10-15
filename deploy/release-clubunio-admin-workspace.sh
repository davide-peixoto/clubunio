#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
expected=${1:?Expected current release name required}
[[ "$expected" =~ ^20261015-clubunio-[0-9]+-[a-z-]+$ ]]
previous=$(readlink -f "$base/current")
test "$previous" = "$base/releases/$expected"
release="$base/releases/20261015-clubunio-69-admin-workspace"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-admin-workspace.tar.gz" -C "$release"
cd "$release"
# Removed obsolete secondary tenant picker, only in the new inactive release.
rm -f "$release/resources/views/clubunio/bexio/select.blade.php"
php -l app/ClubUnio/Http/BexioConnectionController.php
php -l app/ClubUnio/WorkspaceRoutes.php
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback %s\n' "$release" "$previous"
