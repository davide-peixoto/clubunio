"""Package only the committed half-pitch delta after checking the live baseline."""
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile

ROOT = Path(__file__).resolve().parents[1]
BASE = '488d95f'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-web-chat-v1'
FILES = '''app/ClubUnio/Pages/SeasonPlanning.php
app/ClubUnio/Resources/TrainingSlotResource.php
app/ClubUnio/Services/ContributionTrainingService.php
app/ClubUnio/Services/PitchWeeklyAreas.php
app/ClubUnio/Services/SeasonPlanningService.php
resources/views/clubunio/season-planning.blade.php
lang/de.json
lang/en.json
deploy/audit-training-halves.php
deploy/correct-training-halves.php'''.splitlines()


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def ssh(command):
    return subprocess.check_output(['ssh', '-o', 'BatchMode=yes', 'admin@svs-pi-server', command])


def sha(data):
    return hashlib.sha256(data).hexdigest()


if __name__ == '__main__':
    revision = git('rev-parse', 'HEAD').decode().strip()
    git('merge-base', '--is-ancestor', BASE, revision)
    changes = git('diff', '--name-only', BASE, revision).decode().splitlines()
    assert all(p in FILES for p in changes if p.startswith(('app/', 'config/', 'routes/', 'database/', 'lang/', 'resources/', 'public/', 'bootstrap/')))
    payload = {p: git('show', revision + ':' + p) for p in FILES}
    for p in payload:
        assert git('ls-tree', revision, '--', p).decode().startswith('100644 blob ')
    inventory = json.dumps({p: sha(d) for p, d in payload.items()}, sort_keys=True).encode()
    if len(sys.argv) == 1:
        print(inventory.decode())
        print('Review hash: ' + sha(inventory))
        sys.exit(0)
    assert sys.argv[1:] == ['--reviewed', sha(inventory)]
    out = ROOT / 'storage/app/training-halves-20261105'
    assert not out.exists()
    assert ssh('readlink -f /var/www/svs-laravel/current').decode().strip() == PREVIOUS
    tracked = set(git('ls-tree', '-r', '--name-only', BASE).decode().splitlines())
    before, added = [], []
    for p in payload:
        remote = PREVIOUS + '/' + p
        if p in tracked:
            data = ssh('test ! -L ' + remote + ' && cat ' + remote)
            assert data.replace(b'\r\n', b'\n') == git('show', BASE + ':' + p).replace(b'\r\n', b'\n'), 'Foreign delta: ' + p
            before.append(sha(data) + '  ' + p)
        else:
            ssh('test ! -e ' + remote + ' && test ! -L ' + remote)
            added.append(p)
    out.mkdir(parents=True)
    with tarfile.open(out / 'delta.tar.gz', 'w:gz', format=tarfile.USTAR_FORMAT) as archive:
        for p, data in payload.items():
            entry = tarfile.TarInfo(p)
            entry.mode, entry.size, entry.mtime = 0o644, len(data), 0
            archive.addfile(entry, io.BytesIO(data))
    for name, lines in {'manifest': [sha(d) + '  ' + p for p, d in payload.items()], 'before.manifest': before, 'new-files': added}.items():
        (out / name).write_text('\n'.join(lines) + '\n', encoding='ascii', newline='\n')
    (out / 'source-revision').write_text(revision + '\n', encoding='ascii')
    sums = '\n'.join(sha(p.read_bytes()) + '  ' + p.name for p in sorted(out.iterdir())) + '\n'
    (out / 'SHA256SUMS').write_text(sums, encoding='ascii', newline='\n')
    print(json.dumps({'directory': str(out), 'files': len(payload), 'sha256sums': sha(sums.encode())}))
