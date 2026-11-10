#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261107-clubunio-push-bound
release=$base/releases/20261107-clubunio-tenant-storage
package=$base/shared/tenant-storage-20261107
backup=$base/shared/backups/tenant-storage-20261107
test "$#" = 3
[[ "$1" =~ ^--(stage|activate)$ && "$2" =~ ^[a-f0-9]{64}$ && "$3" =~ ^[a-f0-9]{40}$ ]]
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
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|bootstrap|config|database|deploy|lang|resources|routes)/[A-Za-z0-9_./-]+|composer\.(?:json|lock))', line)
    assert match and '..' not in match[2] and match[2] not in expected
    expected[match[2]] = match[1]
with tarfile.open(package / 'delta.tar.gz', 'r:gz') as archive:
    files = {}
    for entry in archive.getmembers():
        target = release / entry.name
        assert target.resolve() == target
        if entry.isdir():
            assert any(name.startswith(entry.name.rstrip('/') + '/') for name in expected)
            continue
        assert entry.isfile() and entry.name not in files and entry.size < 2000000
        data = archive.extractfile(entry).read()
        assert hashlib.sha256(data).hexdigest() == expected[entry.name]
        files[entry.name] = data
    assert files.keys() == expected.keys()
    for name, data in files.items():
        target = release / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        target.chmod(0o640)
PY
    cd "$release"
    sha256sum -c "$package/manifest"
    while read -r hash file; do
        sudo chgrp www-data "$file"
        if [[ "$file" = *.php ]]; then php -l "$file" >/dev/null; fi
    done < "$package/manifest"
    cmp --silent .env "$previous/.env"
    test "$(readlink -f storage)" = "$base/shared/storage"
    test "$(readlink -f bootstrap/cache)" = "$release/bootstrap/cache"
    test -z "$(find bootstrap/cache -type l -print)"
    composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader
    composer check-platform-reqs --no-dev
    php artisan config:clear >/dev/null
    php artisan route:clear >/dev/null
    php tools/publish-livewire-assets.php
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    mkdir -p bootstrap/cache/training-half-views
    VIEW_COMPILED_PATH="$release/bootstrap/cache/training-half-views" php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    sudo chgrp -R www-data bootstrap/cache
    sudo chmod -R g+rwX bootstrap/cache
    sudo -u www-data php artisan view:cache >/dev/null
    php -r '
    [$old, $new] = array_slice($argv, 1);
    $before = require $old."/bootstrap/cache/config.php";
    $after = require $new."/bootstrap/cache/config.php";
    unset($after["clubunio-storage"]);
    $normalize = fn ($root, $config) => str_replace($root, "RELEASE_ROOT", json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    if ($normalize($old, $before) !== $normalize($new, $after)) throw new LogicException("Unexpected config difference");
    echo "Existing configuration preserved.\n";
    ' "$previous" "$release"
    sudo -u www-data php deploy/prepare-tenant-storage-release.php check
    sudo -u www-data php deploy/check-push-bound-runtime.php
    printf '%s\n' "$3" > CLUBUNIO-REVISION
    echo 'Inactive candidate verified; no schema changes yet.'
    exit 0
fi

cd "$release"
test "$(cat CLUBUNIO-REVISION)" = "$3"
sha256sum -c "$package/manifest"
cmp --silent .env "$previous/.env"
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo -u www-data php deploy/prepare-tenant-storage-release.php check
test "$(readlink -f "$base/shared/backups")" = "$base/shared/backups"
mkdir -m 700 "$backup"
(
    umask 077
    openssl rand -hex 32 > "$backup/backup.key"
    php deploy/prepare-tenant-storage-release.php dump |
        openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -out "$backup/clubora.pg.enc"
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -in "$backup/clubora.pg.enc" |
        docker run --rm --network none --read-only -i --entrypoint pg_restore postgres:18-alpine --file=/dev/null
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -in "$backup/clubora.pg.enc" |
        { docker run --rm --network none --read-only -i --entrypoint pg_restore postgres:18-alpine --list > "$backup/clubora.toc"; cat >/dev/null; }
    grep -Fq ' TABLE clubora tenants ' "$backup/clubora.toc"
    grep -Fq ' TABLE DATA clubora migrations ' "$backup/clubora.toc"
    tar -czf - -C "$previous" .env -C "$base/shared/storage/app" private |
        openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -out "$backup/private-files.tar.gz.enc"
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -in "$backup/private-files.tar.gz.enc" |
        tar -tzf - > "$backup/private-files.toc"
    grep -Fx '.env' "$backup/private-files.toc"
    grep -Fx 'private/' "$backup/private-files.toc"
    (cd "$backup" && sha256sum clubora.pg.enc private-files.tar.gz.enc > SHA256SUMS && sha256sum -c SHA256SUMS)
)
sudo -u www-data php deploy/prepare-tenant-storage-release.php migrate
sudo -u www-data php deploy/prepare-tenant-storage-release.php verify

rollback() {
    if test "$(readlink -f "$base/current")" = "$release"; then
        sudo ln -s "$previous" "$base/current.storage-rollback"
        sudo mv -Tf "$base/current.storage-rollback" "$base/current"
        sudo systemctl reload php8.3-fpm
        sudo systemctl restart clubunio-dev-mobile-push.service
    fi
}
trap rollback ERR
test "$(readlink -f "$base/current")" = "$previous"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
sudo systemctl restart clubunio-dev-mobile-push.service
sudo systemctl is-active php8.3-fpm clubunio-dev-mobile-push.service
for check in '200 up' '200 login' '200 platform/login' '302 platform' '404 club-logo/999999999/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'; do
    read -r code path <<< "$check"
    test "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://dev.clubunio.ch/$path")" = "$code"
    printf '%s /%s\n' "$code" "$path"
done
trap - ERR
echo 'ClubUnio storage release active; previous code and additive schema retained for rollback.'
