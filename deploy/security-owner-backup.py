"""Root-only bounded backup and isolated PostgreSQL restore rehearsal on the VPS."""
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess

BASE = Path('/root/clubunio-releases/security-20261125')
DATABASES = ('clubunio_stage', 'clubunio_prod', 'keycloak')


def run(args, data=None):
    result = subprocess.run(args, input=data, capture_output=True)
    if result.returncode:
        raise RuntimeError('Private backup operation failed: ' + Path(args[0]).name)
    return result.stdout


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    BASE.mkdir(mode=0o700, parents=True, exist_ok=True)
    key = BASE / 'backup.key'
    if not key.exists():
        key.write_text(os.urandom(48).hex())
    reports = {}
    for database in DATABASES:
        cipher = BASE / (database + '.dump.enc')
        if not cipher.exists():
            dump = run(['sudo', '-u', 'postgres', 'pg_dump', '-Fc', '--lock-wait-timeout=10s', database])
            run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '200000', '-pass', 'file:' + str(key), '-out', str(cipher)], dump)
        plain = run(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-pass', 'file:' + str(key), '-in', str(cipher)])
        run(['pg_restore', '--file=/dev/null'], plain)
        scratch = 'security_restore_' + database + '_20261125'
        assert scratch in ('security_restore_clubunio_stage_20261125', 'security_restore_clubunio_prod_20261125', 'security_restore_keycloak_20261125')
        exists = run(['sudo', '-u', 'postgres', 'psql', '-X', '-At', '-d', 'postgres', '-c', "SELECT 1 FROM pg_database WHERE datname='" + scratch + "'"]).strip()
        if exists:
            raise RuntimeError('Restore target already exists; manual inspection required')
        run(['sudo', '-u', 'postgres', 'createdb', '--template=template0', scratch])
        try:
            run(['sudo', '-u', 'postgres', 'pg_restore', '--no-owner', '--no-acl', '--exit-on-error', '-d', scratch], plain)
            count = run(['sudo', '-u', 'postgres', 'psql', '-X', '-At', '-d', scratch, '-c', "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')"]).strip().decode()
            assert int(count) > 0
        finally:
            run(['sudo', '-u', 'postgres', 'dropdb', scratch])
        reports[database] = {'encrypted_sha256': hashlib.sha256(cipher.read_bytes()).hexdigest(), 'restore_ok': True, 'restored_table_count': int(count)}
    for name in ('ClubUnioStage', 'ClubUnioProd'):
        target = BASE / (name + '.source.enc')
        if not target.exists():
            archive = run(['tar', '-czf', '-', '-C', '/home/runcloud/webapps/' + name,
                           'app', 'bootstrap', 'config', 'database', 'lang', 'routes', 'resources', 'public', 'composer.json', 'composer.lock', '.env'])
            run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '200000', '-pass', 'file:' + str(key), '-out', str(target)], archive)
    (BASE / 'backup-report.json').write_text(json.dumps(reports, indent=2))
    print(json.dumps({'backup_and_restore': reports, 'source_archives': 2}))


if __name__ == '__main__':
    main()
