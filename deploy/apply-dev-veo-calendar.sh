#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(hostname -f)" = vps.clubunio.com
case "${1:-calendar}" in
    calendar) root=/root/clubunio-releases/veo-calendar-20261124 ;;
    follow-fixture) root=/root/clubunio-releases/veo-follow-fixture-20261124 ;;
    *) exit 1 ;;
esac
test "$(sudo -u postgres psql -X -At -d clubunio_dev -c 'select current_database()')" = clubunio_dev
test ! -e "$root/applied"
if test ! -e "$root/dev.dump.enc"; then
    test ! -e "$root/backup.key"
    openssl rand -hex 32 > "$root/backup.key"
    sudo -u postgres pg_dump -Fc clubunio_dev | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$root/backup.key" -out "$root/dev.dump.enc"
fi
# Read the whole archive without executing its SQL; TOC-only reads close the pipe early.
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$root/backup.key" -in "$root/dev.dump.enc" | pg_restore --file=/dev/null
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d clubunio_dev < "$root/migration.sql"
touch "$root/applied"
printf 'DEV additive migration applied; encrypted backup verified in %s\n' "$root"
