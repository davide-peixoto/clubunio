#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass the verified current release path}"
release="$base/releases/20261021-clubunio-season-planning"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-season-planning.tar.gz" -C "$release"
cd "$release"
php -l app/ClubUnio/Services/SeasonPlanningService.php
php -l app/ClubUnio/Pages/SeasonPlanning.php
php -l app/ClubUnio/Services/CatalogService.php
php -l app/ClubUnio/Services/TeamAssignmentService.php
php artisan migrate --database=clubunio --path=database/clubunio/2026_10_21_000072_season_plans.php --force
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/check-tenant-navigation.php
sudo -u www-data php deploy/check-clubunio.php
sudo -u www-data php deploy/check-access-profiles.php
sudo -u www-data php deploy/check-season-planning.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback %s (retain planning tables)\n' "$release" "$previous"
