"""Promote only reviewed Clubhouse files; preserve each target's security release."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tarfile

ROOT = Path('/root/clubunio-releases/clubhouse-promotion-20261125')
PHP = '/RunCloud/Packages/php83rc/bin/php'
MIGRATION = '2026_11_24_030000_clubhouse'


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def run(args, data=None, user=None, cwd=None):
    if user:
        args = ['runuser', '-u', user, '--'] + args
    result = subprocess.run(args, input=data, capture_output=True, cwd=cwd)
    if result.returncode:
        with (ROOT / 'errors-private.log').open('ab') as log:
            log.write(result.stdout + result.stderr + b'\n')
        raise RuntimeError('Command failed: ' + Path(args[0]).name)
    return result.stdout


def extract(archive, destination):
    destination.mkdir(mode=0o700)
    with tarfile.open(archive) as bundle:
        files = bundle.getmembers()
        for member in files:
            name = member.name.removeprefix('./')
            if not (member.isfile() or member.isdir()) or name.startswith('/') or '..' in Path(name).parts:
                raise RuntimeError('Non-regular or unsafe package member')
            if not name.startswith(('app/', 'database/clubunio/', 'deploy/', 'lang/', 'public/', 'resources/', 'routes/')):
                raise RuntimeError('Unexpected package directory')
        bundle.extractall(destination, filter='data')
    return sorted(str(p.relative_to(destination)) for p in destination.rglob('*') if p.is_file())


def sql(database, statement):
    return run(['runuser', '-u', 'postgres', '--', 'psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1', '-d', database], statement.encode()).decode().strip()


def rights(database):
    result = {}
    for table in ['tenant_accounts', 'access_profiles', 'access_profile_permissions', 'account_access_profiles']:
        if sql(database, "SELECT to_regclass('clubunio." + table + "') IS NOT NULL") == 't':
            result[table] = sql(database, "SELECT md5(COALESCE(jsonb_agg(row ORDER BY row::text)::text,'[]')) FROM (SELECT to_jsonb(t) AS row FROM clubunio." + table + ' t) s')
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('operation', choices=['snapshot', 'apply'])
    parser.add_argument('environment', choices=['stage', 'prod'])
    parser.add_argument('archive')
    args = parser.parse_args()
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    ROOT.mkdir(mode=0o700, exist_ok=True)
    env = args.environment
    home = ROOT / env
    app = Path('/home/runcloud/webapps/ClubUnio' + env.capitalize())
    database, user = 'clubunio_' + env, 'clubunio-' + env
    origin = 'https://stage.clubunio.com' if env == 'stage' else 'https://app.clubunio.com'
    archive = Path(args.archive).resolve()
    assert archive.parent == ROOT
    if args.operation == 'snapshot':
        home.mkdir(mode=0o700)
        paths = extract(archive, home / 'source')
        baseline = {p: sha(app / p) for p in paths}
        protected = {p: sha(app / p) for p in ['.env', 'composer.json', 'composer.lock',
            'app/ClubUnio/Services/KeycloakLogoutState.php', 'config/clubunio-security.php']}
        (home / 'baseline.json').write_text(json.dumps({'files': baseline, 'protected': protected,
            'rights': rights(database)}, indent=2))
        before = home / 'before'
        before.mkdir(mode=0o700)
        for relative, digest in baseline.items():
            if digest:
                dest = before / relative
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(app / relative, dest)
        with tarfile.open(home / 'before.tar.gz', 'w:gz') as tar:
            for relative, digest in baseline.items():
                if digest:
                    tar.add(before / relative, arcname=relative, recursive=False)
        print(json.dumps({'environment': env, 'files': len(paths), 'snapshot_only': True}))
        return

    if (home / 'complete.json').exists() or (home / 'apply-started').exists():
        raise RuntimeError('Existing deployment attempt; inspect instead of repeating')
    candidate = home / 'candidate'
    paths = extract(archive, candidate)
    baseline = json.loads((home / 'baseline.json').read_text())
    if set(paths) != set(baseline['files']):
        raise RuntimeError('Candidate scope differs from reviewed snapshot')
    for relative, digest in baseline['files'].items():
        if sha(app / relative) != digest:
            raise RuntimeError('Live file changed after review: ' + relative)
    for relative, digest in baseline['protected'].items():
        if sha(app / relative) != digest:
            raise RuntimeError('Protected configuration changed: ' + relative)
    assert rights(database) == baseline['rights'], 'Account/profile assignments changed during review'
    assert not (app / 'storage/framework/down').exists(), 'Target already in maintenance'
    assert sql(database, 'SELECT current_database()') == database
    assert sql(database, "SELECT to_regclass('clubunio.clubhouse_settings') IS NULL") == 't'
    for path in candidate.rglob('*.php'):
        run([PHP, '-l', str(path)])

    # Restore the encrypted backup in isolation before any live write.
    key = home / 'backup.key'
    key.write_bytes(run(['openssl', 'rand', '-hex', '32']))
    dump = home / 'database.dump.enc'
    with dump.open('wb') as output:
        producer = subprocess.Popen(['runuser', '-u', 'postgres', '--', 'pg_dump', '-Fc', database], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        encoder = subprocess.run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-iter', '200000', '-pass', 'file:' + str(key)], stdin=producer.stdout, stdout=output, stderr=subprocess.PIPE)
        producer.stdout.close()
        producer.wait()
        if encoder.returncode or producer.returncode:
            raise RuntimeError('Encrypted database backup failed')
    restore = 'clubhouse_restore_' + env + '_20261125'
    run(['runuser', '-u', 'postgres', '--', 'createdb', '-T', 'template0', restore])
    try:
        decoder = subprocess.Popen(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-pass', 'file:' + str(key), '-in', str(dump)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        restored = subprocess.run(['runuser', '-u', 'postgres', '--', 'pg_restore', '--no-owner', '--no-privileges', '--exit-on-error', '-d', restore], stdin=decoder.stdout, capture_output=True)
        decoder.stdout.close()
        decoder.wait()
        if restored.returncode or decoder.returncode:
            (home / 'restore-private.log').write_bytes(restored.stdout + restored.stderr)
            raise RuntimeError('Backup restore failed')
        assert int(sql(restore, "SELECT count(*) FROM information_schema.tables WHERE table_schema='clubunio'")) > 100
    finally:
        run(['runuser', '-u', 'postgres', '--', 'dropdb', restore])
    (home / 'restore-ok').touch()
    print(json.dumps({'environment': env, 'encrypted_backup_restore_ok': True}), flush=True)
    (candidate / 'vendor').symlink_to(app / 'vendor', target_is_directory=True)
    statement = run([PHP, str(candidate / 'deploy/emit-clubhouse-migration.php'), database]).decode()
    assert 'clubora.' not in statement and ('current_database()<>\'' + database + "'") in statement
    shutil.copytree(app / 'bootstrap/cache', home / 'bootstrap-cache')
    (home / 'apply-started').touch()
    push_running = env == 'prod' and subprocess.run(['systemctl', 'is-active', '--quiet', 'clubunio-prod-mobile-push.service']).returncode == 0
    mutated = False
    try:
        run([PHP, str(app / 'artisan'), 'down', '--retry=30'], user=user)
        if push_running:
            run(['systemctl', 'stop', 'clubunio-prod-mobile-push.service'])
        sql(database, statement)
        (home / 'migration-applied').touch()
        mutated = True
        for relative in paths:
            target = app / relative
            missing = []
            parent = target.parent
            while not parent.exists():
                missing.append(parent)
                parent = parent.parent
            for parent in reversed(missing):
                parent.mkdir(mode=0o750)
                run(['setfacl', '-m', 'u:' + user + ':r-x,g:users-rc:---', str(parent)])
                if relative.startswith('public/'):
                    run(['setfacl', '-m', 'u:runcloud-www:r-x', str(parent)])
            shutil.copyfile(candidate / relative, target)
            os.chown(target, 0, 0)
            os.chmod(target, 0o640)
            run(['setfacl', '-m', 'u:' + user + ':r--,g:users-rc:---', str(target)])
            if relative.startswith('public/'):
                run(['setfacl', '-m', 'u:runcloud-www:r--', str(target)])
            assert sha(target) == sha(candidate / relative)
        for command in ['route:cache', 'view:cache']:
            run([PHP, str(app / 'artisan'), command], user=user)
        result = run([PHP, str(app / 'deploy/check-clubhouse.php'), env], user=user, cwd=app)
        (home / 'acceptance.log').write_bytes(result)
        assert b'17 checks' in result and b'rolled back' in result
        for relative, digest in baseline['protected'].items():
            assert sha(app / relative) == digest, 'Protected configuration was changed'
        assert rights(database) == baseline['rights'], 'Existing account/profile rights changed'
        assert sql(database, 'SELECT count(*) FROM clubunio.clubhouse_grants') == '0'
        assert sql(database, 'SELECT count(*) FROM clubunio.clubhouse_settings WHERE public_enabled') == '0'
        run([PHP, str(app / 'artisan'), 'up'], user=user)
        run(['systemctl', 'reload', 'php83rc-fpm'])
        run(['curl', '--retry', '2', '--max-time', '30', '--fail', '--silent', '-o', '/dev/null', origin + '/up'])
        http = {}
        for path, expected in [('/clubhouse', 302), ('/api/mobile/v1/clubs/1/clubhouse', 401),
                ('/public/clubhouse/sv-sissach/data', 404), ('/css/clubunio-clubhouse.css', 200),
                ('/js/clubunio-react-navigation.js?v=7', 200)]:
            status = int(run(['curl', '--max-time', '30', '--silent', '--show-error', '-o', '/dev/null', '-w', '%{http_code}', origin + path]))
            http[path] = status
            assert status == expected, 'Unexpected HTTP status for ' + path
        if push_running:
            run(['systemctl', 'start', 'clubunio-prod-mobile-push.service'])
        report = {'environment': env, 'files': len(paths), 'clubhouse_checks': 17, 'backup_restored': True,
            'configuration_preserved': True, 'existing_rights_preserved': True, 'public_enabled': False,
            'http': http,
            'hashes': {p: sha(app / p) for p in paths}}
        (home / 'complete.json').write_text(json.dumps(report, indent=2))
        print(json.dumps({k: v for k, v in report.items() if k != 'hashes'}))
    except Exception:
        if mutated:
            for relative, digest in baseline['files'].items():
                target = app / relative
                if digest:
                    shutil.copyfile(home / 'before' / relative, target)
                elif target.is_file():
                    target.unlink()
            shutil.copytree(home / 'bootstrap-cache', app / 'bootstrap/cache', dirs_exist_ok=True)
            for command in ['route:cache', 'view:cache']:
                run([PHP, str(app / 'artisan'), command], user=user)
        run([PHP, str(app / 'artisan'), 'up'], user=user)
        run(['systemctl', 'reload', 'php83rc-fpm'])
        if push_running:
            run(['systemctl', 'start', 'clubunio-prod-mobile-push.service'])
        raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok': False, 'error': str(error) if isinstance(error, (RuntimeError, AssertionError)) else type(error).__name__}))
        raise SystemExit(1)
