"""Full isolated PostgreSQL restore from the verified Google Drive snapshot."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import time
import uuid


def run(args, data=None):
    result = subprocess.run(args, input=data, capture_output=True)
    if result.returncode:
        raise RuntimeError('Isolated remote restore failed: ' + Path(args[0]).name)
    return result.stdout


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    spec = importlib.util.spec_from_file_location('gdrive', '/usr/local/sbin/clubunio-gdrive-backup.py')
    drive = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(drive)
    proof = json.loads(drive.STATE.read_text())
    assert proof['full_remote_sha256_verified'] is True
    stamp = proof['snapshot']
    assert re.fullmatch(r'\d{8}T\d{6}Z', stamp)
    destination = drive.REMOTE + '/' + stamp
    manifest = json.loads(drive.rclone('cat', destination + '/manifest.json'))
    # Anchor remote contents against the original local ciphertext manifest.
    original = json.loads((drive.BASE / stamp / 'manifest.json').read_text())
    assert manifest['files'] == original['files']
    results = {}
    for database in ('clubunio_dev', 'clubunio_stage', 'clubunio_prod', 'keycloak'):
        name = database + '.dump.enc'
        encrypted = drive.rclone('cat', destination + '/' + name)
        info = manifest['files'][name]
        assert len(encrypted) == info['bytes'] and hashlib.sha256(encrypted).hexdigest() == info['sha256']
        plain = run(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000',
                     '-pass', 'file:/root/clubunio-backups/master.key'], encrypted)
        assert plain.startswith(b'PGDMP')
        target = 'security_drive_restore_' + uuid.uuid4().hex
        assert re.fullmatch(r'security_drive_restore_[a-f0-9]{32}', target)
        run(['runuser', '-u', 'postgres', '--', 'createdb', '--template=template0', target])
        try:
            run(['runuser', '-u', 'postgres', '--', 'pg_restore', '--no-owner', '--no-acl', '--exit-on-error', '-d', target], plain)
            count = int(run(['runuser', '-u', 'postgres', '--', 'psql', '-XAt', '-d', target, '-c',
                             "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')"]).strip())
            assert count > 0
            results[database] = {'full_restore_from_google_drive_ok': True, 'table_count': count}
        finally:
            run(['runuser', '-u', 'postgres', '--', 'dropdb', target])
    evidence = {'verified_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                'snapshot': stamp, 'results': results, 'temporary_databases_removed': True}
    Path('/root/clubunio-backups/gdrive-restore-proof.json').write_text(json.dumps(evidence, indent=2) + '\n')
    print(json.dumps(evidence))


if __name__ == '__main__':
    main()
