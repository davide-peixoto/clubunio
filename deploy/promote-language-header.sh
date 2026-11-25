#!/usr/bin/env bash
set -euo pipefail
umask 077
case "${1:?stage or prod}" in
 stage) suffix=Stage; origin=https://stage.clubunio.com ;;
 prod) suffix=Prod; origin=https://app.clubunio.com ;;
 *) exit 1 ;;
esac
root=/home/runcloud/webapps/ClubUnio$suffix
backup=/root/clubunio-releases/language-header-${1}-visual-20261125
patch=/root/clubunio-language-header-visual-20261125.patch
php=/RunCloud/Packages/php83rc/bin/php
test ! -e "$backup/complete"
cd "$root"
git -c safe.directory="$root" apply --check "$patch"
if test ! -e "$backup"; then
install -d -m 700 "$backup"
tar -cf "$backup/source.tar" resources/views/clubunio/language-switcher.blade.php public/css/clubunio-language-switcher.css
sha256sum .env composer.lock > "$backup/config-before.sha256"
fi
rollback() {
 status=$?
 trap - EXIT
 if test "$status" != 0; then
  set +e
  tar -xf "$backup/source.tar"
  runuser -u runcloud -- "$php" -r 'require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make("Illuminate\\Contracts\\Console\\Kernel")->bootstrap();$app["blade.compiler"]->compile(resource_path("views/clubunio/language-switcher.blade.php"));'
  systemctl reload php83rc-fpm
 fi
 exit "$status"
}
trap rollback EXIT
git -c safe.directory="$root" apply "$patch"
for path in resources/views/clubunio/language-switcher.blade.php public/css/clubunio-language-switcher.css; do
 chown --reference=artisan "$path"
 chmod 644 "$path"
done
"$php" -l app/Providers/Filament/ClubUnioPanelProvider.php
sha256sum -c "$backup/config-before.sha256"
runuser -u runcloud -- env CLUBUNIO_BASE="$root" "$php" /tmp/check-language-header.php | tee "$backup/acceptance.log"
grep -q LANGUAGE_HEADER_OK "$backup/acceptance.log"
systemctl reload php83rc-fpm
curl --max-time 20 -fsS "$origin/css/clubunio-language-switcher.css?v=2" -o "$backup/https.css"
cmp --silent public/css/clubunio-language-switcher.css "$backup/https.css"
sha256sum app/Providers/Filament/ClubUnioPanelProvider.php public/css/svs.css resources/views/clubunio/language-switcher.blade.php public/css/clubunio-language-switcher.css > "$backup/deployed.sha256"
touch "$backup/complete"
trap - EXIT
printf 'Language header promoted to %s, CSS HTTPS exact match; backup: %s\n' "$1" "$backup"
