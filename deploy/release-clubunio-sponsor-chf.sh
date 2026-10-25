#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="$base/releases/20261025-clubunio-sponsor-visual"
release="$base/releases/20261025-clubunio-sponsor-chf"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-sponsor-chf.tar.gz" -C "$release"
cd "$release"
for file in app/ClubUnio/Services/SponsorRunMoney.php app/ClubUnio/Pages/SponsorRuns.php app/ClubUnio/Http/SponsorRunPortalController.php app/ClubUnio/Http/SponsorRunPublicController.php; do
    php -l "$file"
done
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-sponsor-documents.php
sudo -u www-data php deploy/check-access-profiles.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; previous %s; no data migration\n' "$release" "$previous"
