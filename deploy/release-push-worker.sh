#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261106-clubunio-push-preview
release=$base/releases/20261107-clubunio-push-worker
package=$base/shared/push-worker-20261107
test "$#" = 2
[[ "$1" =~ ^--(stage|activate)$ && "$2" =~ ^[a-f0-9]{64}$ ]]
test "$(readlink -f "$base/current")" = "$previous"
test "$(readlink -f "$package")" = "$package"
test "$(sha256sum "$package/manifest" | cut -d' ' -f1)" = "$2"
if test "$1" = --stage; then
    test ! -e "$release" && test ! -L "$release"
    (cd "$previous" && sha256sum -c "$package/baseline")
    sudo cp -a "$previous" "$release"
    python3 - "$package" "$release" <<'PY'
import hashlib, pathlib, re, sys, tarfile
package, release = map(pathlib.Path, sys.argv[1:])
expected = {}
for line in (package / 'manifest').read_text().splitlines():
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|deploy|lang)/[A-Za-z0-9_./-]+)', line)
    assert match and '..' not in match[2] and match[2] not in expected
    expected[match[2]] = match[1]
with tarfile.open(package / 'delta.tar.gz', 'r:gz') as archive:
    members = archive.getmembers()
    assert len(members) == len(expected) == 5 and {m.name for m in members} == set(expected)
    payload = {}
    for entry in members:
        assert entry.isfile() and entry.size < 2000000
        target = release / entry.name
        assert target.resolve() == target and not target.is_symlink()
        data = archive.extractfile(entry).read()
        assert hashlib.sha256(data).hexdigest() == expected[entry.name]
        payload[target] = data
    for target, data in payload.items():
        target.write_bytes(data)
        target.chmod(0o640)
PY
    cd "$release"
    sha256sum -c "$package/manifest"
    while read -r hash file; do sudo chgrp www-data "$file"; php -l "$file" >/dev/null; done < "$package/manifest"
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
    php <<'PHP'
<?php
$old = '/var/www/svs-laravel/releases/20261106-clubunio-push-preview';
$new = getcwd();
$before = require $old.'/bootstrap/cache/config.php';
$after = require $new.'/bootstrap/cache/config.php';
if (str_replace($old, 'RELEASE_ROOT', json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== str_replace($new, 'RELEASE_ROOT', json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) throw new LogicException('Unexpected configuration change');
echo "Configuration including private Firebase registry preserved.\n";
PHP
    sudo -u www-data php deploy/check-push-worker-runtime.php
    echo 'Inactive candidate ready. No database changes.'
    sudo systemd-analyze verify "$release/deploy/systemd/clubunio-dev-mobile-push.service"
    exit 0
fi
cd "$release"
sha256sum -c "$package/manifest"
cmp --silent .env "$previous/.env"
sudo -u www-data php deploy/check-push-worker-runtime.php
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
sudo install -o root -g root -m 644 "$release/deploy/systemd/clubunio-dev-mobile-push.service" /etc/systemd/system/clubunio-dev-mobile-push.service
sudo systemd-analyze verify /etc/systemd/system/clubunio-dev-mobile-push.service
sudo systemctl daemon-reload
sudo systemctl disable --now clubunio-dev-mobile-push.timer
sudo systemctl enable clubunio-dev-mobile-push.service
sudo systemctl restart clubunio-dev-mobile-push.service
sudo systemctl is-active clubunio-dev-mobile-push.service
echo 'Resident DEV push worker activated; previous release and timer unit preserved.'
