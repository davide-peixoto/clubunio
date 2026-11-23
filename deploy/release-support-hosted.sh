#!/usr/bin/env bash
set -euo pipefail
umask 077
instance=${1:?stage or prod}
source=${2:?reviewed source directory}
case "$instance" in
  stage) root=/home/runcloud/webapps/ClubUnioStage ;;
  prod) root=/home/runcloud/webapps/ClubUnioProd ;;
  *) exit 1 ;;
esac
php=/RunCloud/Packages/php83rc/bin/php
cd "$root"
test -f "$source/deploy/promote-support-workspace.php"
backup="/root/clubunio-releases/support-$instance-$(date -u +%Y%m%d-%H%M%S)"
mkdir -m 0700 -p "$backup"
mapfile -t files < <("$php" "$source/deploy/promote-support-workspace.php" "$source" files)
existing=()
for file in "${files[@]}"; do
  [[ "$file" != *..* && "$file" =~ ^(app|resources/views|public/(css|js)|lang)/ ]]
  if test -f "$file"; then existing+=("$file"); else echo "$file" >> "$backup/new-files"; fi
done
test "${#existing[@]}" -gt 0
tar -czf "$backup/source.tar.gz" "${existing[@]}"
tar -tzf "$backup/source.tar.gz" >/dev/null
cp -a bootstrap/cache "$backup/bootstrap-cache"
rollback() {
  status=$?
  if test "$status" != 0; then
    tar -xzf "$backup/source.tar.gz"
    if test -f "$backup/new-files"; then
      while IFS= read -r file; do rm -f -- "$file"; done < "$backup/new-files"
    fi
    cp -a "$backup/bootstrap-cache/." bootstrap/cache/
    sudo -u runcloud "$php" artisan view:cache
    systemctl reload php83rc-fpm
    echo "Support update restored from $backup" >&2
  fi
  exit "$status"
}
trap rollback EXIT
"$php" "$source/deploy/promote-support-workspace.php" "$source" apply
for file in "${files[@]}"; do
  if [[ "$file" = *.php ]]; then "$php" -l "$file" >/dev/null; fi
done
sudo -u runcloud "$php" artisan view:cache
sudo -u runcloud "$php" "$source/deploy/check-support-promotion.php" | tee "$backup/acceptance.log"
systemctl reload php83rc-fpm
trap - EXIT
echo "Support workspace deployed on $instance; backup: $backup"
