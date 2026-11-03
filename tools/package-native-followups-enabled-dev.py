"""Snapshot a verified immutable DEV baseline and package only committed core files."""
from pathlib import Path
import hashlib
import io
import json
import re
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'storage' / 'app' / 'native-followups-enabled-release'
OUT.mkdir(parents=True, exist_ok=True)

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

def ssh(command):
    return subprocess.check_output(['ssh', '-o', 'BatchMode=yes', 'admin@svs-pi-server', command])

revision = git('rev-parse', 'HEAD').decode().strip()
previous = ssh('readlink -f /var/www/svs-laravel/current').decode().strip()
if not re.fullmatch(r'/var/www/svs-laravel/releases/[a-zA-Z0-9-]+', previous):
    raise RuntimeError('Unexpected DEV release path')
paths = ['app/ClubUnio/Mobile/MobileAccountController.php', 'deploy/check-mobile-native-core.php',
 'deploy/check-mobile-facilities-bus-functional.php', 'deploy/check-mobile-expenses-functional.php',
 'deploy/release-clubunio-native-followups-enabled.sh', 'app/ClubUnio/Mobile/ChatService.php',
 'app/ClubUnio/Mobile/MobileController.php', 'app/ClubUnio/Mobile/MobileExpenseData.php', 'routes/mobile.php']
# Sponsor/Training/Registration source packages remain outside this reviewed activation.
sources = {'routes/mobile.php': 'c0b4551'}
before, after, added, verified = [], [], [], {}
with tarfile.open(OUT / 'native-followups-enabled.tar.gz', 'w:gz') as archive:
    for path in paths:
        data = git('show', sources.get(path, revision) + ':' + path)
        entry = tarfile.TarInfo(path)
        entry.mode, entry.size = 0o644, len(data)
        archive.addfile(entry, io.BytesIO(data))
        after.append(hashlib.sha256(data).hexdigest() + '  ' + path)
        remote = previous + '/' + path
        present = ssh('if test -f ' + shlex.quote(remote) + '; then printf present; else printf absent; fi').decode()
        if present == 'absent':
            added.append(path)
            continue
        if present != 'present':
            raise RuntimeError('Cannot verify baseline file state')
        baseline = OUT / ('baseline__' + path.replace('/', '__'))
        subprocess.run(['scp', 'admin@svs-pi-server:' + remote, str(baseline)], check=True)
        old = baseline.read_bytes()
        match = None
        for commit in git('log', '--all', '--format=%H', '--', path).decode().splitlines():
            try:
                known = subprocess.check_output(['git', '-C', str(ROOT), 'show', commit + ':' + path], stderr=subprocess.DEVNULL)
            except subprocess.CalledProcessError:
                continue
            if known.replace(b'\r\n', b'\n') == old.replace(b'\r\n', b'\n'):
                match = commit
                break
        if not match:
            raise RuntimeError('Uncommitted/unknown deployed baseline: ' + path)
        verified[path] = match
        before.append(hashlib.sha256(old).hexdigest() + '  ' + path)
for suffix, rows in [('before.manifest', before), ('manifest', after), ('new-files', added)]:
    (OUT / ('native-followups-enabled.' + suffix)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
(OUT / 'native-followups-enabled.previous').write_text(previous + '\n', encoding='utf-8', newline='\n')
(OUT / 'native-followups-enabled.metadata.json').write_text(json.dumps({'revision': revision, 'previous': previous, 'sources': sources, 'verified': verified}, indent=2), encoding='utf-8')
(OUT / 'release-clubunio-native-followups-enabled.sh').write_bytes(git('show', revision + ':deploy/release-clubunio-native-followups-enabled.sh'))
print(f'{len(paths)} committed files; {len(before)} verified existing files; {len(added)} new files; source {revision[:8]}')
print(previous)
