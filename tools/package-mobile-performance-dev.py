"""Bounded, committed performance delta on the reviewed active DEV release."""
from pathlib import Path
import hashlib
import io
import json
import re
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'storage/app/mobile-performance-release'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-parity-v1'
PATHS = [
    'app/ClubUnio/Mobile/ChatAttachments.php',
    'app/ClubUnio/Mobile/ChatControls.php',
    'app/ClubUnio/Mobile/ChatService.php',
    'app/ClubUnio/Mobile/MobileExpenseData.php',
    'app/ClubUnio/Mobile/MobileExpenseController.php',
    'app/ClubUnio/Services/ExpenseService.php',
    'app/ClubUnio/Mobile/MobileExpenseOcrProvenance.php',
]

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

def ssh(command):
    return subprocess.check_output(['ssh', '-o', 'BatchMode=yes', 'admin@svs-pi-server', command])

revision = git('rev-parse', 'HEAD').decode().strip()
if ssh('readlink -f /var/www/svs-laravel/current').decode().strip() != PREVIOUS:
    raise RuntimeError('Active baseline changed; review instead of overwriting')
OUT.mkdir(parents=True, exist_ok=True)
before, after, known = [], [], {}
with tarfile.open(OUT / 'mobile-performance.tar.gz', 'w:gz') as archive:
    for path in PATHS:
        if not re.fullmatch(r'app/ClubUnio/(?:Mobile|Services)/[A-Za-z]+[.]php', path):
            raise RuntimeError('Unexpected runtime path')
        data = git('show', revision + ':' + path)
        baseline = ssh('cat ' + shlex.quote(PREVIOUS + '/' + path))
        for commit in git('log', '--all', '--format=%H', '--', path).decode().splitlines():
            previous = git('show', commit + ':' + path)
            if previous.replace(b'\r\n', b'\n') == baseline.replace(b'\r\n', b'\n'):
                known[path] = commit
                break
        else:
            raise RuntimeError('Unknown deployed source: ' + path)
        before.append(hashlib.sha256(baseline).hexdigest() + '  ' + path)
        after.append(hashlib.sha256(data).hexdigest() + '  ' + path)
        entry = tarfile.TarInfo(path)
        entry.mode, entry.size = 0o644, len(data)
        archive.addfile(entry, io.BytesIO(data))
for suffix, rows in [('before.manifest', before), ('manifest', after)]:
    (OUT / ('mobile-performance.' + suffix)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
(OUT / 'mobile-performance.metadata.json').write_text(json.dumps({
    'revision': revision, 'previous': PREVIOUS, 'verified': known,
}, indent=2), encoding='utf-8')
(OUT / 'release-clubunio-mobile-performance.sh').write_bytes(git('show', revision + ':deploy/release-clubunio-mobile-performance.sh'))
print(f'{len(PATHS)} committed PHP files, exact Git/SHA baseline verified; source {revision[:8]}')
