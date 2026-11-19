#!/usr/bin/env bash
set -euo pipefail
b=/var/www/svs-laravel
old=$(readlink -f "$b/current")
new="$b/releases/20261119-clubunio-support-management"
test "$(hostname -s)" = svs-pi-server
test "$old" = "$b/releases/20261119-clubunio-campaign-operations-v3"
test ! -e "$new"
sudo cp -a "$old" "$new"
for file in app/Providers/Filament/ClubUnioPanelProvider.php resources/views/clubunio/platform/layout.blade.php resources/views/clubunio/support/launcher.blade.php; do
    sudo cp "/tmp/clubunio-management/$file" "$new/$file"
    sudo chgrp www-data "$new/$file"
    sudo chmod g+r "$new/$file"
done
cd "$new"
sudo php -r '$f="bootstrap/cache/config.php"; file_put_contents($f,str_replace($argv[1],$argv[2],file_get_contents($f)));' "$old" "$new"
sudo chgrp www-data bootstrap/cache/config.php
sudo chmod g+r bootstrap/cache/config.php
php -l app/Providers/Filament/ClubUnioPanelProvider.php
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
test "$(readlink -f "$b/current")" = "$old"
sudo ln -s "$new" "$b/current.support-next"
sudo mv -Tf "$b/current.support-next" "$b/current"
sudo systemctl reload php8.3-fpm
echo "DEPLOYED $new"
