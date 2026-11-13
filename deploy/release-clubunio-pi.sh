#!/usr/bin/env bash
set -euo pipefail
umask 027
name="${1:?Release name required}"
[[ "$name" =~ ^[0-9]{8}-[a-z0-9-]+$ ]]
mode=${2:-deploy}
[[ $# -le 2 && ( "$mode" = deploy || "$mode" = --prepare-only ) ]]
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
release="$base/releases/$name"
previous=$(readlink -f "$base/current")
[[ "$previous" == "$base/releases/"* ]]
test ! -e "$release"
test -f "$base/shared/.env.clubora"
test -f "$base/shared/.env.postgresql.vps-candidate"
test -f "$base/shared/.env.clubora-integrations.local"
test ! -e "$base/current.next" && test ! -L "$base/current.next"
systemctl is-active --quiet clubunio-dev-db-tunnel.service
sudo install -d -o admin -g www-data -m 2750 "$release"
tar -xzf "$base/shared/$name.tar.gz" -C "$release"
cd "$release"
for path in storage vendor .env .env.postgresql.local .env.clubora-integrations.local bootstrap/cache/config.php; do
    test ! -e "$path" && test ! -L "$path"
done
mkdir -p bootstrap/cache
ln -s "$base/shared/storage" storage
ln -s "$base/shared/.env.clubora" .env
ln -s "$base/shared/.env.postgresql.vps-candidate" .env.postgresql.local
ln -s "$base/shared/.env.clubora-integrations.local" .env.clubora-integrations.local
php deploy/prepare-pi-dev-vps-schema.php | while IFS= read -r file; do
    php -l "$file" >/dev/null
done
cp -a "$previous/vendor" vendor
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
sudo chgrp -R www-data "$release"
chmod -R g+rX "$release"
install -d -m 2770 bootstrap/cache/dev-views
chmod 2770 bootstrap/cache
php artisan package:discover
php artisan filament:assets
php tools/publish-livewire-assets.php
VIEW_COMPILED_PATH="$release/bootstrap/cache/dev-views" php artisan config:cache
php -- "$previous" "$release" <<'PHP'
<?php
[$previous, $candidate] = array_slice($argv, 1);
$old = require $previous.'/bootstrap/cache/config.php';
$new = require $candidate.'/bootstrap/cache/config.php';
foreach ($old['clubunio-mobile'] ?? [] as $key => $enabled) {
    if (($key === 'enabled' || str_starts_with($key, 'native_') || $key === 'uploads_enabled')
        && $enabled === true && ($new['clubunio-mobile'][$key] ?? false) !== true) {
        fwrite(STDERR, "Candidate lost an active DEV mobile setting: $key\n");
        exit(1);
    }
}
PHP
php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php artisan clubunio:status
sudo -u www-data php deploy/check-clubunio.php
sudo chgrp -R www-data bootstrap/cache
sudo chmod -R g+rX bootstrap/cache
test "$(readlink -f "$base/current")" = "$previous"
if [[ "$mode" = --prepare-only ]]; then
    printf 'Verified %s; live release unchanged\n' "$name"
    exit 0
fi
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
sudo systemctl restart clubunio-dev-mobile-push.service
printf 'Deployed %s; rollback release: %s\n' "$name" "$previous"
