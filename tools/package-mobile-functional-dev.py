"""Package only the reviewed, committed functional DEV delta; never working files."""
from pathlib import Path
import hashlib
import io
import json
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'storage/app/mobile-functional-v2-release'
BASELINE = 'acfda56'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-modules-performance-v1'
# An explicit review boundary, not a wildcard over unrelated working changes.
ALLOWED = frozenset('''
app/ClubUnio/Mobile/ChatControls.php
app/ClubUnio/Mobile/ChatService.php
app/ClubUnio/Mobile/ChatPreferences.php
app/ClubUnio/Mobile/MobileAccountController.php
app/ClubUnio/Mobile/MobileAiService.php
app/ClubUnio/Mobile/MobileBusData.php
app/ClubUnio/Mobile/MobileController.php
app/ClubUnio/Mobile/MobileKeyboxService.php
app/ClubUnio/Mobile/MobileMemberDirectoryData.php
app/ClubUnio/Mobile/MobilePenaltyAccess.php
app/ClubUnio/Mobile/MobileTeamsController.php
app/ClubUnio/Mobile/MobileTeamsData.php
routes/mobile.php
database/clubunio/2026_11_04_001100_chat_experience.php
deploy/check-mobile-native-core.php
deploy/check-mobile-account-functional.php
deploy/check-mobile-team-planning-functional.php
deploy/mobile-followup-menu-probe.php
deploy/check-mobile-chat-experience.php
deploy/prepare-chat-experience-schema.php
deploy/verify-chat-experience-schema.php
'''.split())
REQUIRED_NEW = frozenset({
    'app/ClubUnio/Mobile/ChatPreferences.php',
    'database/clubunio/2026_11_04_001100_chat_experience.php',
    'deploy/check-mobile-chat-experience.php',
    'deploy/prepare-chat-experience-schema.php',
    'deploy/verify-chat-experience-schema.php',
})


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
        raise RuntimeError('Commit the complete reviewed product/schema/gates before packaging')
    if git('status', '--porcelain', '--', *sorted(ALLOWED)).strip():
        raise RuntimeError('Runtime working changes must be reviewed and committed first')
    if ssh('readlink -f /var/www/svs-laravel/current').decode().strip() != PREVIOUS:
        raise RuntimeError('Active baseline changed; review instead of overwriting')
    OUT.mkdir(parents=True, exist_ok=True)
    before, after, known, added = [], [], {}, []
    with tarfile.open(OUT / 'mobile-functional.tar.gz', 'w:gz') as archive:
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
        (OUT / ('mobile-functional.' + suffix)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
    (OUT / 'mobile-functional.metadata.json').write_text(json.dumps({
        'revision': revision, 'baseline': baseline, 'previous': PREVIOUS,
        'verified': known, 'new': added, 'paths': sorted(paths),
    }, indent=2), encoding='utf-8')
    (OUT / 'release-clubunio-mobile-functional.sh').write_bytes(
        git('show', revision + ':deploy/release-clubunio-mobile-functional.sh'))
    print(f'{len(paths)} committed files, exact raw SHA/Git baseline verified; source {revision[:8]}')


if __name__ == '__main__':
    main()
