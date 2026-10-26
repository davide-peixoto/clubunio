#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Pass verified current release path}"
revision="${2:?Pass reviewed Git revision}"
release="$base/releases/20261026-clubunio-platform"
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-platform.tar.gz" -C "$release" --no-same-owner
cd "$release"
test "$(readlink -f .env)" = "$(readlink -f "$previous/.env")"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
sudo chgrp -R www-data "$release"
sudo chmod -R g+rX "$release"
test ! -L bootstrap/cache
sudo chmod -R g+rwX bootstrap/cache
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-tenant-api-release check-platform-administration; do
    sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $error) { fwrite(STDERR, "Release check failed: ".get_class($error).PHP_EOL); exit(1); }' "deploy/$check.php"
done
printf '%s\n' "$revision" > CLUBUNIO-REVISION
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s revision %s; previous %s retained\n' "$release" "$revision" "$previous"
