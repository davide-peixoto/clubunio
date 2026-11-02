#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
revision="${1:?Reviewed source revision required}"
previous="${2:?Verified previous release required}"
release="$base/releases/20261102-clubunio-compact-shell"
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]
[[ "$previous" =~ ^$base/releases/[0-9]{8}-clubunio-[a-z0-9-]+$ ]]
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
tar -xzf "$base/shared/clubunio-compact-shell.tar.gz" -C "$release" --no-same-owner
cd "$release"
cmp --silent .env "$previous/.env"
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
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-tenant-api-release check-platform-administration check-administration-ui check-impersonation check-mobile; do
    sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $error) { fwrite(STDERR, "Release check failed: ".get_class($error).PHP_EOL); exit(1); }' "deploy/$check.php"
done
printf '%s\n' "$revision" > CLUBUNIO-REVISION
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Deployed %s revision %s; previous %s retained\n' "$release" "$revision" "$previous"
