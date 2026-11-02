#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261102-clubunio-navigation-cleanup
release=$base/releases/20261102-clubunio-mobile-communication
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
tar -xzf "$base/shared/mobile.tar.gz" --no-same-owner
sha256sum -c "$base/shared/manifest"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
test "$(sha256sum composer.lock | cut -d' ' -f1)" = "$(sha256sum "$previous/composer.lock" | cut -d' ' -f1)"
cp --dereference .env .env.mobile
rm .env
mv .env.mobile .env
chmod 640 .env
cat >> .env <<'ENV'

CLUBUNIO_MOBILE_ENABLED=true
CLUBUNIO_MOBILE_UPLOADS_ENABLED=true
CLUBUNIO_CLAMD_SOCKET=/run/clamav/clamd.ctl
CLUBUNIO_MOBILE_AI_ENABLED=false
ENV
php artisan config:clear
php artisan route:clear
cp "$base/shared/ddl.php" ./mobile-ddl-check.php
php mobile-ddl-check.php
rm mobile-ddl-check.php
php artisan migrate --database=clubunio --path=database/clubunio/2026_11_02_000082_mobile_communication.php --force
php artisan migrate --database=clubunio --path=database/clubunio/2026_11_02_000083_mobile_chat_controls.php --force
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile; do
 sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, "Release gate failed: ".get_class($e)." ".$e->getMessage()."\n"); exit(1); }' "deploy/$check.php"
done
sudo -u www-data php deploy/provision-mobile-chats.php
sha256sum -c "$base/shared/manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s; rollback %s\n' "$release" "$previous"
