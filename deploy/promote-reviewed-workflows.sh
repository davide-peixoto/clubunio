#!/usr/bin/env bash
set -euo pipefail
umask 077
env=${1:?stage or prod}
case "$env" in
 stage) suffix=Stage; origin=https://stage.clubunio.com ;;
 prod) suffix=Prod; origin=https://app.clubunio.com ;;
 *) exit 1 ;;
esac
root=/home/runcloud/webapps/ClubUnio$suffix
php=/RunCloud/Packages/php83rc/bin/php
backup=/root/clubunio-releases/workflows-$env-20261124
test ! -e "$backup/complete"
if ! test -e "$backup"; then
mkdir -m 700 "$backup"
openssl rand -hex 32 > "$backup/backup.key"
runuser -u postgres -- pg_dump -Fc -n clubunio "clubunio_$env" |
 openssl enc -aes-256-cbc -salt -pbkdf2 -pass "file:$backup/backup.key" -out "$backup/database.dump.enc"
openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$backup/backup.key" -in "$backup/database.dump.enc" | pg_restore --file=/dev/null
tar -tzf "/root/clubunio-$suffix-promotion-20261124.tar.gz" > "$backup/package-files"
cp -a "$root/bootstrap/cache" "$backup/bootstrap-cache"
cp "/root/clubunio-$suffix-code-before-20261124.tar.gz" "$backup/source.tar.gz"
mkdir "$backup/package"
tar -xzf "/root/clubunio-$suffix-promotion-20261124.tar.gz" -C "$backup/package"
cd "$backup/package"
sha256sum -c deploy/promotion.manifest > "$backup/source-verification.log"
find app database -type f -name '*.php' -print0 | xargs -0 -r -n1 "$php" -l > "$backup/php-lint.log"
# Record absent paths for precise rollback; preserve all other target files.
while IFS= read -r path; do
 [[ "$path" != *..* && "$path" != /* ]]
 if ! test -e "$root/$path"; then echo "$path" >> "$backup/new-files"; fi
done < "$backup/package-files"
fi
if ! runuser -u postgres -- psql -X -At -d "clubunio_$env" -c "SELECT 1 FROM clubunio.migrations WHERE migration='2026_11_24_010000_work_hub'" | grep -qx 1; then
 runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d "clubunio_$env" -f "/tmp/cu-$env-work-hub.sql" > "$backup/migration.log"
fi
cd "$root"
rollback() {
 status=$?
 set +e
 if test "$status" != 0; then
  tar -xzf "$backup/source.tar.gz"
  if test -f "$backup/new-files"; then while IFS= read -r path; do rm -f -- "$path"; done < "$backup/new-files"; fi
  cp -a "$backup/bootstrap-cache/." bootstrap/cache/
  runuser -u runcloud -- "$php" artisan view:cache
  runuser -u runcloud -- "$php" artisan up
  systemctl reload php83rc-fpm
  echo "Application restored; additive tables retained. Backup: $backup" >&2
 fi
 exit "$status"
}
trap rollback EXIT
runuser -u runcloud -- "$php" artisan down --retry=30
tar -xzf "/root/clubunio-$suffix-promotion-20261124.tar.gz"
# Tar-created source directories must be traversable by the application user.
while IFS= read -r -d '' directory; do
 relative=${directory#"$backup/package/"}
 test "$directory" = "$backup/package" && continue
 chown --reference=artisan "$relative"
 chmod 755 "$relative"
done < <(find "$backup/package" -type d -print0)
sed 's/clubora\./clubunio./g' /tmp/cu-check-promoted-workflows.php > deploy/check-promoted-workflows.php
chown --reference=artisan deploy/check-promoted-workflows.php
chmod 644 deploy/check-promoted-workflows.php
while IFS= read -r path; do chown --reference=artisan "$path"; done < "$backup/package-files"
sha256sum -c deploy/promotion.manifest > "$backup/deployed-verification.log"
runuser -u runcloud -- "$php" artisan route:cache
runuser -u runcloud -- "$php" artisan view:cache
runuser -u runcloud -- "$php" deploy/check-promoted-workflows.php > "$backup/acceptance.log" 2>&1
runuser -u runcloud -- "$php" artisan up
systemctl reload php83rc-fpm
trap - EXIT
curl -fsS -o /dev/null "$origin/css/clubunio-dashboard.css"
touch "$backup/complete"
echo "Promoted $env; backup and acceptance: $backup"
tail -1 "$backup/acceptance.log"
