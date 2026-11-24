#!/usr/bin/env bash
set -euo pipefail
umask 077
instance=${1:?stage or prod}
case "$instance" in
 stage) suffix=Stage; origin=https://stage.clubunio.com ;;
 prod) suffix=Prod; origin=https://app.clubunio.com ;;
 *) exit 1 ;;
esac
root=/home/runcloud/webapps/ClubUnio$suffix
php=/RunCloud/Packages/php83rc/bin/php
backup=/root/clubunio-releases/veo-$instance-20261124
package=/root/clubunio-veo-promotion-20261124.tar.gz
test ! -e "$backup/complete"
if ! test -e "$backup"; then
install -d -m 700 "$backup/package"
tar -xzf "$package" -C "$backup/package"
cd "$backup/package"
sha256sum -c deploy/veo-promotion.manifest > "$backup/package-verification.log"
tar -tzf "$package" > "$backup/package-files"
while IFS= read -r path; do
 [[ "$path" != /* && "$path" != *..* ]]
 if test -f "$root/$path"; then echo "$path" >> "$backup/existing-files"; else echo "$path" >> "$backup/new-files"; fi
done < "$backup/package-files"
tar -czf "$backup/source.tar.gz" -C "$root" -T "$backup/existing-files"
cp -a "$root/bootstrap/cache" "$backup/bootstrap-cache"
sha256sum "$root/.env" "$root/composer.lock" > "$backup/config-before.sha256"
openssl rand -hex 32 > "$backup/backup.key"
runuser -u postgres -- pg_dump -Fc "clubunio_$instance" |
 openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -out "$backup/database.dump.enc"
fi
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -in "$backup/database.dump.enc" | pg_restore --file=/dev/null
runuser -u postgres -- psql -X -v ON_ERROR_STOP=1 -d "clubunio_$instance" < "/root/clubunio-veo-$instance.sql" > "$backup/migration.log"
cd "$backup/package"
find app database deploy -type f -name '*.php' -print0 | xargs -0 -r -n1 "$php" -l > "$backup/php-lint.log"
find app database deploy -type f -name '*.php' -exec sed -i 's/clubora\./clubunio./g' {} +
cd "$root"
compile_veo() {
 runuser -u runcloud -- "$php" -r 'require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make("Illuminate\\Contracts\\Console\\Kernel")->bootstrap();$app["blade.compiler"]->compile(resource_path("views/clubunio/veo-planning.blade.php"));'
}
rollback() {
 status=$?
 trap - EXIT
 if test "$status" != 0; then
  set +e
  tar -xzf "$backup/source.tar.gz" -C "$root"
  if test -f "$backup/new-files"; then while IFS= read -r path; do rm -f -- "$root/$path"; done < "$backup/new-files"; fi
  cp -a "$backup/bootstrap-cache/." bootstrap/cache/
  compile_veo
  runuser -u runcloud -- "$php" artisan up
  systemctl reload php83rc-fpm
  echo "Restored previous application; additive migrations retained: $backup" >&2
 fi
 exit "$status"
}
trap rollback EXIT
runuser -u runcloud -- "$php" artisan down --retry=30
while IFS= read -r path; do
 install -D -o runcloud -g runcloud -m 644 "$backup/package/$path" "$root/$path"
 cmp --silent "$backup/package/$path" "$root/$path"
done < "$backup/package-files"
sha256sum -c "$backup/config-before.sha256"
compile_veo
runuser -u runcloud -- "$php" deploy/check-veo-calendar.php "$instance" | tee "$backup/acceptance.log"
grep -q 'VEO_CALENDAR_ACCEPTANCE_OK' "$backup/acceptance.log"
runuser -u runcloud -- "$php" artisan up
systemctl reload php83rc-fpm
curl --max-time 25 -fsS -o /dev/null "$origin/css/clubunio-veo-planning.css?v=1"
status=$(curl --max-time 25 -sS -o /dev/null -w '%{http_code}' "$origin/veo-planning")
case "$status" in 200|302|303) ;; *) echo "Veo HTTP $status" >&2; exit 1 ;; esac
while IFS= read -r path; do sha256sum "$root/$path"; done < "$backup/package-files" > "$backup/deployed.sha256"
touch "$backup/complete"
trap - EXIT
printf 'Veo promoted to %s; HTTP %s; private backup: %s\n' "$instance" "$status" "$backup"
