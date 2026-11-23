#!/usr/bin/env bash
set -euo pipefail
umask 027
instance=${1:?dev stage or prod required}
patch=${2:?Reviewed mapped patch required}
php_bin=${3:-/usr/bin/php}
"$php_bin" -r 'exit(in_array("pgsql",PDO::getAvailableDrivers(),true)?0:1);'
case "$instance" in
  dev)
    base=/var/www/svs-laravel
    previous=$(readlink -f "$base/current")
    [[ "$previous" = "$base/releases/"* ]]
    root="$base/releases/20261123-clubunio-tenant-ai"
    test ! -e "$root"
    cp -a "$previous" "$root"
    ;;
  stage) root=/home/runcloud/webapps/ClubUnioStage; previous=$root ;;
  prod) root=/home/runcloud/webapps/ClubUnioProd; previous=$root ;;
  *) exit 1 ;;
esac
cd "$root"
backup="$root/storage/app/private/tenant-ai-releases/$instance-$(date -u +%Y%m%d-%H%M%S)"
mkdir -m 0700 -p "$backup"
cp -pL .env "$backup/environment"
cp -a bootstrap/cache "$backup/cache"
mapfile -t paths < <(git apply --numstat "$patch" | cut -f3)
existing=()
for path in "${paths[@]}"; do
  [[ ( "$path" =~ ^(app|config|database/clubunio|deploy|resources/views|lang|routes)/(.*)$ || "$path" = public/css/clubunio-tenant-ai.css ) && "$path" != *..* ]]
  if test -f "$path"; then existing+=("$path"); fi
done
tar -czf "$backup/source.tar.gz" "${existing[@]}"
restore_failure() {
  status=$?
  if test "$status" != 0; then
    tar -xzf "$backup/source.tar.gz"
    cp -p "$backup/environment" "$(readlink -f .env)"
    cp -a "$backup/cache/." bootstrap/cache/
    echo "Tenant AI release stopped; previous configuration/source restored: $backup" >&2
  fi
  exit "$status"
}
trap restore_failure EXIT
git -c safe.directory="$root" apply --ignore-space-change --unidiff-zero --check "$patch"
git -c safe.directory="$root" apply --ignore-space-change --unidiff-zero "$patch"
for path in "${paths[@]}"; do
  chown --reference=artisan "$path"
  chmod 0644 "$path"
  if [[ "$path" = *.php ]]; then "$php_bin" -l "$path" >/dev/null; fi
done
"$php_bin" deploy/prepare-tenant-ai-config.php "$previous"
chown --reference=artisan bootstrap/cache/config.php
owner=$(stat -c '%U' artisan)
sudo -u "$owner" "$php_bin" artisan route:cache
sudo -u "$owner" "$php_bin" artisan view:cache
"$php_bin" deploy/adopt-tenant-ai-legacy.php "clubunio_$instance"
runtime_user=$owner
if test "$instance" = dev; then runtime_user=www-data; fi
sudo -u "$runtime_user" "$php_bin" deploy/check-tenant-ai.php | tee "$backup/acceptance.log"
grep -q '"all_data":"rolled_back"' "$backup/acceptance.log"
if test "$instance" = dev; then
  "$php_bin" deploy/verify-pi-dev-vps-database.php
  test "$(readlink -f "$base/current")" = "$previous"
  test ! -e "$base/current.tenant-ai-next"
  ln -s "$root" "$base/current.tenant-ai-next"
  mv -Tf "$base/current.tenant-ai-next" "$base/current"
  systemctl reload php8.3-fpm
fi
trap - EXIT
printf 'Tenant AI deployed on %s; previous=%s; backup=%s\n' "$instance" "$previous" "$backup"
