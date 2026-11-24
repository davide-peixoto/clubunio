#!/usr/bin/env bash
set -euo pipefail
umask 077
backup=/root/clubunio-releases/glass-promotion-20261124
test ! -e "$backup"
mkdir -m 700 "$backup"
test -f /opt/keycloak/themes/clubunio-glass-dev/login/resources/js/clubunio-glass-v6.js
test ! -e /opt/keycloak/themes/clubunio-glass
test ! -e /opt/keycloak/themes/sv-sissach-glass
cp -a /opt/keycloak/themes/clubunio-glass-dev /opt/keycloak/themes/clubunio-glass
cp -a /opt/keycloak/themes/sv-sissach-glass-dev /opt/keycloak/themes/sv-sissach-glass
sed -i 's/^parent=clubunio-glass-dev$/parent=clubunio-glass/' /opt/keycloak/themes/sv-sissach-glass/login/theme.properties
# Only four client presentation attributes; no credentials, flows or realm policy.
cat > "$backup/targets.sql" <<'SQL'
CREATE TEMP TABLE targets(client_id text PRIMARY KEY, theme text);
INSERT INTO targets VALUES
 ('clubunio-platform-stage','clubunio-glass'),
 ('clubunio-verein-stage','sv-sissach-glass'),
 ('clubunio-platform-prod','clubunio-glass'),
 ('clubunio-verein-prod','clubunio-glass');
SQL
{ cat "$backup/targets.sql"; cat <<'SQL'
SELECT format('DELETE FROM client_attributes WHERE client_id=%L AND name=''login_theme'';',c.id)
 || CASE WHEN a.value IS NULL THEN '' ELSE E'\n'||format('INSERT INTO client_attributes(client_id,name,value) VALUES (%L,''login_theme'',%L);',c.id,a.value) END
FROM client c JOIN realm r ON r.id=c.realm_id JOIN targets t ON t.client_id=c.client_id
LEFT JOIN client_attributes a ON a.client_id=c.id AND a.name='login_theme' WHERE r.name='clubunio';
SQL
} | runuser -u postgres -- psql -X -qAt -v ON_ERROR_STOP=1 -d keycloak > "$backup/rollback.sql"
{ echo 'BEGIN;'; cat "$backup/targets.sql"; cat <<'SQL'
DO $$ BEGIN
 IF (SELECT count(*) FROM client c JOIN realm r ON r.id=c.realm_id JOIN targets t ON t.client_id=c.client_id WHERE r.name='clubunio')<>4 THEN RAISE EXCEPTION 'Client scope mismatch'; END IF;
END $$;
DELETE FROM client_attributes a USING client c,realm r,targets t
WHERE a.client_id=c.id AND c.realm_id=r.id AND r.name='clubunio' AND c.client_id=t.client_id AND a.name='login_theme';
INSERT INTO client_attributes(client_id,name,value)
SELECT c.id,'login_theme',t.theme FROM client c JOIN realm r ON r.id=c.realm_id JOIN targets t ON t.client_id=c.client_id WHERE r.name='clubunio';
COMMIT;
SQL
} > "$backup/apply.sql"
# Apply offline to avoid stale client caches; existing sessions stay in the DB.
systemctl stop keycloak
trap 'systemctl start keycloak' EXIT
runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d keycloak < "$backup/apply.sql" > "$backup/apply.log"
systemctl start keycloak
trap - EXIT
echo "Four Stage/PROD login themes promoted; rollback: $backup/rollback.sql"
