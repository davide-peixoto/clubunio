"""Package only committed planning/My Teams changes against the verified DEV base."""
import hashlib
import io
from pathlib import Path
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
BASE = 'ef14cfd'
OUT = ROOT / 'storage' / 'app' / 'mobile-planning-release'
OUT.mkdir(parents=True, exist_ok=True)

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

paths = git('diff', BASE, 'HEAD', '--name-only', '--', 'app', 'routes', 'config', 'database/clubunio').decode().splitlines()
paths += ['deploy/' + name for name in [
    'check-mobile-planning.php', 'check-mobile-team-planning-functional.php',
    'prepare-mobile-planning-schema.php', 'release-clubunio-mobile-foundation.sh',
]]
paths = sorted(set(paths))
before, after, added = [], [], []
old_paths = set(git('ls-tree', '-r', '--name-only', BASE).decode().splitlines())
with tarfile.open(OUT / 'mobile-foundation.tar.gz', 'w:gz') as archive:
    for path in paths:
        data = git('show', 'HEAD:' + path)
        entry = tarfile.TarInfo(path)
        entry.size = len(data)
        entry.mode = 0o644
        archive.addfile(entry, io.BytesIO(data))
        after.append(hashlib.sha256(data).hexdigest() + '  ' + path)
        if path in old_paths:
            old = git('show', BASE + ':' + path)
            # These two deployed files were copied with CRLF by the account-shell release.
            snapshot = {'config/clubunio-mobile.php': 'remote-mobile-config.php',
                        'routes/mobile.php': 'remote-mobile-routes.php'}.get(path)
            if snapshot:
                deployed = (OUT / snapshot).read_bytes()
                if deployed.replace(b'\r\n', b'\n') != old:
                    raise RuntimeError('Deployed baseline differs beyond line endings: ' + path)
                old = deployed
            before.append(hashlib.sha256(old).hexdigest() + '  ' + path)
        else:
            added.append(path)
for name, rows in [('before.manifest', before), ('manifest', after), ('new-files', added)]:
    (OUT / ('mobile-foundation.' + name)).write_text('\n'.join(rows) + '\n', encoding='utf-8', newline='\n')
(OUT / 'release-clubunio-mobile-foundation.sh').write_bytes(git('show', 'HEAD:deploy/release-clubunio-mobile-foundation.sh'))
print(str(OUT))
print(f'{len(paths)} committed files; {len(before)} baseline matches; {len(added)} new files')
