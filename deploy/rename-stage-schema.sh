#!/usr/bin/env bash
set -euo pipefail
umask 077
app=/home/runcloud/webapps/ClubUnioStage
php=/RunCloud/Packages/php83rc/bin/php
branch=codex/stage-clubunio-schema
old=6621b86
fail() { printf 'Stage preflight: %s\n' "$1" >&2; exit 1; }
test "$#" -eq 2
candidate=$1
bundle=$2
[[ "$candidate" =~ ^[0-9a-f]{40}$ ]]
test -f "$bundle" || fail 'bundle not found'
test -x "$php" || fail 'Stage PHP 8.3 CLI not found'
test -f /etc/php83rc/fpm.d/ClubUnioStage.conf || fail 'Stage PHP 8.3 pool not found'
exec 9>/var/lock/clubunio-stage-schema.lock
flock -n 9 || fail 'another Stage schema cutover is running'
"$php" -m | grep -x pdo_pgsql >/dev/null || fail 'PDO-PgSQL missing in PHP 8.3 CLI'
/RunCloud/Packages/php83rc/sbin/php-fpm -m | grep -x pdo_pgsql >/dev/null || fail 'PDO-PgSQL missing in PHP 8.3 FPM'
cd "$app"
as_app() { runuser -u runcloud -- "$@"; }
psql_stage() { runuser -u postgres -- psql -X -qAt -v ON_ERROR_STOP=1 -d clubunio_stage "$@"; }
test "$(as_app git rev-parse --short=7 HEAD)" = "$old" || fail 'Stage baseline changed'
test -z "$(as_app git status --porcelain --untracked-files=no)" || fail 'Stage checkout has tracked changes'
test ! -e storage/framework/down || fail 'Stage already in maintenance mode'
test "$(psql_stage -c "SELECT current_database() || ':' || (to_regnamespace('clubora') IS NOT NULL)::text || ':' || (to_regnamespace('clubunio') IS NULL)::text")" = 'clubunio_stage:true:true' || fail 'unexpected Stage schema state'
test "$(psql_stage -c "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='clubora' AND p.prosrc ~ '\mclubora\M'")" = 0 || fail 'stored routines still reference clubora'
as_app git fetch --no-tags "$bundle" "refs/heads/$branch"
test "$(as_app git rev-parse FETCH_HEAD)" = "$candidate"

backup=$(mktemp -d /root/clubunio-stage-schema-XXXXXXXX)
openssl rand -base64 48 > "$backup/passphrase"
runuser -u postgres -- pg_dump -Fc --schema=clubora --lock-wait-timeout=5s clubunio_stage |
    gpg --quiet --batch --pinentry-mode loopback --passphrase-file "$backup/passphrase" --symmetric --cipher-algo AES256 --output "$backup/clubora.dump.gpg"
gpg --quiet --batch --pinentry-mode loopback --passphrase-file "$backup/passphrase" --decrypt "$backup/clubora.dump.gpg" |
    pg_restore --file=/dev/null
sha256sum "$backup/clubora.dump.gpg" > "$backup/SHA256SUMS"
echo "Verified encrypted Stage backup: $backup"

fingerprint() {
    psql_stage -v schema="$1" <<'SQL'
BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
SELECT format('SELECT %L, count(*), md5(coalesce(string_agg(h, %L ORDER BY h), %L)) FROM (SELECT md5(to_jsonb(t)::text) AS h FROM %I.%I t) q',
              c.oid::text || ':' || c.relname,'','',n.nspname,c.relname)
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname IN (:'schema','legacy_prod','migration_audit') AND c.relkind IN ('r','p') ORDER BY c.oid
\gexec
ROLLBACK;
SQL
}

clear_app_caches() {
    as_app "$php" artisan config:clear
    as_app "$php" artisan route:clear
    as_app "$php" artisan view:clear
}

renamed=0
code_changed=0
maintenance=0
acl_changed=0
rollback() {
    rc=$?
    trap - EXIT
    if (( rc == 0 )); then return; fi
    set +e
    if (( renamed || code_changed )); then
        as_app "$php" artisan down --retry=60
        maintenance=1
    fi
    if (( renamed )); then
        psql_stage -c "BEGIN; SET LOCAL lock_timeout='5s'; SET LOCAL ROLE userunio; ALTER SCHEMA clubunio RENAME TO clubora; COMMIT;" || exit 2
    fi
    if (( code_changed )); then
        as_app git switch --detach "$old" || exit 2
        clear_app_caches || exit 2
    fi
    if (( acl_changed )); then setfacl --restore="$backup/postgresql-config.acl" || exit 2; fi
    if (( maintenance )); then as_app "$php" artisan up || exit 2; fi
    echo 'Stage cutover failed; prior code and schema restored.' >&2
    exit "$rc"
}
trap rollback EXIT
if ! as_app test -r "$app/.env.postgresql.local"; then
    getfacl -p "$app/.env.postgresql.local" > "$backup/postgresql-config.acl"
    setfacl -m u:runcloud:r--,o::--- "$app/.env.postgresql.local"
    acl_changed=1
fi
as_app "$php" <<'PHP'
<?php
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    $identity = $db->selectOne('SELECT current_database() AS db, current_schema() AS schema');
    if ($identity->db !== 'clubunio_stage' || $identity->schema !== 'clubora') throw new LogicException('Unexpected baseline');
    echo "Stage runtime database preflight passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Stage runtime database preflight failed ('.$e->getCode().').'.PHP_EOL);
    exit(1);
}
PHP
as_app "$php" artisan down --retry=60
maintenance=1
fingerprint clubora > "$backup/before.tsv"
psql_stage <<'SQL'
BEGIN;
SET LOCAL lock_timeout='5s';
SET LOCAL ROLE userunio;
ALTER SCHEMA clubora RENAME TO clubunio;
SELECT count(*) FROM clubunio.migrations;
ROLLBACK;
SQL
test "$(psql_stage -c "SELECT (to_regnamespace('clubora') IS NOT NULL)::text || ':' || (to_regnamespace('clubunio') IS NULL)::text")" = 'true:true'

psql_stage -c "BEGIN; SET LOCAL lock_timeout='5s'; SET LOCAL ROLE userunio; ALTER SCHEMA clubora RENAME TO clubunio; COMMIT;"
renamed=1
code_changed=1
as_app git switch --detach "$candidate"
clear_app_caches
fingerprint clubunio > "$backup/after.tsv"
cmp "$backup/before.tsv" "$backup/after.tsv"
as_app "$php" deploy/check-stage-schema.php
as_app "$php" artisan view:cache
as_app "$php" artisan up
maintenance=0
base=https://stage.clubunio.com
test "$(curl --silent --show-error --max-time 15 --output /dev/null --write-out '%{http_code}' "$base/up")" = 200
test "$(curl --silent --show-error --max-time 15 --output /dev/null --write-out '%{http_code}' "$base/login")" = 200
test "$(curl --silent --show-error --max-time 15 --output /dev/null --write-out '%{http_code}' "$base/members")" = 302
trap - EXIT
echo "Stage schema renamed; verified $(wc -l < "$backup/after.tsv") table fingerprints, including untouched snapshot schemas."
