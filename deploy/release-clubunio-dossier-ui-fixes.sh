#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass the verified current release path}"
release="$base/releases/20261018-clubunio-dossier-ui-fixes"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-dossier-ui-fixes.tar.gz" -C "$release"
cd "$release"
for file in app/ClubUnio/Services/DossierHistoryService.php app/ClubUnio/Resources/Relations/{DossierRelationManager,SeasonFinanceActions,InvoiceLinesRelationManager}.php app/Providers/AppServiceProvider.php; do
    php -l "$file"
done
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo -u www-data php deploy/check-clubunio.php
sudo -u www-data php deploy/check-access-profiles.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback %s\n' "$release" "$previous"
