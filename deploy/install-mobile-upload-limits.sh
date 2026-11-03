#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(id -u)" = 0
source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
nginx_target=/etc/nginx/sites-available/svs-laravel
fpm_target=/etc/php/8.3/fpm/pool.d/svs-laravel.conf
test "$(readlink -f /etc/nginx/sites-enabled/svs-laravel)" = "$nginx_target"
test "$(readlink -f /var/www/svs-laravel/current)" = /var/www/svs-laravel/releases/20261103-clubunio-native-followups-v4
cd "$source_dir"
sha256sum -c source.manifest
nginx_desired=$(sha256sum pi-nginx.conf | cut -d ' ' -f 1)
fpm_desired=$(sha256sum php-fpm/svs-laravel.conf | cut -d ' ' -f 1)
nginx_current=$(sha256sum "$nginx_target" | cut -d ' ' -f 1)
fpm_current=$(sha256sum "$fpm_target" | cut -d ' ' -f 1)
if test "$nginx_current" = "$nginx_desired" && test "$fpm_current" = "$fpm_desired"; then
 echo 'Reviewed ClubUnio upload limits already installed.'
 exit 0
fi
test "$nginx_current" = a6a6e602734a1a468366c516882527dfdd9082ea0fa711d5dc4503da8875e55e
test "$fpm_current" = c52d26fcd9ee38df76dcb7cf4f88c13afb9d03cba53060b37b268547fe757780
grep -Fxq '[svs-laravel]' "$fpm_target"
grep -Fxq 'listen = /run/php/svs-laravel.sock' "$fpm_target"
nginx -t
php-fpm8.3 -t
backup=/var/backups/clubunio-mobile-upload-limits/20261104
install -d -m 0700 /var/backups/clubunio-mobile-upload-limits
mkdir -m 0700 "$backup"
cp -a "$nginx_target" "$backup/nginx.conf"
cp -a "$fpm_target" "$backup/fpm.conf"
rollback() {
 trap - ERR
 cp -a "$backup/nginx.conf" "$nginx_target"
 cp -a "$backup/fpm.conf" "$fpm_target"
 if nginx -t && php-fpm8.3 -t; then
  systemctl reload php8.3-fpm
  systemctl reload nginx
 fi
 echo 'Upload-limit installation failed; reviewed prior files restored.' >&2
 exit 1
}
trap rollback ERR
install -m 0644 pi-nginx.conf "$nginx_target"
install -m 0644 php-fpm/svs-laravel.conf "$fpm_target"
nginx -t
php-fpm8.3 -t
systemctl reload php8.3-fpm
systemctl reload nginx
systemctl is-active --quiet php8.3-fpm
systemctl is-active --quiet nginx
test "$(sha256sum "$nginx_target" | cut -d ' ' -f 1)" = "$nginx_desired"
test "$(sha256sum "$fpm_target" | cut -d ' ' -f 1)" = "$fpm_desired"
trap - ERR
printf 'ClubUnio DEV only:40MiB request/20MiB file transport; endpoint limits retained; backup %s.\n' "$backup"
