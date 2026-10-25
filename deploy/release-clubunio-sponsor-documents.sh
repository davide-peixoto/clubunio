#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass the verified current release path}"
release="$base/releases/20261025-clubunio-sponsor-documents"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-sponsor-documents.tar.gz" -C "$release"
cd "$release"
php -l app/ClubUnio/Services/SponsorRunBillingService.php
php -l app/ClubUnio/Services/SponsorRunService.php
php -l app/ClubUnio/Services/DocumentSeriesService.php
for migration in 2026_10_25_000073_document_series.php 2026_10_25_000074_sponsor_runs.php 2026_10_25_000075_sponsor_run_billing.php; do
    php artisan migrate --database=clubunio --path="database/clubunio/$migration" --force
done
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo -u www-data php deploy/check-clubunio.php
sudo -u www-data php deploy/check-access-profiles.php
sudo -u www-data php deploy/check-season-planning.php
sudo -u www-data php deploy/check-sponsor-documents.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback %s; retain document and billing evidence tables\n' "$release" "$previous"
