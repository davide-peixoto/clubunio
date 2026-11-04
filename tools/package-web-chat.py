"""Exact committed webchat delta, read-only server baseline inspection; never deploy."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import shlex
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
BASE = 'e5d098b'
PREVIOUS = '/var/www/svs-laravel/releases/20261104-clubunio-pitch-v1'
OUT = ROOT / 'storage/app/web-chat-release-20261104'
FILES = '''
app/ClubUnio/Api/ApiErrors.php
app/ClubUnio/Http/WebChatController.php
app/ClubUnio/Mobile/ChatAccess.php
app/ClubUnio/Mobile/ChatAttachments.php
app/ClubUnio/Mobile/ChatControls.php
app/ClubUnio/Mobile/ChatPreferences.php
app/ClubUnio/Mobile/ChatService.php
app/ClubUnio/Mobile/MobileAiService.php
app/ClubUnio/Mobile/MobileController.php
app/ClubUnio/NavigationStructure.php
app/ClubUnio/Pages/Chats.php
app/ClubUnio/Services/PermissionCatalog.php
app/Providers/Filament/ClubUnioPanelProvider.php
bootstrap/app.php
deploy/check-mobile-chat-delta.php
deploy/check-web-chat.php
deploy/check-web-chat-release.php
lang/de.json
lang/en.json
public/css/clubunio-chat.css
public/js/clubunio-chat.js
resources/views/clubunio/chat/page.blade.php
routes/web.php
'''.split()


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def ssh(command):
    return subprocess.check_output(['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'admin@svs-pi-server', command])


def sha(data):
    return hashlib.sha256(data).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--inventory', action='store_true')
    parser.add_argument('--reviewed-sha256')
    args = parser.parse_args()
    assert re.fullmatch('[a-f0-9]{7,40}', args.revision), 'Explicit commit required'
    revision = git('rev-parse', args.revision + '^{commit}').decode().strip()
    git('merge-base', '--is-ancestor', BASE, revision)
    for path in git('diff', '--name-only', BASE, revision).decode().splitlines():
        if path.startswith(('app/', 'config/', 'routes/', 'database/', 'lang/', 'resources/', 'public/', 'bootstrap/', 'vendor/')) or path in ('composer.json', 'composer.lock'):
            assert path in FILES, 'Unreviewed runtime change: ' + path
    payload = {}
    for path in sorted(FILES):
        assert git('ls-tree', revision, '--', path).decode().startswith('100644 blob '), 'Regular committed file required: ' + path
        payload[path] = git('show', revision + ':' + path)
    inventory = (json.dumps({p: sha(data) for p, data in payload.items()}, indent=2) + '\n').encode()
    if args.inventory:
        print(inventory.decode(), end='')
        print('Inventory SHA256: ' + sha(inventory))
        return
    assert args.reviewed_sha256 == sha(inventory), 'Review exact inventory first'
    assert not OUT.exists(), 'Retain existing package; do not overwrite'
    assert ssh('readlink -f /var/www/svs-laravel/current').decode().strip() == PREVIOUS, 'Review changed active release'
    old_paths = set(git('ls-tree', '-r', '--name-only', BASE).decode().splitlines())
    before, added = [], []
    for path in payload:
        remote = shlex.quote(PREVIOUS + '/' + path)
        state = ssh('if test -L ' + remote + '; then printf link; elif test -f ' + remote + '; then printf file; elif test -e ' + remote + '; then printf other; else printf absent; fi').decode()
        if path not in old_paths:
            assert state == 'absent', 'New file exists on server: ' + path
            added.append(path)
        else:
            assert state == 'file', 'Baseline missing/nonregular: ' + path
            actual = ssh('cat -- ' + remote)
            expected = git('show', BASE + ':' + path)
            assert actual.replace(b'\r\n', b'\n') == expected.replace(b'\r\n', b'\n'), 'Foreign server delta: ' + path
            before.append(sha(actual) + '  ' + path)
    assert ssh('readlink -f /var/www/svs-laravel/current').decode().strip() == PREVIOUS
    OUT.mkdir(parents=True)
    with tarfile.open(OUT / 'chat.tar.gz', 'w:gz', format=tarfile.USTAR_FORMAT) as tar:
        for path, data in payload.items():
            entry = tarfile.TarInfo(path)
            entry.mode, entry.size, entry.mtime = 0o644, len(data), 0
            tar.addfile(entry, io.BytesIO(data))
    for name, lines in [('before.manifest', before), ('new-files', added), ('manifest', [sha(d) + '  ' + p for p, d in payload.items()])]:
        (OUT / ('chat.' + name)).write_text('\n'.join(lines) + '\n', encoding='utf-8', newline='\n')
    (OUT / 'reviewed-inventory.json').write_bytes(inventory)
    (OUT / 'release-clubunio-web-chat.sh').write_bytes(git('show', revision + ':deploy/release-clubunio-web-chat.sh'))
    (OUT / 'source-revision').write_text(revision + '\n', encoding='ascii')
    sums = '\n'.join(sha(p.read_bytes()) + '  ' + p.name for p in sorted(OUT.iterdir())) + '\n'
    (OUT / 'SHA256SUMS').write_text(sums, encoding='ascii', newline='\n')
    print(json.dumps({'files': len(payload), 'output': str(OUT), 'sha256sums': sha(sums.encode())}, indent=2))


if __name__ == '__main__':
    main()
