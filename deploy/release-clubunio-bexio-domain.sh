#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$(readlink -f "$base/current")
test "$previous" = "$base/releases/20261014-clubunio-52"
release="$base/releases/20261014-clubunio-53-bexio-domain"
backup="$base/shared/.env.before-bexio-domain-20261014"
test ! -e "$release"
test ! -e "$backup"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-bexio-domain.tar.gz" -C "$release"
cd "$release"
sudo cp -a "$base/shared/.env.clubora" "$backup"
trap 'sudo cp -a "$backup" "$base/shared/.env.clubora"' ERR
sudo php deploy/set-clubunio-domain.php
php -l app/ClubUnio/Http/BexioConnectionController.php
php -l routes/web.php
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/clubunio-bexio-domain-check.php
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
trap - ERR
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; rollback %s (also restore APP_URL backup)\n' "$release" "$previous"
