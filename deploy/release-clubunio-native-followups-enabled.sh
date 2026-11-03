#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$(cat "$base/shared/native-followups-enabled.previous")
[[ "$previous" =~ ^/var/www/svs-laravel/releases/[a-zA-Z0-9-]+$ ]]
release=$base/releases/20261103-clubunio-native-followups-v2
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$release"
cd "$previous"
sha256sum -c "$base/shared/native-followups-enabled.before.manifest"
while IFS= read -r file; do test ! -e "$file"; done < "$base/shared/native-followups-enabled.new-files"
sudo cp -a "$previous" "$release"
sudo chown -R admin:www-data "$release"
cd "$release"
tar -xzf "$base/shared/native-followups-enabled.tar.gz" --no-same-owner
sha256sum -c "$base/shared/native-followups-enabled.manifest"
test ! -L .env
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
for module in EXPENSES DOCUMENTS FACILITIES BUS; do
 key=CLUBUNIO_MOBILE_NATIVE_${module}_ENABLED
 if grep -q "^${key}=" .env; then
  grep -Eq "^${key}=(false|0)$" .env
  sed -i "s/^${key}=\(false\|0\)$/${key}=true/" .env
 else
  printf '\n%s=true\n' "$key" >> .env
 fi
done
chmod 0640 .env
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
sudo -u www-data php artisan view:cache
for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell check-mobile-season-scope; do
 sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' "deploy/$check.php"
done
sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' deploy/check-mobile-planning.php --enabled
sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' deploy/check-mobile-native-core.php --expenses-enabled --documents-enabled --facilities-enabled --bus-enabled
sudo -u www-data php deploy/check-mobile-expenses.php --enabled
sudo -u www-data php -r 'try { require $argv[1]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' deploy/check-mobile-documents.php --enabled
sudo -u www-data php deploy/check-mobile-facilities-bus.php --facilities-enabled --bus-enabled
sudo -u www-data php deploy/check-mobile-documents-functional.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-facilities-bus-functional.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-expenses-functional.php --synthetic-rollback --enabled
sudo -u www-data php deploy/check-mobile-account-functional.php --synthetic-rollback
sudo -u www-data php deploy/check-mobile-team-planning-functional.php --synthetic-rollback
sha256sum -c "$base/shared/native-followups-enabled.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s; rollback %s; four native follow-ups enabled; no schema or dependency change.\n' "$release" "$previous"
