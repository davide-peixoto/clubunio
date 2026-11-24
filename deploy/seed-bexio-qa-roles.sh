#!/usr/bin/env bash
set -euo pipefail
db=clubunio_bexio_qa_20261124
test "$(sudo -u postgres psql -At -d "$db" -c 'SELECT count(*) FROM clubora.tenants')" = 0
test "$(sudo -u postgres psql -At -d "$db" -c 'SELECT count(*) FROM clubora.account_roles')" = 0
# Only the global role lookup, never memberships, accounts or personal data.
sudo -u postgres pg_dump --data-only --no-owner --no-privileges --table=clubunio.account_roles clubunio_dev |
  sed 's/^COPY clubunio\./COPY clubora./' |
  sudo -u postgres psql -v ON_ERROR_STOP=1 --single-transaction -d "$db" >/dev/null
echo 'Global QA role definitions seeded.'
