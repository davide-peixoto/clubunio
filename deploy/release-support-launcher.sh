#!/usr/bin/env bash
set -euo pipefail
b=/var/www/svs-laravel
old=$(readlink -f "$b/current")
new="$b/releases/20261119-clubunio-support-launcher"
test "$(hostname -s)" = svs-pi-server
test "$old" = "$b/releases/20261119-clubunio-member-change-conflicts"
test ! -e "$new"
sudo cp -a "$old" "$new"
sudo cp /tmp/clubunio-help-widget.js "$new/public/js/clubunio-help-widget.js"
sudo cp /tmp/clubunio-help-widget.css "$new/public/css/clubunio-help-widget.css"
sudo cp /tmp/clubunio-support-layout.blade.php "$new/resources/views/clubunio/portal/layout.blade.php"
cd "$new"
sudo php -r '$f="bootstrap/cache/config.php"; $s=file_get_contents($f); file_put_contents($f,str_replace($argv[1],$argv[2],$s));' "$old" "$new"
sudo chgrp www-data public/css/clubunio-help-widget.css public/js/clubunio-help-widget.js resources/views/clubunio/portal/layout.blade.php bootstrap/cache/config.php
sudo chmod g+r public/css/clubunio-help-widget.css public/js/clubunio-help-widget.js resources/views/clubunio/portal/layout.blade.php bootstrap/cache/config.php
sudo -u www-data php artisan view:cache
sudo -u www-data php deploy/verify-pi-dev-vps-database.php
test "$(readlink -f "$b/current")" = "$old"
sudo ln -s "$new" "$b/current.support-next"
sudo mv -Tf "$b/current.support-next" "$b/current"
sudo systemctl reload php8.3-fpm
echo "DEPLOYED $new"
