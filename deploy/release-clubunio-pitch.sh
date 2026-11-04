#!/usr/bin/env bash
# Parent-run only. Stage, verify, and deliberately activate are separate invocations.
set -euo pipefail
umask 027
base=/var/www/svs-laravel
previous=$base/releases/20261104-clubunio-chat-delta-v1
release=$base/releases/20261104-clubunio-pitch-v1
package=$base/shared/pitch-release
stage=${1:-}
expected=${2:-}
script=$(readlink -f "${BASH_SOURCE[0]}")
[[ "$stage" =~ ^--(stage|verify|activate-after-review)$ ]]
[[ "$expected" =~ ^[a-f0-9]{64}$ ]]
test "$(readlink -f "$base/current")" = "$previous"
test ! -L "$package"
test "$(readlink -f "$package")" = "$package"
test "$(sha256sum "$package/SHA256SUMS" | cut -d' ' -f1)" = "$expected"
cd "$package"
sha256sum -c SHA256SUMS
cmp --silent "$script" "$package/release-clubunio-pitch.sh"
manifest=$(sha256sum pitch.manifest | cut -d' ' -f1)
if test "$stage" = --stage; then
    test "$#" = 2
    test ! -e "$release" && test ! -L "$release"
    cd "$previous"
    sha256sum -c "$package/pitch.before.manifest"
    while IFS= read -r file; do test ! -e "$file" && test ! -L "$file"; done < "$package/pitch.new-files"
    sudo mkdir -- "$release"
    sudo cp -a -- "$previous/." "$release/"
    cd "$release"
    sha256sum -c "$package/pitch.before.manifest"
    # Validate the entire archive and manifest before any overlay; no tar links/traversal/extras.
    python3 - "$package" "$release" <<'PY'
import hashlib, pathlib, re, sys, tarfile
package, release = map(pathlib.Path, sys.argv[1:])
expected = {}
for line in (package / 'pitch.manifest').read_text().splitlines():
    match = re.fullmatch(r'([a-f0-9]{64})  ((?:app|config|routes|database/clubunio|lang|resources/views|public/css|deploy)/[A-Za-z0-9_./-]+)', line)
    assert match and '..' not in match[2] and match[2] not in expected, 'Invalid manifest'
    assert all(part and not part.startswith('.') for part in match[2].split('/')), 'Hidden/empty path component refused'
    expected[match[2]] = match[1]
with tarfile.open(package / 'pitch.tar.gz', 'r:gz') as archive:
    members = archive.getmembers()
    assert 0 < len(members) == len(expected) <= 100 and {m.name for m in members} == set(expected), 'Exact bounded archive inventory required'
    payload = {}
    for member in members:
        assert member.isfile() and member.mode == 0o644 and 0 <= member.size <= 10000000, 'Regular bounded files only'
        path = release / member.name
        assert path.resolve() == path and not path.is_symlink(), 'Symlink destination refused'
        data = archive.extractfile(member).read()
        assert hashlib.sha256(data).hexdigest() == expected[member.name], 'Archive byte hash mismatch'
        payload[path] = data
    for path, data in payload.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(0o640)
PY
    sha256sum -c "$package/pitch.manifest"
    while read -r hash file; do
        sudo chgrp www-data "$file"
        directory=$(dirname "$file")
        while test "$directory" != .; do
            sudo chgrp www-data "$directory"
            chmod g+rx "$directory"
            directory=$(dirname "$directory")
        done
        if [[ "$file" = *.php ]]; then php -l "$file" >/dev/null; fi
    done < "$package/pitch.manifest"
    test ! -L .env
    cmp --silent .env "$previous/.env"
    cmp --silent composer.json "$previous/composer.json"
    cmp --silent composer.lock "$previous/composer.lock"
    test "$(readlink -f storage)" = "$(readlink -f "$previous/storage")"
    # Views get a release-local cache: staging must not rewrite the active shared views.
    mkdir -p bootstrap/cache/pitch-views
    sudo chgrp -R www-data bootstrap/cache
    chmod -R g+rwX bootstrap/cache
    php artisan config:clear >/dev/null
    php artisan route:clear >/dev/null
    VIEW_COMPILED_PATH="$release/bootstrap/cache/pitch-views" php artisan config:cache >/dev/null
    php artisan route:cache >/dev/null
    sudo chgrp -R www-data bootstrap/cache
    chmod -R g+rwX bootstrap/cache
    sudo -u www-data php artisan view:cache >/dev/null
    sudo -u www-data php deploy/check-pitch-release.php --staged
    test "$(readlink -f "$base/current")" = "$previous"
    printf 'Staged inactive %s. No schema applied, no symlink switch, no flags enabled.\n' "$release"
    exit 0
fi
test "$#" = 4
backup=$3
backup_sha=$4
[[ "$backup_sha" =~ ^[a-f0-9]{64}$ ]]
test ! -L "$release"
test "$(readlink -f "$release")" = "$release"
cd "$release"
sha256sum -c "$package/pitch.manifest"
cmp --silent .env "$previous/.env"
cmp --silent composer.json "$previous/composer.json"
cmp --silent composer.lock "$previous/composer.lock"
schema_verify() {
    sudo -u www-data php deploy/prepare-pitch-schema.php --verify-after \
        "--manifest-sha256=$manifest" "--backup-file=$backup" "--backup-sha256=$backup_sha"
}
if test "$stage" = --verify; then
    sudo -u www-data php deploy/check-pitch-release.php --closed
    gate() { sudo -u www-data php -r 'array_shift($argv); $argc=count($argv); try { require $argv[0]; } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' "deploy/$1.php" "${@:2}"; }
    for check in check-tenant-navigation check-clubunio check-access-profiles check-season-planning check-sponsor-documents check-api check-impersonation check-tenant-api-release check-administration-ui check-platform-administration check-mobile check-mobile-account-shell check-mobile-season-scope; do gate "$check"; done
    gate check-mobile-planning --enabled
    gate check-mobile-native-core --expenses-enabled --documents-enabled --facilities-enabled --bus-enabled
    for check in check-mobile-training; do gate "$check" --training-enabled; done
    for check in check-mobile-registrations check-mobile-sponsor check-mobile-sponsor-billing check-mobile-member-id-card check-mobile-penalty check-mobile-bootstrap check-mobile-expense-provenance; do gate "$check" --enabled; done
    gate check-mobile-identity --closed
    gate check-mobile-push --closed
    gate check-mobile-keybox --ready
    gate check-mobile-admin-notices --synthetic-rollback --enabled
    gate check-mobile-followup-modules --synthetic-rollback --enabled
    for check in check-mobile-account-functional check-mobile-team-planning-functional check-mobile-chat-experience check-mobile-team-member-access check-mobile-chat-composer check-mobile-language-policy check-mobile-direct-contacts check-mobile-chat-delta; do gate "$check" --synthetic-rollback; done
    schema_verify
    sha256sum -c "$package/pitch.manifest"
    test ! -L "$package/pitch.verified"
    printf '%s\n%s\n%s\n%s\n' "$expected" "$backup" "$backup_sha" "$(date +%s)" > "$package/pitch.verified"
    printf 'Technical gates passed with pitch/provider flags closed. Parent review and explicit activation remain.\n'
    exit 0
fi
# Explicit operator gate; recent verification alone never switches a release.
mapfile -t verified < "$package/pitch.verified"
test "${#verified[@]}" = 4
test "${verified[0]}" = "$expected" && test "${verified[1]}" = "$backup" && test "${verified[2]}" = "$backup_sha"
[[ "${verified[3]}" =~ ^[0-9]+$ ]]
age=$(( $(date +%s) - verified[3] ))
test "$age" -ge 0 && test "$age" -le 600
schema_verify
sudo -u www-data php deploy/check-pitch-release.php --closed
sha256sum -c "$package/pitch.manifest"
test "$(readlink -f "$base/current")" = "$previous"
test ! -e "$base/current.next" && test ! -L "$base/current.next"
sudo ln -s "$release" "$base/current.next"
sudo mv -Tf "$base/current.next" "$base/current"
sudo systemctl reload php8.3-fpm
printf 'Activated %s with native pitch/providers still closed. Rollback code: %s; retain additive schema.\n' "$release" "$previous"
