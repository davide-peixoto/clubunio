#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261103-clubunio-mobile-foundation
release=$base/releases/20261103-clubunio-mobile-planning
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/mobile-foundation.manifest"
php deploy/prepare-mobile-planning-schema.php --verify-backup
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
# DEV environments are regular per-release files; never edit a shared link.
test ! -L .env
cmp --silent .env "$previous/.env"
if grep -q '^CLUBUNIO_MOBILE_NATIVE_PLANNING_ENABLED=' .env; then exit 1; fi
printf '\nCLUBUNIO_MOBILE_NATIVE_PLANNING_ENABLED=true\n' >> .env
chmod 0640 .env
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell; do
 sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' "deploy/$check.php"
done
sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' deploy/check-mobile-planning.php --enabled
sudo -u www-data php deploy/check-mobile-account-functional.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-team-planning-functional.php --synthetic-rollback
sha256sum -c "$base/shared/mobile-foundation.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s. Safe closed-planning rollback %s\n' "$release" "$previous"
