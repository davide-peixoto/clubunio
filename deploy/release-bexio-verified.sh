#!/usr/bin/env bash
set -euo pipefail
instance=${1:?instance}
package=${2:?private package path}
case "$instance" in
  dev) base=$(readlink -f /var/www/svs-laravel/current); owner=www-data ;;
  stage) base=/home/runcloud/webapps/ClubUnioStage; owner=runcloud ;;
  prod) base=/home/runcloud/webapps/ClubUnioProd; owner=runcloud ;;
  *) exit 1 ;;
esac
path=app/ClubUnio/Services/BexioSandboxDispatchService.php
backup="$base/storage/app/private/bexio-verified/$instance-$(date -u +%Y%m%d-%H%M%S)"
install -d -m 700 "$backup"
/usr/bin/php -l "$package/BexioSandboxDispatchService.php"
cp -p "$base/$path" "$backup/BexioSandboxDispatchService.php"
sha256sum "$base/$path" > "$backup/before.sha256"
install -o "$owner" -g "$owner" -m 644 "$package/BexioSandboxDispatchService.php" "$base/$path"
if ! env CLUBUNIO_BASE="$base" /usr/bin/php "$package/check-bexio-guards.php"; then
  cp -p "$backup/BexioSandboxDispatchService.php" "$base/$path"
  echo 'Guard check failed; previous service restored.' >&2
  exit 1
fi
sha256sum "$base/$path" > "$backup/after.sha256"
echo "Verified Bexio service installed; backup: $backup"
