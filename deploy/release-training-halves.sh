#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261104-clubunio-web-chat-v1
release=$base/releases/20261105-clubunio-training-halves
package=$base/shared/training-halves-20261105
test "$#" = 1
[[ "$1" =~ ^--(stage|verify|activate)$ ]]
test "$(readlink -f "$base/current")" = "$previous"
test "$(readlink -f "$package")" = "$package"
test "$(sha256sum "$package/SHA256SUMS" | cut -d' ' -f1)" = d39997b4a8c3f7f7e61f402eec0ddbf06dc15f021c3e021744f0290b6f62df5e
cd "$package"
sha256sum -c SHA256SUMS
if test "$1" = --stage; then
    test ! -e "$release" && test ! -L "$release"
    cd "$previous"
    sha256sum -c "$package/before.manifest"
    while IFS= read -r file; do test ! -e "$file" && test ! -L "$file"; done < "$package/new-files"
    sudo mkdir "$release"
    sudo cp -a "$previous/." "$release/"
    cd "$release"
    python3 - "$package" "$release" <<'PY'
import hashlib, pathlib, re, sys, tarfile
package, release = map(pathlib.Path, sys.argv[1:])
expected = {}
for line in (package / 'manifest').read_text().splitlines():
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|resources/views|lang|deploy)/[A-Za-z0-9_./-]+)', line)
    assert match and '..' not in match[2] and match[2] not in expected
    expected[match[2]] = match[1]
with tarfile.open(package / 'delta.tar.gz', 'r:gz') as archive:
    members = archive.getmembers()
    assert len(members) == len(expected) == 10 and {m.name for m in members} == set(expected)
    payload = {}
    for entry in members:
        assert entry.isfile() and entry.mode == 0o644 and entry.size < 2000000
        target = release / entry.name
        assert target.resolve() == target and not target.is_symlink()
        data = archive.extractfile(entry).read()
        assert hashlib.sha256(data).hexdigest() == expected[entry.name]
        payload[target] = data
    for target, data in payload.items():
        target.write_bytes(data)
        target.chmod(0o640)
PY
    sha256sum -c "$package/manifest"
    while read -r hash file; do sudo chgrp www-data "$file"; if [[ "$file" = *.php ]]; then php -l "$file" >/dev/null; fi; done < "$package/manifest"
    cmp --silent .env "$previous/.env"
    cmp --silent composer.lock "$previous/composer.lock"
    test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
    test "$(readlink -f bootstrap/cache)" = "$release/bootstrap/cache"
    test -z "$(find bootstrap/cache -type l -print)"
    mkdir -p bootstrap/cache/training-half-views
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    php artisan config:clear >/dev/null
    php artisan route:clear >/dev/null
    VIEW_COMPILED_PATH="$release/bootstrap/cache/training-half-views" php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    sudo -u www-data php artisan view:cache >/dev/null
    sudo -u www-data php deploy/check-web-chat-release.php --closed
    echo 'Inactive candidate prepared; no database writes.'
    exit 0
fi
cd "$release"
sha256sum -c "$package/manifest"
cmp --silent .env "$previous/.env"
cmp --silent composer.lock "$previous/composer.lock"
if test "$1" = --verify; then
    sudo -u www-data php deploy/check-web-chat-release.php --closed
    for gate in check-tenant-navigation check-access-profiles check-impersonation; do
        sudo -u www-data php -r 'array_shift($argv); $argc=count($argv); try { require $argv[0]; } catch (Throwable $e) { fwrite(STDERR,get_class($e).PHP_EOL); exit(1); }' "deploy/$gate.php"
    done
    sudo -u www-data php deploy/correct-training-halves.php --preview
    date +%s > "$package/verified"
    exit 0
fi
verified=$(cat "$package/verified")
[[ "$verified" =~ ^[0-9]+$ ]]
age=$(( $(date +%s) - verified ))
test "$age" -ge 0 && test "$age" -lt 600
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
echo 'ClubUnio training half-pitch release activated.'
