#!/usr/bin/env bash
set -euo pipefail
db=clubunio_bexio_qa_20261124
if sudo -u postgres psql -At -d postgres -c "SELECT 1 FROM pg_database WHERE datname='$db'" | grep -q 1; then
  echo 'QA database already exists; no automatic replacement.'
  exit 1
fi
sudo -u postgres createdb --template=template0 --owner=userunio "$db"
sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres -c "REVOKE CONNECT ON DATABASE $db FROM PUBLIC; GRANT CONNECT ON DATABASE $db TO userunio;"
sudo -u postgres pg_dump --schema-only --no-owner --no-privileges --schema=clubunio clubunio_dev |
  sudo -u postgres psql -v ON_ERROR_STOP=1 --single-transaction -d "$db" >/dev/null
sudo -u postgres psql -v ON_ERROR_STOP=1 --single-transaction -d "$db" <<'SQL'
ALTER SCHEMA clubunio RENAME TO clubora;
ALTER SCHEMA clubora OWNER TO userunio;
GRANT USAGE, CREATE ON SCHEMA clubora TO userunio;
GRANT ALL ON ALL TABLES IN SCHEMA clubora TO userunio;
GRANT ALL ON ALL SEQUENCES IN SCHEMA clubora TO userunio;
SQL
echo 'QA schema prepared without source rows.'
