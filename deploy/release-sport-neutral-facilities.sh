#!/usr/bin/env bash
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261109-clubunio-my-teams-trial
release=$base/releases/20261109-clubunio-sport-neutral-facilities
package=$base/shared/sport-neutral-facilities-20261109
backup=$base/shared/backups/sport-neutral-facilities-20261109
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
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|database|deploy|lang|resources)/[A-Za-z0-9_./-]+)', line)
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
    php -r '
    [$old, $new] = array_slice($argv, 1);
    $normalize = fn ($root) => str_replace($root, "RELEASE_ROOT", json_encode(require $root."/bootstrap/cache/config.php", JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    if ($normalize($old) !== $normalize($new)) throw new LogicException("Unexpected config difference");
    echo "Existing configuration preserved.\n";
    ' "$previous" "$release"
    sudo -u www-data php deploy/prepare-sport-neutral-facilities-release.php check
    printf '%s\n' "$3" > CLUBUNIO-REVISION
    echo 'Inactive candidate verified; no schema changes yet.'
    exit 0
fi

cd "$release"
test "$(cat CLUBUNIO-REVISION)" = "$3"
sha256sum -c "$package/manifest"
cmp --silent .env "$previous/.env"
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo -u www-data php deploy/prepare-sport-neutral-facilities-release.php check
test "$(readlink -f "$base/shared/backups")" = "$base/shared/backups"
mkdir -m 700 "$backup"
(
    umask 077
    openssl rand -hex 32 > "$backup/backup.key"
    php deploy/prepare-sport-neutral-facilities-release.php dump |
        openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -out "$backup/clubora.pg.enc"
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -in "$backup/clubora.pg.enc" |
        docker run --rm --network none --read-only -i --entrypoint pg_restore postgres:18-alpine --file=/dev/null
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$backup/backup.key" -in "$backup/clubora.pg.enc" |
        { docker run --rm --network none --read-only -i --entrypoint pg_restore postgres:18-alpine --list > "$backup/clubora.toc"; cat >/dev/null; }
    for table in tenants training_options training_slots field_availabilities facility_statuses migrations; do
        grep -Fq " TABLE DATA clubora $table " "$backup/clubora.toc"
    done
    (cd "$backup" && sha256sum clubora.pg.enc > SHA256SUMS && sha256sum -c SHA256SUMS)
)
sudo -u www-data php deploy/prepare-sport-neutral-facilities-release.php migrate

rollback() {
    if test "$(readlink -f "$base/current")" = "$release"; then
        sudo ln -s "$previous" "$base/current.facilities-rollback"
        sudo mv -Tf "$base/current.facilities-rollback" "$base/current"
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
sudo -u www-data php deploy/prepare-sport-neutral-facilities-release.php live
for check in '200 up' '200 login' '200 platform/login' '302 pitch-planning' '302 signage-preview' '401 api/mobile/v1/profile'; do
    read -r code path <<< "$check"
    test "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://dev.clubunio.ch/$path")" = "$code"
    printf '%s /%s\n' "$code" "$path"
done
trap - ERR
echo 'ClubUnio DEV facility planning active; previous code retained.'
