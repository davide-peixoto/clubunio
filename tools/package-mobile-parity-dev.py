"""Build a committed, bounded delta on a verified immutable ClubUnio DEV release."""
from pathlib import Path
import hashlib
import io
import json
import re
import shlex
import subprocess
import sys
import tarfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'storage/app/mobile-parity-release'
OUT.mkdir(parents=True, exist_ok=True)

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

def ssh(command):
    return subprocess.check_output(['ssh', '-o', 'BatchMode=yes', 'admin@svs-pi-server', command])

revision = git('rev-parse', 'HEAD').decode().strip()
previous = ssh('readlink -f /var/www/svs-laravel/current').decode().strip()
if previous != '/var/www/svs-laravel/releases/20261104-clubunio-mobile-identity-push-foundation':
    raise RuntimeError('Reviewed identity-push foundation baseline required; re-review any concurrent release')
paths = set()
for commit in ['7dbdff3', '08eea27', 'db915b4', '667cb71', 'd1f7398', '48bd947', '3560c6e']:
    for path in git('diff-tree', '--no-commit-id', '--name-only', '-r', commit).decode().splitlines():
        if path.startswith(('app/', 'config/', 'routes/', 'database/clubunio/', 'resources/views/')) or path.startswith('deploy/check-') or path in ('composer.json', 'composer.lock'):
            paths.add(path)
paths.update(['deploy/check-mobile-bootstrap.php', 'deploy/check-mobile-keybox.php',
              'deploy/check-mobile-admin-notices.php', 'deploy/check-mobile-expense-provenance.php',
              'deploy/prepare-mobile-parity-schema.php', 'deploy/mobile-parity-schema.json',
              'deploy/release-clubunio-mobile-parity-foundation.sh'])
if 'routes/mobile.php' not in paths or 'config/clubunio-mobile.php' not in paths:
    raise RuntimeError('Route and feature contract must be packaged together')
reuse = sys.argv[1:] == ['--reuse-baseline']
if sys.argv[1:] and not reuse:
    raise RuntimeError('Unexpected packaging arguments')
before, after, added, verified = [], [], [], {}
if reuse:
    old = json.loads((OUT / 'mobile-parity.metadata.json').read_text(encoding='utf-8'))
    if old['previous'] != previous:
        raise RuntimeError('Recorded baseline differs from actual active DEV')
    before = (OUT / 'mobile-parity.before.manifest').read_text(encoding='utf-8').splitlines()
    added = (OUT / 'mobile-parity.new-files').read_text(encoding='utf-8').splitlines()
    existing = set()
    for line in before:
        match = re.fullmatch(r'[a-f0-9]{64}  ([a-zA-Z0-9_./-]+)', line)
        if not match:
            raise RuntimeError('Invalid captured baseline manifest')
        existing.add(match[1])
    verified = old['verified']
    if existing & set(added) or existing | set(added) != paths or set(verified) != existing:
        raise RuntimeError('Reuse requires the exact already verified file inventory')
with tarfile.open(OUT / 'mobile-parity.tar.gz', 'w:gz') as archive:
    for path in sorted(paths):
        if not re.fullmatch(r'[a-zA-Z0-9_./-]+', path) or '..' in path.split('/'):
            raise RuntimeError('Unexpected package path')
        source_revision = '3560c6e' if path.startswith(('app/', 'config/', 'database/', 'routes/', 'composer.')) else revision
        data = git('show', source_revision + ':' + path)
        entry = tarfile.TarInfo(path)
        entry.mode, entry.size = 0o644, len(data)
        archive.addfile(entry, io.BytesIO(data))
        after.append(hashlib.sha256(data).hexdigest() + '  ' + path)
        if reuse:
            continue  # Deployment rechecks every captured baseline SHA before any copy.
        remote = previous + '/' + path
        state = ssh('if test -f ' + shlex.quote(remote) + '; then printf present; else printf absent; fi').decode()
        if state == 'absent':
            added.append(path)
            continue
        if state != 'present':
            raise RuntimeError('Unknown baseline state')
        baseline = ssh('cat ' + shlex.quote(remote))
        if path == 'composer.lock':
            old_lock, new_lock = json.loads(baseline), json.loads(data)
            versions = lambda lock: {p['name']: p['version'].lstrip('v') for key in ('packages', 'packages-dev') for p in lock[key]}
            old_versions, new_versions = versions(old_lock), versions(new_lock)
            additions = {name: version for name, version in new_versions.items() if name not in old_versions}
            if additions != {'firebase/php-jwt': '7.2.1', 'google/auth': '1.55.3', 'psr/cache': '3.0.0'} or any(
                new_versions.get(name) != version for name, version in old_versions.items()
            ):
                raise RuntimeError('Dependency delta must be exactly three reviewed additions and no other version changes')
        for commit in git('log', '--all', '--format=%H', '--', path).decode().splitlines():
            try:
                known = subprocess.check_output(['git', '-C', str(ROOT), 'show', commit + ':' + path], stderr=subprocess.DEVNULL)
            except subprocess.CalledProcessError:
                continue
            if known.replace(b'\r\n', b'\n') == baseline.replace(b'\r\n', b'\n'):
                verified[path] = commit
                break
        else:
            raise RuntimeError('Unknown deployed file; preserve and review: ' + path)
        before.append(hashlib.sha256(baseline).hexdigest() + '  ' + path)
for suffix, rows in [('before.manifest', before), ('manifest', after), ('new-files', added)]:
    (OUT / ('mobile-parity.' + suffix)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
(OUT / 'mobile-parity.previous').write_text(previous + '\n', encoding='utf-8', newline='\n')
(OUT / 'mobile-parity.metadata.json').write_text(json.dumps({'revision': revision, 'product_revision': '3560c6e', 'previous': previous, 'verified': verified}, indent=2), encoding='utf-8')
(OUT / 'release-clubunio-mobile-parity-foundation.sh').write_bytes(git('show', revision + ':deploy/release-clubunio-mobile-parity-foundation.sh'))
print(f'{len(paths)} committed files; {len(before)} verified baseline files; {len(added)} new files; source {revision[:8]}')
