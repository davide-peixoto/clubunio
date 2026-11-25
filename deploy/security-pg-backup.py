"""Root-only encrypted PostgreSQL + application recovery material backup."""
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import time

BASE = Path('/home/dbbackups/clubunio-postgresql')
KEY = Path('/root/clubunio-backups/master.key')
DATABASES = ('clubunio_dev', 'clubunio_stage', 'clubunio_prod', 'keycloak')


def run(args, data=None):
    result = subprocess.run(args, input=data, capture_output=True)
    if result.returncode:
        raise RuntimeError('Backup operation failed: ' + Path(args[0]).name)
    return result.stdout


def encrypted(path, data):
    run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '200000', '-pass', 'file:' + str(KEY), '-out', str(path)], data)
    return {'bytes': path.stat().st_size, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    BASE.mkdir(parents=True, mode=0o700, exist_ok=True)
    KEY.parent.mkdir(mode=0o700, exist_ok=True)
    if not KEY.exists():
        with KEY.open('x') as stream: stream.write(os.urandom(48).hex())
        os.chmod(KEY, 0o600)
    assert KEY.stat().st_uid == 0 and KEY.stat().st_mode & 0o077 == 0 and not KEY.is_symlink()
    folder = BASE / time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())
    folder.mkdir(mode=0o700)
    manifest = {'completed_utc': None, 'files': {}, 'offsite_verified': False}
    for database in DATABASES:
        dump = run(['runuser', '-u', 'postgres', '--', 'pg_dump', '-Fc', '--lock-wait-timeout=10s', database])
        run(['pg_restore', '--file=/dev/null'], dump)
        manifest['files'][database + '.dump.enc'] = encrypted(folder / (database + '.dump.enc'), dump)
    roles = run(['runuser', '-u', 'postgres', '--', 'pg_dumpall', '--globals-only'])
    manifest['files']['postgres-globals.sql.enc'] = encrypted(folder / 'postgres-globals.sql.enc', roles)
    for title in ('Stage', 'Prod'):
        root = Path('/home/runcloud/webapps/ClubUnio' + title)
        # Includes all environment files and application recovery sources; vendor
        # is reconstructed from the lock. Private local uploads are separate below.
        paths = ['app', 'bootstrap', 'config', 'database', 'lang', 'routes', 'resources', 'public', 'composer.json', 'composer.lock']
        paths += [p.name for p in root.glob('.env*') if p.is_file() and not p.is_symlink()]
        data = run(['tar', '-czf', '-', '-C', str(root)] + paths)
        filename = 'ClubUnio' + title + '.recovery.enc'
        manifest['files'][filename] = encrypted(folder / filename, data)
        storage = run(['tar', '-czf', '-', '-C', str(root), 'storage/app'])
        filename = 'ClubUnio' + title + '.private-storage.enc'
        manifest['files'][filename] = encrypted(folder / filename, storage)
    private_paths = ['etc/keycloak', 'opt/keycloak/conf', 'opt/keycloak/providers', 'opt/keycloak/themes',
                     'home/runcloud/wallet', 'home/runcloud/google-wallet', 'home/runcloud/firebase',
                     'etc/php83rc/fpm.d/ClubUnioStage.conf', 'etc/php83rc/fpm.d/ClubUnioProd.conf',
                     'etc/cron.d/clubunio-wallet-refresh', 'etc/systemd/system/clubunio-prod-mobile-push.service',
                     'home/runcloud/webapps/ClubUnioStage/storage/framework/stage-secrets']
    data = run(['tar', '-czf', '-', '-C', '/', '--hard-dereference'] + private_paths)
    manifest['files']['identity-and-private-material.enc'] = encrypted(folder / 'identity-and-private-material.enc', data)
    manifest['completed_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    (folder / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    temporary = BASE / '.latest-new.json'
    temporary.write_text(json.dumps({'folder': str(folder), **manifest}, indent=2))
    temporary.replace(BASE / 'latest.json')
    print(json.dumps({'encrypted_backup_ok': True, 'files': len(manifest['files']), 'folder': str(folder), 'offsite_verified': False}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'encrypted_backup_ok': False, 'error_class': type(error).__name__}))
        raise SystemExit(1)
