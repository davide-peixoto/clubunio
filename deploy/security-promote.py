"""Guarded Stage/PROD source/dependency promotion; leaves target in maintenance."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import subprocess

BASE = Path('/root/clubunio-releases/security-20261125')
PHP = '/RunCloud/Packages/php83rc/bin/php'


def run(args, data=None, user=None):
    if user: args = ['runuser', '-u', user, '--'] + args
    result = subprocess.run(args, input=data, capture_output=True)
    if result.returncode:
        with open(BASE / 'promotion-errors-private.log', 'ab') as diagnostics:
            diagnostics.write(result.stdout + result.stderr + b'\n')
        raise RuntimeError('Promotion command failed: ' + Path(args[0]).name)
    return result.stdout


def env_values(path, values):
    text = path.read_text()
    for key, value in values.items():
        assert re.fullmatch(r'[A-Za-z0-9_:/.-]+', value)
        pattern = r'(?m)^' + key + r'=.*$'
        count = len(re.findall(pattern, text))
        if count > 1: raise RuntimeError('Duplicate configuration key: ' + key)
        text = re.sub(pattern, key + '=' + value, text) if count else text.rstrip() + '\n' + key + '=' + value + '\n'
    path.write_text(text)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('environment', choices=['stage', 'prod'])
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--confirm-environment', choices=['stage', 'prod'])
    args = parser.parse_args()
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    env = args.environment
    if args.apply and args.confirm_environment != env: raise RuntimeError('Explicit matching confirmation required')
    app = Path('/home/runcloud/webapps/ClubUnio' + ('Stage' if env == 'stage' else 'Prod'))
    origin = 'https://stage.clubunio.com' if env == 'stage' else 'https://app.clubunio.com'
    database = 'clubunio_' + env
    build = BASE / (env + '-build')
    backup = BASE / (env + '-application-before')
    expected = json.loads((BASE / (env + '-before-manifest.json')).read_text())
    assert json.loads((BASE / 'backup-report.json').read_text())[database]['restore_ok']
    for relative, digest in expected.items():
        target = app / relative
        if digest is None:
            if target.exists(): raise RuntimeError('Unexpected existing target: ' + relative)
        elif hashlib.sha256(target.read_bytes()).hexdigest() != digest:
            raise RuntimeError('Runtime changed after snapshot: ' + relative)
    for source in build.rglob('*.php'):
        if 'vendor' not in source.relative_to(build).parts:
            run([PHP, '-l', str(source)])
    print(json.dumps({'environment': env, 'baseline_verified': True, 'files': len(expected), 'apply': args.apply}), flush=True)
    if not args.apply: return
    if backup.exists(): raise RuntimeError('Before snapshot exists; inspect prior attempt')
    os.umask(0o077)
    backup.mkdir(mode=0o700)
    for relative in expected:
        source = app / relative
        if source.is_file():
            dest = backup / relative
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, dest)
    for source in list(app.glob('.env*')) + list((app / 'bootstrap/cache').glob('*')):
        if source.is_file():
            dest = backup / source.relative_to(app)
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, dest)
    user = pwd.getpwuid(app.stat().st_uid).pw_name
    run([PHP, str(app / 'artisan'), 'down', '--retry=30'], user=user)
    if env == 'prod': run(['systemctl', 'stop', 'clubunio-prod-mobile-push.service'])
    migration = '2026_11_25_010000_keycloak_logout_watermarks'
    statement = run([PHP, '-r', 'require $argv[1]."/vendor/autoload.php"; $m=require $argv[1]."/database/clubunio/' + migration + '.php"; echo $m->sql();', str(build)]).decode()
    assert 'CREATE TABLE clubunio.keycloak_logout_watermarks' in statement and 'clubora.' not in statement
    statement = ("BEGIN; SET LOCAL lock_timeout='5s'; SET LOCAL statement_timeout='60s'; SELECT pg_advisory_xact_lock(2026112501);\n" + statement + "\n"
        + 'CREATE TABLE clubunio.security_replay_cache (key varchar(255) PRIMARY KEY,value text NOT NULL,expiration bigint NOT NULL);\n'
        + 'CREATE INDEX security_replay_cache_expiry ON clubunio.security_replay_cache(expiration);\n'
        + 'CREATE TABLE clubunio.security_replay_locks (key varchar(255) PRIMARY KEY,owner varchar(255) NOT NULL,expiration bigint NOT NULL);\n'
        + "INSERT INTO clubunio.migrations(migration,batch) SELECT '" + migration + "',coalesce(max(batch),0)+1 FROM clubunio.migrations;\nCOMMIT;\n")
    run(['sudo', '-u', 'postgres', 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-d', database], statement.encode())
    for relative in expected:
        source = build / relative
        if not source.is_file(): raise RuntimeError('Missing reviewed source')
        target = app / relative
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
        shutil.copy2(source, target)
        os.chmod(target, 0o644)
    oldvendor = BASE / (env + '-vendor-before')
    if oldvendor.exists(): raise RuntimeError('Vendor before target exists')
    shutil.move(str(app / 'vendor'), oldvendor)
    shutil.copytree(build / 'vendor', app / 'vendor')
    for folder, _, files in os.walk(app / 'vendor'):
        os.chmod(folder, 0o755)
        for name in files: os.chmod(Path(folder) / name, 0o644)
    env_values(app / '.env', {
        'CLUBUNIO_KEYCLOAK_BACKCHANNEL_ENABLED': 'true',
        'CLUBUNIO_KEYCLOAK_LOGOUT_STATE_ENFORCED': 'true',
        'CLUBUNIO_KEYCLOAK_BACKCHANNEL_ORIGIN': origin,
        'CLUBUNIO_KEYCLOAK_BACKCHANNEL_CACHE_STORE': 'database',
        'CLUBUNIO_VEREIN_OIDC_WEB_MFA_ACR': 'clubunio-mobile-mfa',
        'CLUBUNIO_PLATFORM_OIDC_MFA_ACR': 'clubunio-mobile-mfa',
        'DB_CACHE_CONNECTION': 'clubunio', 'DB_CACHE_LOCK_CONNECTION': 'clubunio',
        'DB_CACHE_TABLE': 'clubunio.security_replay_cache',
        'DB_CACHE_LOCK_TABLE': 'clubunio.security_replay_locks',
    })
    # Compile all views only after dedicated runtime ACLs have been installed.
    for command in ['config:cache', 'route:cache']:
        run([PHP, str(app / 'artisan'), command], user=user)
    (BASE / (env + '-application-applied.json')).write_text(json.dumps({'environment': env, 'origin': origin, 'migration': migration, 'maintenance': True}))
    print(json.dumps({'application_prepared': env, 'maintenance': True, 'migration_applied': True, 'dependencies_updated': True}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok': False, 'error': str(error) if isinstance(error, (RuntimeError, AssertionError)) else type(error).__name__}))
        raise SystemExit(1)
