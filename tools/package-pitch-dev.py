"""Prepare exact reviewed Git blobs only. SSH reads; never upload/deploy."""
import argparse
import hashlib
import io
import json
import re
import sys
from pathlib import Path
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'storage/app/pitch-release'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-chat-delta-v1'
PACKAGE = '1e265a81e258aabf93af148747e700c6a4f8e781'
# Closed list for the new current delta. No discovery of dirty/untracked files.
DELTA = '''
app/ClubUnio/Api/Modules/ApiModule.php
app/ClubUnio/Api/Modules/ApiModuleDefinitions.php
app/ClubUnio/Models/TrainingSlot.php
app/ClubUnio/Mobile/MobileTeamsData.php
app/ClubUnio/Mobile/MobilePushOutbox.php
app/ClubUnio/Pages/SeasonPlanning.php
app/ClubUnio/Resources/TrainingSlotResource.php
app/ClubUnio/Services/ContributionTrainingService.php
app/ClubUnio/Services/SeasonPlanningService.php
app/ClubUnio/Services/FacilityService.php
app/ClubUnio/Services/PitchCompetitionTeamMapping.php
app/ClubUnio/Services/PitchCompetitionEdits.php
app/ClubUnio/Services/PitchFriendlyRequestService.php
app/ClubUnio/Services/PitchWeeklyAreas.php
app/Console/Commands/PreviewPitchCompetitionTeamMapping.php
app/Console/Commands/DeliverMobilePush.php
database/clubunio/2026_11_04_001700_pitch_weekly_areas.php
database/clubunio/2026_11_04_001800_pitch_request_idempotency.php
lang/de/pitch_mapping.php
lang/en/pitch_mapping.php
lang/parts/pitch-weekly.en.json
resources/views/clubunio/season-planning.blade.php
resources/views/clubunio/signage-board.blade.php
deploy/prepare-pitch-schema.php
deploy/audit-pitch-readiness.php
deploy/check-pitch-release.php
'''.split()
PREFIXES = ('app/', 'config/', 'routes/', 'database/clubunio/', 'lang/', 'resources/views/', 'public/css/')
RUNTIME_PREFIXES = ('app/', 'config/', 'routes/', 'database/', 'lang/', 'resources/', 'public/', 'bootstrap/', 'vendor/')


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def ssh(command):
    return subprocess.check_output(['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10',
                                    'admin@svs-pi-server', command])


def digest(data):
    return hashlib.sha256(data).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--revision', required=True, help='Explicit final reviewed Git commit, never implicit HEAD')
    parser.add_argument('--inventory', action='store_true', help='Print exact path/hash review inventory, no SSH/archive')
    parser.add_argument('--reviewed-sha256', help='SHA256 of the inventory JSON printed by --inventory')
    args = parser.parse_args()
    if not re.fullmatch('[a-f0-9]{7,40}', args.revision):
        raise RuntimeError('Explicit hexadecimal commit required')
    revision = git('rev-parse', args.revision + '^{commit}').decode().strip()
    git('merge-base', '--is-ancestor', PACKAGE, revision)
    base_paths = git('diff-tree', '--no-commit-id', '--name-only', '-r', PACKAGE).decode().splitlines()
    paths = sorted(set([p for p in base_paths if p.startswith(PREFIXES)] + DELTA))
    for changed in git('diff', '--name-only', PACKAGE, revision).decode().splitlines():
        if (changed.startswith(RUNTIME_PREFIXES) or changed in {'composer.json', 'composer.lock'}) and changed not in paths:
            raise RuntimeError('Unreviewed final runtime delta; extend closed list after review: ' + changed)
    payload = {}
    for path in paths:
        entry = git('ls-tree', revision, '--', path).decode()
        if not entry.startswith('100644 blob '):
            raise RuntimeError('Missing/nonregular committed path: ' + path)
        payload[path] = git('show', revision + ':' + path)
    runner = payload['deploy/prepare-pitch-schema.php'].decode()
    for path, data in payload.items():
        if re.fullmatch(r'database/clubunio/2026_11_04_001[5678]00_[a-z_]+\.php', path):
            if ("'" + Path(path).stem + "' => '" + digest(data) + "'") not in runner:
                raise RuntimeError('Migration pin differs from exact deploy Git blob: ' + path)
    inventory = json.dumps({p: digest(b) for p, b in payload.items()}, indent=2) + '\n'
    if args.inventory:
        print(inventory, end='')
        print('Inventory SHA256: ' + digest(inventory.encode()), file=sys.stderr)
        return
    if args.reviewed_sha256 != digest(inventory.encode()):
        raise RuntimeError('Review exact Git blob inventory then pass its SHA256; no implicit HEAD package')
    if OUT.exists():
        raise RuntimeError('Output exists; retain it and explicitly review before making another package')
    if ssh('readlink -f /var/www/svs-laravel/current').decode().strip() != PREVIOUS:
        raise RuntimeError('Current changed: re-review baseline')
    previous_paths = set(git('ls-tree', '-r', '--name-only', PACKAGE + '^').decode().splitlines())
    before, added = [], []
    for path in paths:
        remote = shlex.quote(PREVIOUS + '/' + path)
        state = ssh('if test -L ' + remote + '; then printf link; elif test -f ' + remote
                    + '; then printf file; elif test -e ' + remote + '; then printf other; else printf absent; fi').decode()
        if path not in previous_paths:
            if state != 'absent':
                raise RuntimeError('New path already present on server: ' + path)
            added.append(path)
        else:
            if state != 'file':
                raise RuntimeError('Baseline missing/nonregular: ' + path)
            deployed = ssh('cat -- ' + remote)
            expected = git('show', PACKAGE + '^:' + path)
            if deployed.replace(b'\r\n', b'\n') != expected.replace(b'\r\n', b'\n'):
                raise RuntimeError('Foreign deployed update: explicitly merge/review, never overwrite: ' + path)
            before.append(digest(deployed) + '  ' + path)
    if ssh('readlink -f /var/www/svs-laravel/current').decode().strip() != PREVIOUS:
        raise RuntimeError('Current moved during packaging')
    OUT.mkdir(parents=True)
    with tarfile.open(OUT / 'pitch.tar.gz', 'w:gz', format=tarfile.USTAR_FORMAT) as archive:
        for path, data in payload.items():
            entry = tarfile.TarInfo(path)
            entry.mode, entry.size, entry.mtime = 0o644, len(data), 0
            archive.addfile(entry, io.BytesIO(data))
    for name, lines in [('before.manifest', before), ('new-files', added),
                        ('manifest', [digest(data) + '  ' + path for path, data in payload.items()])]:
        (OUT / ('pitch.' + name)).write_text('\n'.join(lines) + '\n', encoding='utf-8', newline='\n')
    (OUT / 'reviewed-inventory.json').write_text(inventory, encoding='utf-8', newline='\n')
    for name in ['release-clubunio-pitch.sh']:
        (OUT / name).write_bytes(git('show', revision + ':deploy/' + name))
    (OUT / 'source-revision').write_text(revision + '\n', encoding='ascii', newline='\n')
    checksums = [digest(p.read_bytes()) + '  ' + p.name for p in sorted(OUT.iterdir())]
    (OUT / 'SHA256SUMS').write_text('\n'.join(checksums) + '\n', encoding='utf-8', newline='\n')
    print(json.dumps({'files': len(payload), 'output': str(OUT),
                      'sha256sums': digest((OUT / 'SHA256SUMS').read_bytes()),
                      'manifest_sha256': digest((OUT / 'pitch.manifest').read_bytes())}, indent=2))


if __name__ == '__main__':
    main()
