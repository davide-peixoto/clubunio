"""Package only the reviewed, committed central mobile chat delta DEV delta; never working files."""
from pathlib import Path
import hashlib
import io
import json
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'storage/app/chat-delta-release'
BASELINE = '93bce9c'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-direct-contacts-v1'
# An explicit review boundary, not a wildcard over unrelated working changes.
ALLOWED = frozenset("""
app/ClubUnio/Mobile/ChatService.php
app/ClubUnio/Mobile/ChatControls.php
deploy/check-mobile-chat-delta.php
""".split())
REQUIRED_NEW = frozenset({'deploy/check-mobile-chat-delta.php'})



def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def ssh(command):
    return subprocess.check_output(['ssh', '-o', 'BatchMode=yes', 'admin@svs-pi-server', command])


def main():
    revision = git('rev-parse', 'HEAD').decode().strip()
    baseline = git('rev-parse', BASELINE + '^{commit}').decode().strip()
    git('merge-base', '--is-ancestor', baseline, revision)
    changes = git('diff', '--name-status', '--no-renames', baseline, revision).decode().splitlines()
    paths = []
    for row in changes:
        status, path = row.split('\t')
        if path not in ALLOWED:
            # Documentation, tests and tooling are deliberately not deployed.
            if path.startswith(('app/', 'routes/', 'database/', 'config/', 'bootstrap/')) or path in {'composer.json', 'composer.lock'}:
                raise RuntimeError('Unreviewed runtime delta: ' + path)
            continue
        if status not in {'A', 'M'}:
            raise RuntimeError('Unsupported runtime change: ' + row)
        paths.append(path)
    if not REQUIRED_NEW.issubset(paths):
        raise RuntimeError('Commit the complete reviewed product/gates before packaging')
    if git('status', '--porcelain', '--', *sorted(ALLOWED),
           'tools/package-chat-delta-dev.py',
           'deploy/release-clubunio-chat-delta.sh').strip():
        raise RuntimeError('Runtime working changes must be reviewed and committed first')
    if ssh('readlink -f /var/www/svs-laravel/current').decode().strip() != PREVIOUS:
        raise RuntimeError('Active baseline changed; review instead of overwriting')
    OUT.mkdir(parents=True, exist_ok=True)
    before, after, known, added = [], [], {}, []
    with tarfile.open(OUT / 'chat-delta.tar.gz', 'w:gz') as archive:
        for path in sorted(paths):
            data = git('show', revision + ':' + path)
            remote = shlex.quote(PREVIOUS + '/' + path)
            exists = ssh('if test -L ' + remote + '; then printf symlink; elif test -f ' + remote + '; then printf present; elif test -e ' + remote + '; then printf other; else printf absent; fi').decode()
            if exists == 'absent':
                if path not in REQUIRED_NEW:
                    raise RuntimeError('Unexpected absent baseline: ' + path)
                added.append(path)
            elif exists == 'present':
                if path in REQUIRED_NEW:
                    raise RuntimeError('Expected new file already deployed: ' + path)
                deployed = ssh('cat ' + remote)
                for commit in git('log', '--all', '--format=%H', '--', path).decode().splitlines():
                    candidate = git('show', commit + ':' + path)
                    if candidate.replace(b'\r\n', b'\n') == deployed.replace(b'\r\n', b'\n'):
                        known[path] = commit
                        break
                else:
                    raise RuntimeError('Unknown deployed Git source: ' + path)
                before.append(hashlib.sha256(deployed).hexdigest() + '  ' + path)
            else:
                raise RuntimeError('Unexpected baseline state')
            after.append(hashlib.sha256(data).hexdigest() + '  ' + path)
            entry = tarfile.TarInfo(path)
            entry.mode, entry.size = 0o644, len(data)
            archive.addfile(entry, io.BytesIO(data))
    for suffix, rows in [('before.manifest', before), ('manifest', after), ('new-files', added)]:
        (OUT / ('chat-delta.' + suffix)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
    (OUT / 'chat-delta.metadata.json').write_text(json.dumps({
        'revision': revision, 'baseline': baseline, 'previous': PREVIOUS,
        'verified': known, 'new': added, 'paths': sorted(paths),
    }, indent=2), encoding='utf-8')
    (OUT / 'release-clubunio-chat-delta.sh').write_bytes(
        git('show', revision + ':deploy/release-clubunio-chat-delta.sh'))
    print(f'{len(paths)} committed files, exact raw SHA/Git baseline verified; source {revision[:8]}')


if __name__ == '__main__':
    main()
