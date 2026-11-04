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
OUT = ROOT / 'storage/app/mobile-modules-performance-release'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-mobile-performance-v1'
PATHS = ['app/ClubUnio/Mobile/MobileAccess.php', 'app/ClubUnio/Mobile/MobileExpenseIndex.php', 'app/ClubUnio/Mobile/MobileMemberData.php', 'app/ClubUnio/Mobile/MobileMemberDocuments.php', 'app/ClubUnio/Mobile/MobileMiddleware.php', 'app/ClubUnio/Mobile/MobileNotificationService.php', 'app/ClubUnio/Mobile/MobilePlanningData.php', 'app/ClubUnio/Mobile/MobilePlanningInvitationsData.php', 'app/ClubUnio/Mobile/MobileSponsorController.php', 'app/ClubUnio/Mobile/MobileSponsorData.php', 'app/ClubUnio/Mobile/MobileTeamsData.php', 'app/ClubUnio/Mobile/MobileTrainingController.php', 'app/ClubUnio/Mobile/MobileReadContext.php', 'app/ClubUnio/Services/ExpenseService.php', 'app/ClubUnio/Services/MemberAccessService.php', 'app/ClubUnio/Services/ModulePermissionService.php', 'deploy/benchmark-mobile-reads.php']

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

def ssh(command):
    return subprocess.check_output(['ssh', '-o', 'BatchMode=yes', 'admin@svs-pi-server', command])

revision = git('rev-parse', 'HEAD').decode().strip()
if ssh('readlink -f /var/www/svs-laravel/current').decode().strip() != PREVIOUS:
    raise RuntimeError('Active baseline changed; review instead of overwriting')
OUT.mkdir(parents=True, exist_ok=True)
before, after, known, added = [], [], {}, []
with tarfile.open(OUT / 'mobile-modules-performance.tar.gz', 'w:gz') as archive:
    for path in PATHS:
        if path != 'deploy/benchmark-mobile-reads.php' and not re.fullmatch(r'app/ClubUnio/(?:Mobile|Services)/[A-Za-z]+[.]php', path):
            raise RuntimeError('Unexpected runtime path')
        data = git('show', revision + ':' + path)
        remote = shlex.quote(PREVIOUS + '/' + path)
        exists = ssh('if test -f ' + remote + '; then printf present; else printf absent; fi').decode()
        if exists == 'absent':
            if path not in ['app/ClubUnio/Mobile/MobileReadContext.php', 'deploy/benchmark-mobile-reads.php']:
                raise RuntimeError('Unexpected absent baseline: ' + path)
            added.append(path)
        elif exists == 'present':
            baseline = ssh('cat ' + remote)
            for commit in git('log', '--all', '--format=%H', '--', path).decode().splitlines():
                previous = git('show', commit + ':' + path)
                if previous.replace(b'\r\n', b'\n') == baseline.replace(b'\r\n', b'\n'):
                    known[path] = commit
                    break
            else:
                raise RuntimeError('Unknown deployed source: ' + path)
            before.append(hashlib.sha256(baseline).hexdigest() + '  ' + path)
        else:
            raise RuntimeError('Unexpected baseline state')
        after.append(hashlib.sha256(data).hexdigest() + '  ' + path)
        entry = tarfile.TarInfo(path)
        entry.mode, entry.size = 0o644, len(data)
        archive.addfile(entry, io.BytesIO(data))
for suffix, rows in [('before.manifest', before), ('manifest', after), ('new-files', added)]:
    (OUT / ('mobile-modules-performance.' + suffix)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
(OUT / 'mobile-modules-performance.metadata.json').write_text(json.dumps({
    'revision': revision, 'previous': PREVIOUS, 'verified': known, 'new': added,
}, indent=2), encoding='utf-8')
(OUT / 'release-clubunio-mobile-modules-performance.sh').write_bytes(git('show', revision + ':deploy/release-clubunio-mobile-modules-performance.sh'))
print(f'{len(PATHS)} committed files, exact Git/SHA baseline verified; source {revision[:8]}')
