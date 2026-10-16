#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(id -u)" = 0 || { echo 'Run with sudo.' >&2; exit 1; }
source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
target=/etc/php/8.3/fpm/pool.d/svs-laravel.conf
expected=c89cdb82487b6381854f9e507039bd983ee532bcba17be59c11dfabc0d976973
current=$(sha256sum "$target" | cut -d ' ' -f 1)
desired=$(sha256sum "$source_dir/php-fpm/svs-laravel.conf" | cut -d ' ' -f 1)
if test "$current" = "$desired"; then
    echo 'ClubUnio FPM settings already installed.'
    exit 0
fi
test "$current" = "$expected" || { echo 'Pool changed; review before replacing.' >&2; exit 1; }
php-fpm8.3 -t
backup="/var/backups/clubunio-fpm-performance/$(date -u +%Y%m%dT%H%M%S)-$$"
install -d -m 0700 "$backup"
cp -a "$target" "$backup/svs-laravel.conf"
install -m 0644 "$source_dir/php-fpm/svs-laravel.conf" "$target"
if ! php-fpm8.3 -t; then
    cp -a "$backup/svs-laravel.conf" "$target"
    echo 'FPM configuration invalid; original restored.' >&2
    exit 1
fi
systemctl reload php8.3-fpm
systemctl is-active --quiet php8.3-fpm
printf 'ClubUnio pool tuned with graceful reload; backup: %s\n' "$backup"
