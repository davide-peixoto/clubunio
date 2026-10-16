#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(id -u)" = 0 || { echo 'Run with sudo.' >&2; exit 1; }
source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
source_file="$source_dir/systemd/nginx.service.d/10-clubunio-recovery.conf"
target=/etc/systemd/system/nginx.service.d/10-clubunio-recovery.conf
test -f "$source_file"
systemctl is-active --quiet nginx
nginx -t
backup="/var/backups/clubunio-nginx-recovery/$(date -u +%Y%m%dT%H%M%S)-$$"
install -d -m 0700 "$backup"
if test -f "$target"; then
    cp -a "$target" "$backup/previous.conf"
else
    touch "$backup/previously-absent"
fi
systemctl cat nginx > "$backup/nginx-unit-before.txt"
install -d -m 0755 /etc/systemd/system/nginx.service.d
install -m 0644 "$source_file" "$target"
systemctl daemon-reload
systemd-analyze verify nginx.service
test "$(systemctl show nginx -p Restart --value)" = on-failure
test "$(systemctl show nginx -p RestartUSec --value)" = 10s
test "$(systemctl show nginx -p StartLimitIntervalUSec --value)" = 0
systemctl is-active --quiet nginx
printf 'Nginx recovery enabled without restarting it; backup: %s\n' "$backup"
systemctl show nginx -p Restart -p RestartUSec -p StartLimitIntervalUSec
