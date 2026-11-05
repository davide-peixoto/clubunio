#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
release=$base/releases/20261105-clubunio-dashboard-reservations-v2
package=$base/shared/dashboard-reservations-20261105
test "$#" = 3
mode=$1
previous=$2
[[ "$mode" =~ ^--(stage|activate)$ && "$3" =~ ^[a-f0-9]{64}$ ]]
[[ "$previous" = "$base/releases/"* && "$previous" != *..* ]]
test "$(readlink -f "$base/current")" = "$previous"
test "$(sha256sum "$package/manifest" | cut -d' ' -f1)" = "$3"
if test "$mode" = --stage; then
    test ! -e "$release" && test ! -L "$release"
    python3 - "$package" "$previous" <<'PY'
import hashlib, pathlib, re, sys
package, root = map(pathlib.Path, sys.argv[1:])
for line in (package / 'baseline').read_text().splitlines():
    match = re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9_./-]+)', line)
    assert match and '..' not in match[2]
    data = (root / match[2]).read_bytes().replace(b'\r\n', b'\n')
    assert hashlib.sha256(data).hexdigest() == match[1], 'Baseline changed: ' + match[2]
PY
    sudo cp -a "$previous" "$release"
    python3 - "$package" "$release" <<'PY'
import hashlib, pathlib, re, sys, tarfile
package, release = map(pathlib.Path, sys.argv[1:])
expected = {}
for line in (package / 'manifest').read_text().splitlines():
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|deploy|resources|lang)/[A-Za-z0-9_./-]+)', line)
    assert match and '..' not in match[2] and match[2] not in expected
    expected[match[2]] = match[1]
with tarfile.open(package / 'delta.tar.gz', 'r:gz') as archive:
    entries = archive.getmembers()
    assert len(entries) == len(expected) == 8 and {m.name for m in entries} == set(expected)
    payload = {}
    for entry in entries:
        assert entry.isfile() and entry.size < 2000000
        target = release / entry.name
        assert target.resolve() == target and not target.is_symlink()
        data = archive.extractfile(entry).read()
        assert hashlib.sha256(data).hexdigest() == expected[entry.name]
        payload[target] = data
    for target, data in payload.items():
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        target.chmod(0o640)
PY
    cd "$release"
    sha256sum -c "$package/manifest"
    while read -r hash file; do sudo chgrp www-data "$file"; if [[ "$file" = *.php ]]; then php -l "$file" >/dev/null; fi; done < "$package/manifest"
    cmp --silent .env "$previous/.env"
    cmp --silent composer.lock "$previous/composer.lock"
    test "$(readlink -f storage)" = "$base/shared/storage"
    test "$(readlink -f bootstrap/cache)" = "$release/bootstrap/cache"
    test -z "$(find bootstrap/cache -type l -print)"
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    mkdir -p bootstrap/cache/training-half-views
    VIEW_COMPILED_PATH="$release/bootstrap/cache/training-half-views" php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    sudo -u www-data php artisan view:cache >/dev/null
    PREVIOUS_RELEASE="$previous" php <<'PHP'
<?php
$old = getenv('PREVIOUS_RELEASE'); $new = getcwd();
$before = require $old.'/bootstrap/cache/config.php';
$after = require $new.'/bootstrap/cache/config.php';
if (str_replace($old, 'ROOT', json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== str_replace($new, 'ROOT', json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) throw new LogicException('Unexpected configuration change');
PHP
    sudo -u www-data php deploy/check-dashboard-reservation-requests.php
    echo 'Inactive candidate ready; database and private configuration unchanged.'
    exit 0
fi
cd "$release"
sha256sum -c "$package/manifest"
cmp --silent .env "$previous/.env"
sudo -u www-data php deploy/check-dashboard-reservation-requests.php
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
echo 'Dashboard reservations activated; previous release preserved.'
