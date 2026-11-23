#!/usr/bin/env bash
set -euo pipefail
umask 027
instance=${1:?dev, stage or prod required}
patch=${2:?Reviewed mapped patch required}
php_bin=${3:-php}
"$php_bin" -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);'
case "$instance" in
  dev)
    base=/var/www/svs-laravel
    previous=$(readlink -f "$base/current")
    [[ "$previous" = "$base/releases/"* ]]
    root="$base/releases/20261123-clubunio-openai-models"
    test ! -e "$root"
    cp -a "$previous" "$root"
    ;;
  stage) root=/home/runcloud/webapps/ClubUnioStage; previous=$root ;;
  prod) root=/home/runcloud/webapps/ClubUnioProd; previous=$root ;;
  *) exit 1 ;;
esac
test -f "$patch"
cd "$root"
test -f bootstrap/cache/config.php
backup="$root/storage/app/private/openai-model-upgrades/release-$instance-$(date -u +%Y%m%d-%H%M%S)"
mkdir -m 0700 -p "$backup"
cp -p bootstrap/cache/config.php "$backup/config.php"
mapfile -t paths < <(git apply --numstat "$patch" | cut -f3)
for path in "${paths[@]}"; do
  [[ "$path" =~ ^(app/ClubUnio/(Assistant|Mobile|Services)/[A-Za-z]+\.php|config/clubunio-(assistant|mobile|support)\.php|deploy/(check-openai-models|upgrade-openai-models|prepare-openai-model-config)\.php)$ ]]
done
existing=()
for path in "${paths[@]}"; do if test -f "$path"; then existing+=("$path"); fi; done
tar -czf "$backup/source.tar.gz" "${existing[@]}"
db_backup=''
restore_on_failure() {
  status=$?
  if test "$status" != 0; then
    if test -n "$db_backup"; then
      "$php_bin" deploy/upgrade-openai-models.php "clubunio_$instance" --restore "$db_backup" || true
    fi
    tar -xzf "$backup/source.tar.gz"
    cp -p "$backup/config.php" bootstrap/cache/config.php
    echo "AI release stopped; previous source/config restored. Backup: $backup" >&2
  fi
  exit "$status"
}
trap restore_on_failure EXIT
git -c safe.directory="$root" apply --check "$patch"
git -c safe.directory="$root" apply --include=app/ClubUnio/Services/OpenAiModels.php "$patch"
git -c safe.directory="$root" apply --exclude=app/ClubUnio/Services/OpenAiModels.php "$patch"
for path in "${paths[@]}"; do
  chown --reference=artisan "$path"
  chmod 0644 "$path"
  "$php_bin" -l "$path" >/dev/null
done
"$php_bin" deploy/upgrade-openai-models.php "clubunio_$instance" --inspect
"$php_bin" deploy/prepare-openai-model-config.php "$previous" "$root/bootstrap/cache/config.php"
chown --reference=artisan bootstrap/cache/config.php
set +e
"$php_bin" deploy/check-openai-models.php --live-synthetic | tee "$backup/provider-check.log"
provider_status=${PIPESTATUS[0]}
set -e
if test "$provider_status" = 0; then
  test "$(grep -c '"synthetic_json":"passed"' "$backup/provider-check.log")" = 3
elif test "$provider_status" = 2; then
  grep -q '"reason":"no_existing_key"' "$backup/provider-check.log"
  echo 'Model selection prepared; real provider check pending private key configuration.'
else
  exit 1
fi
"$php_bin" deploy/upgrade-openai-models.php "clubunio_$instance" --apply | tee "$backup/migration.log"
db_backup=$(sed -n 's/^Private rollback: //p' "$backup/migration.log")
"$php_bin" deploy/check-openai-models.php
if test "$instance" = dev; then
  "$php_bin" deploy/verify-pi-dev-vps-database.php
  test "$(readlink -f "$base/current")" = "$previous"
  test ! -e "$base/current.openai-next"
  ln -s "$root" "$base/current.openai-next"
  mv -Tf "$base/current.openai-next" "$base/current"
  systemctl reload php8.3-fpm
fi
trap - EXIT
printf 'AI models deployed on %s; previous=%s; private backup=%s\n' "$instance" "$previous" "$backup"
