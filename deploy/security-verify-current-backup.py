"""Restore exactly the current encrypted backup into isolated disposable DBs."""
import json
import os
from pathlib import Path
import socket
import subprocess
import time


def run(args, data=None):
    result = subprocess.run(args, input=data, capture_output=True)
    if result.returncode: raise RuntimeError('Isolated restore operation failed')
    return result.stdout


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    base = Path('/home/dbbackups/clubunio-postgresql')
    latest = json.loads((base / 'latest.json').read_text())
    folder = Path(latest['folder']).resolve()
    assert folder.parent == base and not folder.is_symlink()
    results = {}
    for database in ('clubunio_dev', 'clubunio_stage', 'clubunio_prod', 'keycloak'):
        plain = run(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000',
                     '-pass', 'file:/root/clubunio-backups/master.key', '-in', str(folder / (database + '.dump.enc'))])
        target = 'security_post_restore_' + database + '_20261125'
        assert target in ('security_post_restore_clubunio_dev_20261125', 'security_post_restore_clubunio_stage_20261125', 'security_post_restore_clubunio_prod_20261125', 'security_post_restore_keycloak_20261125')
        exists = run(['runuser', '-u', 'postgres', '--', 'psql', '-XAt', '-d', 'postgres', '-c', "SELECT 1 FROM pg_database WHERE datname='" + target + "'"]).strip()
        assert not exists
        run(['runuser', '-u', 'postgres', '--', 'createdb', '--template=template0', target])
        try:
            run(['runuser', '-u', 'postgres', '--', 'pg_restore', '--no-owner', '--no-acl', '--exit-on-error', '-d', target], plain)
            count = int(run(['runuser', '-u', 'postgres', '--', 'psql', '-XAt', '-d', target, '-c', "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')"]).strip())
            assert count > 0
            results[database] = {'full_restore_ok': True, 'table_count': count}
        finally:
            run(['runuser', '-u', 'postgres', '--', 'dropdb', target])
    proof = {'verified_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'results': results, 'temporary_databases_removed': True}
    (folder / 'restore-proof.json').write_text(json.dumps(proof, indent=2))
    print(json.dumps(proof))


if __name__ == '__main__':
    main()
