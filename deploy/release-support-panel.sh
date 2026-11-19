#!/usr/bin/env bash
set -euo pipefail
b=/var/www/svs-laravel
old=$(readlink -f "$b/current")
new="$b/releases/20261119-clubunio-support-panel"
test "$(hostname -s)" = svs-pi-server
test "$old" = "$b/releases/20261119-clubunio-laravel-mail-theme"
test ! -e "$new"
sudo cp -a "$old" "$new"
for file in public/js/clubunio-help-widget.js public/css/clubunio-help-widget.css public/css/clubunio-support.css resources/views/clubunio/portal/layout.blade.php lang/de/support.php lang/en/support.php; do
    sudo cp "/tmp/clubunio-panel/$file" "$new/$file"
    sudo chgrp www-data "$new/$file"
    sudo chmod g+r "$new/$file"
done
cd "$new"
sudo php -r '$f="bootstrap/cache/config.php"; $s=file_get_contents($f); file_put_contents($f,str_replace($argv[1],$argv[2],$s));' "$old" "$new"
sudo chgrp www-data bootstrap/cache/config.php
sudo chmod g+r bootstrap/cache/config.php
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
test "$(readlink -f "$b/current")" = "$old"
sudo ln -s "$new" "$b/current.panel-next"
sudo mv -Tf "$b/current.panel-next" "$b/current"
sudo systemctl reload php8.3-fpm
echo "DEPLOYED $new"
