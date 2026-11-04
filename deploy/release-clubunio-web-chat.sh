#!/usr/bin/env bash
# Exact code-only ClubUnio release. No migrations, provider activation or PHPMaker actions.
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261104-clubunio-pitch-v1
release=$base/releases/20261104-clubunio-web-chat-v1
package=$base/shared/web-chat-release-20261104
mode=${1:-}
expected=${2:-}
[[ "$mode" =~ ^--(stage|verify|activate-after-review)$ ]]
[[ "$expected" =~ ^[a-f0-9]{64}$ ]]
test "$#" = 2
test "$(readlink -f "$base/current")" = "$previous"
test ! -L "$package" && test "$(readlink -f "$package")" = "$package"
test "$(sha256sum "$package/SHA256SUMS" | cut -d' ' -f1)" = "$expected"
cd "$package"
sha256sum -c SHA256SUMS
cmp --silent "$(readlink -f "${BASH_SOURCE[0]}")" "$package/release-clubunio-web-chat.sh"
if test "$mode" = --stage; then
    test ! -e "$release" && test ! -L "$release"
    cd "$previous"
    sha256sum -c "$package/chat.before.manifest"
    while IFS= read -r file; do test ! -e "$file" && test ! -L "$file"; done < "$package/chat.new-files"
    sudo mkdir -- "$release"
    sudo cp -a -- "$previous/." "$release/"
    cd "$release"
    python3 - "$package" "$release" <<'PY'
import hashlib, pathlib, re, sys, tarfile
package, release = map(pathlib.Path, sys.argv[1:])
expected = {}
for line in (package / 'chat.manifest').read_text().splitlines():
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|routes|lang|resources/views|public/css|public/js|deploy|bootstrap)/[A-Za-z0-9_./-]+)', line)
    assert match and '..' not in match[2] and match[2] not in expected, 'Invalid manifest'
    assert all(part and not part.startswith('.') for part in match[2].split('/'))
    expected[match[2]] = match[1]
with tarfile.open(package / 'chat.tar.gz', 'r:gz') as archive:
    members = archive.getmembers()
    assert 0 < len(members) == len(expected) <= 60 and {m.name for m in members} == set(expected)
    payload = {}
    for member in members:
        assert member.isfile() and member.mode == 0o644 and 0 <= member.size <= 2000000
        path = release / member.name
        assert path.resolve() == path and not path.is_symlink(), 'Symlink destination refused'
        data = archive.extractfile(member).read()
        assert hashlib.sha256(data).hexdigest() == expected[member.name]
        payload[path] = data
    for path, data in payload.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(0o640)
PY
    sha256sum -c "$package/chat.manifest"
    while read -r hash file; do
        sudo chgrp www-data "$file"
        directory=$(dirname "$file")
        while test "$directory" != .; do sudo chgrp www-data "$directory"; chmod g+rx "$directory"; directory=$(dirname "$directory"); done
        if [[ "$file" = *.php ]]; then php -l "$file" >/dev/null; fi
    done < "$package/chat.manifest"
    cmp --silent .env "$previous/.env"
    cmp --silent composer.json "$previous/composer.json"
    cmp --silent composer.lock "$previous/composer.lock"
    test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
    mkdir -p bootstrap/cache/web-chat-views
    sudo chgrp -R www-data bootstrap/cache
    chmod -R g+rwX bootstrap/cache
    php artisan config:clear >/dev/null
    php artisan route:clear >/dev/null
    VIEW_COMPILED_PATH="$release/bootstrap/cache/web-chat-views" php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    sudo chgrp -R www-data bootstrap/cache
    chmod -R g+rwX bootstrap/cache
    sudo -u www-data php artisan view:cache >/dev/null
    sudo -u www-data php deploy/check-web-chat-release.php --closed
    printf 'Staged inactive %s. No migration, flags or site switch.\n' "$release"
    exit 0
fi
test ! -L "$release" && test "$(readlink -f "$release")" = "$release"
cd "$release"
sha256sum -c "$package/chat.manifest"
cmp --silent .env "$previous/.env"
cmp --silent composer.json "$previous/composer.json"
cmp --silent composer.lock "$previous/composer.lock"
if test "$mode" = --verify; then
    sudo -u www-data php deploy/check-web-chat-release.php --closed
    gate() { sudo -u www-data php -r 'array_shift($argv); $argc=count($argv); try { require $argv[0]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' "deploy/$1.php" "${@:2}"; }
    for check in check-tenant-navigation check-access-profiles check-impersonation check-api check-mobile; do gate "$check"; done
    for check in check-mobile-chat-experience check-mobile-chat-composer check-mobile-direct-contacts check-mobile-chat-delta; do gate "$check" --synthetic-rollback; done
    sudo -u www-data php deploy/check-web-chat.php --synthetic-rollback
    gate check-mobile-push --closed
    sha256sum -c "$package/chat.manifest"
    test ! -L "$package/chat.verified"
    printf '%s\n%s\n' "$expected" "$(date +%s)" > "$package/chat.verified"
    printf 'Verified; explicit activation remains.\n'
    exit 0
fi
mapfile -t verified < "$package/chat.verified"
test "${#verified[@]}" = 2 && test "${verified[0]}" = "$expected"
[[ "${verified[1]}" =~ ^[0-9]+$ ]]
age=$(( $(date +%s) - verified[1] ))
test "$age" -ge 0 && test "$age" -le 600
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s; rollback code is %s. No schema/ENV/provider changes.\n' "$release" "$previous"
