#!/usr/bin/env bash
set -euo pipefail
umask 077
database=${1:?ClubUnio target database required}
sql=${2:?Reviewed SQL required}
[[ "$database" =~ ^clubunio_(dev|stage|prod)$ ]]
test -f "$sql"
test "$(sudo -u postgres psql -d "$database" -Atc 'select current_database()')" = "$database"
existing=$(sudo -u postgres psql -d "$database" -Atc "select count(*) from clubunio.migrations where migration='2026_11_23_000200_tenant_ai'")
if test "$existing" = 1; then echo 'Tenant AI schema already applied'; exit 0; fi
test "$existing" = 0
backup="/root/clubunio-backups/$database-before-tenant-ai-$(date -u +%Y%m%d-%H%M%S).dump"
mkdir -p /root/clubunio-backups
sudo -u postgres pg_dump -Fc -d "$database" > "$backup"
pg_restore --list "$backup" >/dev/null
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d "$database" < "$sql"
printf 'Verified private backup: %s\n' "$backup"
