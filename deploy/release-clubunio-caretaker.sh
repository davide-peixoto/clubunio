#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Verified current release required}"
release="$base/releases/20261019-clubunio-caretaker"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-caretaker.tar.gz" -C "$release"
cd "$release"
for file in app/ClubUnio/Services/{PermissionCatalog,ModulePermissionService,FacilityService}.php app/ClubUnio/Resources/FieldAvailabilityResource.php tools/{restrict-svs-caretaker,svs-access-profile-definitions}.php; do
    php -l "$file"
done
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo -u www-data php deploy/check-access-profiles.php
# Restrict first: old code fails closed for the new permission keys.
php tools/restrict-svs-caretaker.php --apply
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback %s (do not restore broad caretaker rights)\n' "$release" "$previous"
