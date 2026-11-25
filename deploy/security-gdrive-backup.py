"""Upload encrypted snapshots only; verify full remote bytes before recording success."""
import calendar
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time

BASE = Path('/home/dbbackups/clubunio-postgresql')
CONFIG = Path('/root/.config/rclone/clubunio-backup.conf')
STATE = Path('/root/clubunio-backups/gdrive-latest.json')
REMOTE = 'clubunio-gdrive:ClubUnio-Backups/vps.clubunio.com'


def rclone(*args):
    result = subprocess.run(['/usr/bin/rclone', '--config', str(CONFIG),
                             '--retries', '3', '--low-level-retries', '5',
                             '--contimeout', '30s', '--timeout', '5m', *args],
                            capture_output=True, timeout=1800)
    if result.returncode:
        # OAuth responses and provider diagnostics must not enter public logs.
        raise RuntimeError('Google Drive operation failed; inspect private configuration')
    return result.stdout


def snapshot():
    latest = json.loads((BASE / 'latest.json').read_text())
    folder = Path(latest['folder']).resolve()
    if folder.parent != BASE or not re.fullmatch(r'\d{8}T\d{6}Z', folder.name):
        raise ValueError('Invalid snapshot directory')
    age = time.time() - calendar.timegm(time.strptime(latest['completed_utc'], '%Y-%m-%dT%H:%M:%SZ'))
    if not 0 <= age <= 36 * 3600:
        raise ValueError('No recent complete snapshot')
    required = {db + '.dump.enc' for db in ('clubunio_dev', 'clubunio_stage', 'clubunio_prod', 'keycloak')}
    if not required.issubset(latest['files']):
        raise ValueError('Missing database backup')
    for name, info in latest['files'].items():
        if not re.fullmatch(r'[A-Za-z0-9._-]+\.enc', name):
            raise ValueError('Only encrypted files may be uploaded')
        path = folder / name
        if path.is_symlink() or path.stat().st_size != info['bytes']:
            raise ValueError('Invalid encrypted backup file')
        data = path.read_bytes()
        if not data.startswith(b'Salted__'):
            raise ValueError('Expected encrypted OpenSSL backup')
        if hashlib.sha256(data).hexdigest() != info['sha256']:
            raise ValueError('Local backup checksum mismatch')
    return latest, folder


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    with open('/run/lock/clubunio-gdrive-backup.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        latest, folder = snapshot()
        if not CONFIG.is_file() or CONFIG.is_symlink() or CONFIG.stat().st_uid != 0 or CONFIG.stat().st_mode & 0o077:
            raise ValueError('Private Google OAuth configuration required')
        destination = REMOTE + '/' + folder.name
        for name, info in latest['files'].items():
            rclone('copyto', str(folder / name), destination + '/' + name, '--immutable')
            downloaded = rclone('cat', destination + '/' + name)
            if len(downloaded) != info['bytes'] or hashlib.sha256(downloaded).hexdigest() != info['sha256']:
                raise ValueError('Remote backup checksum mismatch')
        # Manifest contains only ciphertext hashes, sizes and timestamps, no keys.
        manifest = {'completed_utc': latest['completed_utc'], 'files': latest['files']}
        with tempfile.TemporaryDirectory(prefix='clubunio-drive-') as tmp:
            path = Path(tmp) / 'manifest.json'
            path.write_text(json.dumps(manifest, indent=2) + '\n')
            rclone('copyto', str(path), destination + '/manifest.json', '--immutable')
            if json.loads(rclone('cat', destination + '/manifest.json')) != manifest:
                raise ValueError('Remote manifest mismatch')
        proof = {'verified_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                 'snapshot': folder.name, 'encrypted_files': len(latest['files']),
                 'full_remote_sha256_verified': True, 'destination': destination}
        pending = STATE.with_suffix('.new')
        pending.write_text(json.dumps(proof, indent=2) + '\n')
        pending.replace(STATE)
        print(json.dumps(proof))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'google_drive_backup_ok': False, 'error_class': type(error).__name__}))
        raise SystemExit(1)
