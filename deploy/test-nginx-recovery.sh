#!/usr/bin/env bash
set -euo pipefail
umask 027
test "$(id -u)" = 0 || { echo 'Run with sudo.' >&2; exit 1; }
unit=clubunio-nginx-recovery-check.service
work=/run/clubunio-nginx-recovery-check
unit_file="/run/systemd/system/$unit"
drop_dir="/run/systemd/system/$unit.d"
source_file=/etc/systemd/system/nginx.service.d/10-clubunio-recovery.conf
test -f "$source_file"
# Refuse to reuse another check or manipulate an address/interface.
test ! -e "$work" && test ! -e "$unit_file" && test ! -e "$drop_dir"
if ip -o -4 address show | grep -q '198\.51\.100\.254/'; then
    echo 'Test address unexpectedly assigned; refusing test.' >&2
    exit 1
fi
if ss -ltnH 'sport = :18092' | grep -q .; then
    echo 'Test port is already occupied.' >&2
    exit 1
fi
cleanup() {
    systemctl stop "$unit" || true
    rm -f "$drop_dir/10-clubunio-recovery.conf" "$unit_file"
    rmdir "$drop_dir"
    systemctl daemon-reload
    systemctl reset-failed "$unit" 2>/dev/null || true
    # Only these known synthetic files; never remove a website or its logs.
    rm -f "$work/nginx.conf" "$work/nginx.pid" "$work/error.log"
    rmdir "$work"
}
install -d -m 0755 "$work" "$drop_dir"
trap cleanup EXIT
install -m 0644 "$source_file" "$drop_dir/10-clubunio-recovery.conf"
write_config() {
    local address=$1
    cat > "$work/nginx.conf" <<EOF
pid $work/nginx.pid;
error_log $work/error.log notice;
events {}
http {
    access_log off;
    server { listen $address:18092; return 200 'recovery-check'; }
}
EOF
}
write_config 198.51.100.254
cat > "$unit_file" <<EOF
[Unit]
Description=Isolated ClubUnio nginx recovery check
[Service]
Type=forking
PIDFile=$work/nginx.pid
ExecStartPre=/usr/sbin/nginx -t -q -p $work/ -c $work/nginx.conf
ExecStart=/usr/sbin/nginx -p $work/ -c $work/nginx.conf
ExecStop=/usr/sbin/nginx -p $work/ -c $work/nginx.conf -s quit
EOF
systemctl daemon-reload
systemctl start "$unit" || true
sleep 12
retries=$(systemctl show "$unit" -p NRestarts --value)
test "$retries" -ge 1
test "$(systemctl show "$unit" -p SubState --value)" = auto-restart
echo "Missing-address ExecStartPre failure automatically retried: $retries"
# The next scheduled retry must succeed without another systemctl start.
write_config 127.0.0.1
for attempt in $(seq 1 20); do
    if systemctl is-active --quiet "$unit"; then break; fi
    sleep 1
done
systemctl is-active --quiet "$unit"
test "$(curl --max-time 5 -s http://127.0.0.1:18092/)" = recovery-check
echo 'Automatic recovery after address becomes available: HTTP200'
systemctl stop "$unit"
sleep 12
test "$(systemctl show "$unit" -p ActiveState --value)" = inactive
echo 'Explicit stop remains stopped; no unintended restart'
journalctl -u "$unit" -n 16 --no-pager
systemctl is-active --quiet nginx
