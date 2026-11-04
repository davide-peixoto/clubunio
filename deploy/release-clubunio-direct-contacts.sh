#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261104-clubunio-language-policy-v1
release=$base/releases/20261104-clubunio-direct-contacts-v1
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/direct-contacts.before.manifest"
while IFS= read -r file; do test ! -e "$file"; done < "$base/shared/direct-contacts.new-files"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
test ! -L .env
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
cmp --silent composer.json "$previous/composer.json"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
sha256sum -c "$base/shared/direct-contacts.before.manifest"
tar -xzf "$base/shared/direct-contacts.tar.gz" --no-same-owner
sha256sum -c "$base/shared/direct-contacts.manifest"
while read -r digest file; do php -l "$file" >/dev/null; done < "$base/shared/direct-contacts.manifest"
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
gate check-mobile-chat-experience --synthetic-rollback
gate check-mobile-team-member-access --synthetic-rollback
sudo -u www-data php deploy/check-mobile-chat-composer.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-language-policy.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-direct-contacts.php --synthetic-rollback
sha256sum -c "$base/shared/direct-contacts.manifest"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
cmp --silent composer.json "$previous/composer.json"
sha256sum -c "$base/shared/direct-contacts.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated bounded direct chat contact policy delta %s; rollback %s; schema/env/dependencies/provider flags unchanged.\n' "$release" "$previous"
