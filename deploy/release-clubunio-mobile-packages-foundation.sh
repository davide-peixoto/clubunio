#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$(cat "$base/shared/mobile-packages.previous")
test "$previous" = "$base/releases/20261103-clubunio-native-followups-v4"
test "$(readlink -f "$base/current")" = "$previous"
release=$base/releases/20261104-clubunio-mobile-packages-foundation-v2
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/mobile-packages.before.manifest"
while IFS= read -r file; do test ! -e "$file"; done < "$base/shared/mobile-packages.new-files"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
tar -xzf "$base/shared/mobile-packages.tar.gz" --no-same-owner
sha256sum -c "$base/shared/mobile-packages.manifest"
test ! -L .env
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
gate() { sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' "deploy/$1.php" "${@:2}"; }
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell check-mobile-season-scope; do
 gate "$check"
done
gate check-mobile-planning --enabled
gate check-mobile-native-core --expenses-enabled --documents-enabled --facilities-enabled --bus-enabled
gate check-mobile-training --foundation
gate check-mobile-registrations --foundation
gate check-mobile-sponsor --foundation
gate check-mobile-sponsor-billing --foundation
gate check-mobile-member-id-card --foundation
gate check-mobile-followup-modules --synthetic-rollback --foundation
gate check-mobile-account-functional --synthetic-rollback
sha256sum -c "$base/shared/mobile-packages.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated closed package foundation %s; rollback %s; no schema/dependency/env change.\n' "$release" "$previous"
