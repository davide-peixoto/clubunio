#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261104-clubunio-mobile-modules-performance-v1
release=$base/releases/20261104-clubunio-mobile-functional-v2
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/mobile-functional.before.manifest"
while IFS= read -r file; do test ! -e "$file"; done < "$base/shared/mobile-functional.new-files"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
test ! -L .env
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
cmp --silent composer.json "$previous/composer.json"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
sha256sum -c "$base/shared/mobile-functional.before.manifest"
tar -xzf "$base/shared/mobile-functional.tar.gz" --no-same-owner
sha256sum -c "$base/shared/mobile-functional.manifest"
while read -r digest file; do php -l "$file" >/dev/null; done < "$base/shared/mobile-functional.manifest"
chmod 0640 .env
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
# Schema1100 was committed by the immutable failed v1; verify only, never reapply.
sudo -u www-data php deploy/verify-chat-experience-schema.php --backup-file=/var/www/svs-laravel/shared/chat-experience-backups/before-1100-20261104103950-bce2422c1f47058bbd309bf15e70aea8.cuenc
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
sha256sum -c "$base/shared/mobile-functional.manifest"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
cmp --silent composer.json "$previous/composer.json"
sudo -u www-data php deploy/benchmark-mobile-reads.php --synthetic-rollback > "$base/shared/mobile-functional-after-admin.json"
sudo -u www-data php deploy/benchmark-mobile-reads.php --synthetic-rollback --role=technical_lead > "$base/shared/mobile-functional-after-lead.json"
sha256sum -c "$base/shared/mobile-functional.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated bounded mobile functional delta %s; runtime rollback %s; additive schema1100 retained; env/dependencies/provider flags unchanged.\n' "$release" "$previous"
