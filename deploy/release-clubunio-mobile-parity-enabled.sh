#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261104-clubunio-mobile-parity-foundation-v2
release=$base/releases/20261104-clubunio-mobile-parity-v1
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/mobile-parity.manifest"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
test ! -L .env
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
enable_flag() {
 local key=$1
 test "$(grep -c "^${key}=" .env || true)" -le 1
 if grep -q "^${key}=" .env; then
  grep -Eq "^${key}=(false|0|)$" .env
  sed -i "s/^${key}=.*/${key}=true/" .env
 else
  printf '\n%s=true\n' "$key" >> .env
 fi
}
for key in CLUBUNIO_MOBILE_BOOTSTRAP_ENABLED CLUBUNIO_MOBILE_NATIVE_ROLE_GUIDE_ENABLED CLUBUNIO_MOBILE_EXPENSE_PROVENANCE_ENABLED CLUBUNIO_MOBILE_NATIVE_PUSH_ADMIN_ENABLED CLUBUNIO_MOBILE_NATIVE_PENALTY_ENABLED CLUBUNIO_MOBILE_NATIVE_KEYBOX_ENABLED CLUBUNIO_KEYBOX_ADMINISTRATION_ENABLED; do
 enable_flag "$key"
done
key=CLUBUNIO_MOBILE_BOOTSTRAP_ORIGINS
test "$(grep -c "^${key}=" .env || true)" -le 1
if grep -q "^${key}=" .env; then
 grep -Eq "^${key}=$" .env
 sed -i "s|^${key}=.*|${key}=https://dev.clubunio.ch|" .env
else
 printf '\n%s=https://dev.clubunio.ch\n' "$key" >> .env
fi
chmod 0640 .env
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
gate() { sudo -u www-data php -r 'array_shift($argv); $argc=count($argv); try { require $argv[0]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' "deploy/$1.php" "${@:2}"; }
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell check-mobile-season-scope; do
 gate "$check"
done
gate check-mobile-planning --enabled
gate check-mobile-native-core --expenses-enabled --documents-enabled --facilities-enabled --bus-enabled
gate check-mobile-training --training-enabled
gate check-mobile-registrations --enabled
gate check-mobile-sponsor --enabled
gate check-mobile-sponsor-billing --enabled
gate check-mobile-member-id-card --enabled
gate check-mobile-identity --closed
gate check-mobile-push --closed
gate check-mobile-keybox --ready
gate check-mobile-penalty --enabled
gate check-mobile-bootstrap --enabled
gate check-mobile-expense-provenance --enabled
gate check-mobile-admin-notices --synthetic-rollback --enabled
gate check-mobile-followup-modules --synthetic-rollback --enabled
gate check-mobile-account-functional --synthetic-rollback
gate check-mobile-team-planning-functional --synthetic-rollback
sha256sum -c "$base/shared/mobile-parity.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated original personal parity %s; rollback %s; external mail/push/PIN/provider flags remain closed.\n' "$release" "$previous"
