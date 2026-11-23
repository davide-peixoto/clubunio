#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(hostname -s)" = svs-pi-server
base=/var/www/svs-laravel
old="$base/releases/20261123-clubunio-openai-models"
name=20261123-clubunio-support-workspace
next="$base/releases/$name"
test "$(readlink -f "$base/current")" = "$old"
test ! -e "$next"
sudo install -d -o admin -g www-data -m 2750 "$next"
cp -a "$old/." "$next/"
tar --no-same-owner --no-same-permissions -xzf "$base/shared/$name.tar.gz" -C "$next"
cd "$next"
test "$(readlink -f storage)" = "$base/shared/storage"
php deploy/prepare-pi-dev-vps-schema.php
php -l app/ClubUnio/Http/SupportCenterController.php
php -r '$f="bootstrap/cache/config.php"; $s=file_get_contents($f); $s=preg_replace("~".preg_quote($argv[1], "~")."/releases/[0-9]{8}-[a-z0-9-]+/~", $argv[2]."/", $s); if(file_put_contents($f,$s)===false) exit(1);' "$base" "$next"
sudo chgrp www-data bootstrap/cache/config.php
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
sudo -u www-data php deploy/check-support-workspace.php
test "$(readlink -f "$base/current")" = "$old"
test ! -e "$base/current.support-next" && test ! -L "$base/current.support-next"
sudo ln -s "$next" "$base/current.support-next"
sudo mv -Tf "$base/current.support-next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'DEV release: %s; rollback: %s\n' "$next" "$old"
