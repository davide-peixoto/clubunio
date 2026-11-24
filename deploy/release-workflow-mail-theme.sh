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
phpbin=/usr/bin/php
backup="$base/storage/app/private/workflow-mail-releases/$instance-$(date -u +%Y%m%d-%H%M%S)"
install -d -m 700 "$backup"
for name in TestOutboxDispatcher TestMailService; do
  path="app/ClubUnio/Services/$name.php"
  "$phpbin" -l "$package/$name.php"
  cp -p "$base/$path" "$backup/$name.php"
  sha256sum "$base/$path" >> "$backup/before.sha256"
done
for locale in de en; do
  path="lang/$locale/mail_theme.php"
  "$phpbin" -l "$package/$locale-mail_theme.php"
  cp -p "$base/$path" "$backup/$locale-mail_theme.php"
  sha256sum "$base/$path" >> "$backup/before.sha256"
done
for name in TestOutboxDispatcher TestMailService; do
  path="app/ClubUnio/Services/$name.php"
  install -o "$owner" -g "$owner" -m 644 "$package/$name.php" "$base/$path"
  sha256sum "$base/$path" >> "$backup/after.sha256"
done
for locale in de en; do
  path="lang/$locale/mail_theme.php"
  install -o "$owner" -g "$owner" -m 644 "$package/$locale-mail_theme.php" "$base/$path"
  sha256sum "$base/$path" >> "$backup/after.sha256"
done
echo "Installed workflow mail services and UTF-8 language files; backup: $backup"
