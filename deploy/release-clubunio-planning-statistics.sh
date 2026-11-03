#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261103-clubunio-mobile-season-scope
release=$base/releases/20261103-clubunio-planning-statistics-v2
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/planning-statistics.before.manifest"
while IFS= read -r file; do test ! -e "$file"; done < "$base/shared/planning-statistics.new-files"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
tar -xzf "$base/shared/planning-statistics.tar.gz" --no-same-owner
sha256sum -c "$base/shared/planning-statistics.manifest"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
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
sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' deploy/check-mobile-season-scope.php
sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' deploy/check-mobile-planning.php --enabled
sudo -u www-data php deploy/check-mobile-account-functional.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-team-planning-functional.php --synthetic-rollback
sha256sum -c "$base/shared/planning-statistics.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s. Rollback %s\n' "$release" "$previous"
