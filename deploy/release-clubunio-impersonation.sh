#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous="${1:?Verified current release path required}"
revision="${2:?Reviewed source revision required}"
release="$base/releases/20261026-clubunio-impersonation"
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]
case "$previous" in "$base"/releases/*) ;; *) exit 1 ;; esac
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-impersonation.tar.gz" -C "$release" --no-same-owner
cd "$release"
test "$(readlink -f .env)" = "$(readlink -f "$previous/.env")"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
test "$(sha256sum composer.lock | cut -d' ' -f1)" = "$(sha256sum "$previous/composer.lock" | cut -d' ' -f1)"
php artisan migrate --database=clubunio --path=database/clubunio/2026_10_26_000081_support_impersonation.php --force
php artisan config:cache
php artisan route:cache
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api; do
    sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, "Release gate failed: ".get_class($e)."\n"); exit(1); }' "deploy/$check.php"
done
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
printf '%s\n' "$revision" > CLUBUNIO-REVISION
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s; previous %s retained\n' "$release" "$previous"
