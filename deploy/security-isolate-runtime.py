#!/usr/bin/env python3
"""Root-only, per-instance ClubUnio runtime isolation. Review before execution.

No action without --apply. Run Stage and verify before PROD. Does not change
userunio, DEV, shared site pools, nginx socket ownership, or shared clamd config.
Private rollback material is saved under /root/clubunio-releases/security-20261125/.
Requires: PHP, PostgreSQL16, runuser, setfacl. Never prints credentials.
"""
import argparse
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import socket
import subprocess
import sys
import time

PHP83 = '/RunCloud/Packages/php83rc/bin/php'


def run(argv, data=None, user=None):
    if user:
        argv = ['runuser', '-u', user, '--'] + argv
    result = subprocess.run(argv, input=data, text=True, capture_output=True)
    if result.returncode:
        # SQL/env/config failures can include credentials: never echo their output.
        raise RuntimeError('Command failed: ' + str(argv[0]))
    return result.stdout


def sql(database, statement):
    return run(['psql', '-XAt', '-v', 'ON_ERROR_STOP=1', '-d', database], statement, 'postgres')


def scram(password):
    salt = secrets.token_bytes(16)
    salted = hashlib.pbkdf2_hmac('sha256', password.encode(), salt, 4096)
    client = hmac.new(salted, b'Client Key', hashlib.sha256).digest()
    stored = hashlib.sha256(client).digest()
    server = hmac.new(salted, b'Server Key', hashlib.sha256).digest()
    enc = lambda v: base64.b64encode(v).decode()
    return f'SCRAM-SHA-256$4096:{enc(salt)}${enc(stored)}:{enc(server)}'


def replace_env(path, values):
    text = path.read_text()
    for key, value in values.items():
        # Generated credentials use only safe, unquoted dotenv characters.
        assert re.fullmatch(r'[A-Za-z0-9_-]+', value)
        pattern = rf'(?m)^{key}=.*$'
        if len(re.findall(pattern, text)) != 1:
            raise RuntimeError('Expected exactly one database setting: ' + key)
        text = re.sub(pattern, key + '=' + value, text)
    temporary = path.with_name(path.name + '.isolation-new')
    with open(temporary, 'x') as f:
        os.chmod(temporary, 0o600)
        f.write(text)
    os.replace(temporary, path)


def backup(path, target):
    dest = target / str(path).lstrip('/')
    dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    shutil.copy2(path, dest)
    os.chmod(dest, 0o600)


def rollback(env, stash):
    allowed = Path('/root/clubunio-releases/security-20261125').resolve()
    stash = Path(stash).resolve()
    if allowed not in stash.parents or stash.is_symlink():
        raise RuntimeError('Unexpected rollback directory')
    manifest = json.loads((stash / 'rollback-plan.json').read_text())
    if manifest['environment'] != env:
        raise RuntimeError('Rollback environment mismatch')
    app = Path('/home/runcloud/webapps/ClubUnio' + ('Stage' if env == 'stage' else 'Prod'))
    if manifest['app'] != str(app):
        raise RuntimeError('Rollback app mismatch')
    pool = Path('/etc/php83rc/fpm.d/' + app.name + '.conf')
    unit = Path('/etc/systemd/system/clubunio-prod-mobile-push.service')
    if env == 'prod':
        run(['systemctl', 'stop', 'clubunio-prod-mobile-push.service'])
    originals = stash / str(app).lstrip('/')
    for f in list(originals.glob('.env*')) + list((originals / 'bootstrap/cache').glob('*')):
        target = app / f.relative_to(originals)
        shutil.copy2(f, target)
    shutil.copy2(stash / str(pool).lstrip('/'), pool)
    if env == 'prod':
        shutil.copy2(stash / str(unit).lstrip('/'), unit)
        run(['systemctl', 'daemon-reload'])
    run(['setfacl', '--restore=' + str(stash / 'acl-before.txt')])
    parents = ['/home/runcloud/wallet', '/home/runcloud/google-wallet'] + (['/home/runcloud/firebase'] if env == 'prod' else [])
    for parent in parents:
        current = run(['getfacl', '-p', parent])
        if 'user:clubunio-' + env + ':' in current:
            run(['setfacl', '-x', 'u:clubunio-' + env, parent])
        # Keep root ownership and the other environment's ACL; shared runcloud
        # traversal remains sufficient for the restored wallet children.
    cron = Path('/etc/cron.d/clubunio-wallet-refresh')
    original_lines = (stash / str(cron).lstrip('/')).read_text().splitlines()
    target_lines = iter(l for l in original_lines if str(app) in l)
    lines = [next(target_lines) if str(app) in l else l for l in cron.read_text().splitlines()]
    cron.write_text('\n'.join(lines) + '\n')
    hba = Path('/etc/postgresql/16/main/pg_hba.conf')
    role = 'clubunio_' + env + '_app'
    block = (f'# ClubUnio dedicated runtime {env}\nlocal clubunio_{env} {role} scram-sha-256\n'
             f'hostssl clubunio_{env} {role} all scram-sha-256\nlocal all {role} reject\nhost all {role} all reject\n')
    hba.write_text(hba.read_text().replace(block, ''))
    sql('postgres', 'SELECT pg_reload_conf();')
    run(['/RunCloud/Packages/php83rc/sbin/php-fpm', '--fpm-config', '/etc/php83rc/php-fpm.conf', '-t'])
    run(['systemctl', 'reload', 'php83rc-fpm'])
    if env == 'prod':
        run(['systemctl', 'start', 'clubunio-prod-mobile-push.service'])
    # Keep the unused role/user for incident analysis; no new authentication.
    if sql('postgres', "SELECT count(*) FROM pg_roles WHERE rolname='" + role + "';").strip() == '1':
        sql('postgres', 'ALTER ROLE ' + role + ' NOLOGIN;')
    print(json.dumps({'rolled_back': env, 'runtime_role_disabled': True, 'verify_http_before_cleanup': True}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('environment', choices=['stage', 'prod'])
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--confirm-environment', choices=['stage', 'prod'])
    parser.add_argument('--rollback', metavar='PRIVATE_BACKUP_DIR')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise RuntimeError('Root is required')
    if socket.getfqdn().rstrip('.') != 'vps.clubunio.com' or socket.gethostname().rstrip('.') != 'vps.clubunio.com':
        raise RuntimeError('This helper is restricted to vps.clubunio.com')
    env = args.environment
    if args.apply and args.confirm_environment != env:
        raise RuntimeError('--apply requires matching --confirm-environment')
    if args.rollback:
        if not args.apply:
            raise RuntimeError('Rollback requires --apply and matching confirmation')
        rollback(env, args.rollback)
        return
    title = 'Stage' if env == 'stage' else 'Prod'
    app = Path('/home/runcloud/webapps/ClubUnio' + title)
    pool = Path('/etc/php83rc/fpm.d/ClubUnio' + title + '.conf')
    role = 'clubunio_' + env + '_app'
    osuser = 'clubunio-' + env
    database = 'clubunio_' + env
    hba = Path('/etc/postgresql/16/main/pg_hba.conf')
    cron = Path('/etc/cron.d/clubunio-wallet-refresh')
    unit = Path('/etc/systemd/system/clubunio-prod-mobile-push.service')
    wallets = [Path('/home/runcloud/wallet') / env, Path('/home/runcloud/google-wallet') / env]
    if env == 'prod':
        wallets.append(Path('/home/runcloud/firebase/prod'))
    if app.is_symlink() or not app.is_dir() or not pool.is_file():
        raise RuntimeError('Unexpected app/pool path')
    for root, dirs, files in os.walk(app):
        if any((Path(root) / n).is_symlink() for n in dirs + files):
            raise RuntimeError('Review app symlinks before isolation')
    for p in wallets:
        if not p.is_dir() or p.is_symlink():
            raise RuntimeError('Unexpected wallet path')
    if sql('postgres', "SELECT count(*) FROM pg_roles WHERE rolname='" + role + "';").strip() != '0':
        raise RuntimeError('Runtime role already exists; do not rotate it implicitly')
    try:
        pwd.getpwnam(osuser)
        raise RuntimeError('Runtime OS account already exists; review before re-running')
    except KeyError:
        pass
    config_code = ('require ' + json.dumps(str(app / 'vendor/autoload.php')) + ';'
                   '$v=Dotenv\\Dotenv::parse(file_get_contents(' + json.dumps(str(app / '.env.postgresql.local')) + '));'
                   'echo json_encode($v,JSON_THROW_ON_ERROR);')
    pg = json.loads(run([PHP83, '-r', config_code]))
    if pg.get('PGDATABASE') != database or pg.get('PGUSER') != 'userunio':
        raise RuntimeError('Unexpected original runtime database/user')
    if sql(database, "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='clubunio' AND p.prosecdef;").strip() != '0':
        raise RuntimeError('Review SECURITY DEFINER functions before granting execute')
    if not re.search(r'^user\s*=\s*runcloud\s*$', pool.read_text(), re.M):
        raise RuntimeError('Unexpected original FPM user')
    if not re.search(r'^group\s*=\s*runcloud\s*$', pool.read_text(), re.M):
        raise RuntimeError('Unexpected original FPM group')
    if len(re.findall(r'(?m)^php_admin_value\[realpath_turbo\.open_basedir\]\s*=[^\r\n]*$', pool.read_text())) != 1:
        raise RuntimeError('Expected exactly one RealpathTurbo allowlist')
    for ancestor in [Path('/home/runcloud'), Path('/home/runcloud/webapps')]:
        if ancestor.is_symlink() or not ancestor.is_dir():
            raise RuntimeError('Unexpected app ancestor')
    for binary in ['/RunCloud/Packages/php83rc/sbin/php-fpm', '/usr/bin/setfacl', PHP83]:
        if not os.path.isfile(binary):
            raise RuntimeError('Missing required executable')
    print(json.dumps({'environment': env, 'database': database, 'runtime_role': role,
                      'runtime_os_user': osuser, 'app': str(app), 'apply': args.apply}))
    if not args.apply:
        return
    stash = Path('/root/clubunio-releases/security-20261125') / ('runtime-' + env + '-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()))
    stash.mkdir(parents=True, mode=0o700)
    os.chmod(stash.parent, 0o700)
    # Include every local environment file and all config cache files, not just .env.
    files = [pool, hba, cron] + list(app.glob('.env*')) + [p for p in (app / 'bootstrap/cache').iterdir() if p.is_file()]
    for secret_dir in wallets + ([app / 'storage/framework/stage-secrets'] if env == 'stage' else []):
        if secret_dir.is_dir():
            files += [p for p in secret_dir.rglob('*') if p.is_file() and not p.is_symlink()]
    if env == 'prod':
        files.append(unit)
    for f in files:
        backup(f, stash)
    (stash / 'acl-before.txt').write_text(run(['getfacl', '-R', '-p', str(app)] + [str(p) for p in wallets]))
    os.chmod(stash / 'acl-before.txt', 0o600)
    (stash / 'wallet-parent-acl-before.txt').write_text(run(['getfacl', '-p'] + [str(p.parent) for p in wallets]))
    os.chmod(stash / 'wallet-parent-acl-before.txt', 0o600)
    ancestors = [Path('/home/runcloud'), Path('/home/runcloud/webapps')]
    (stash / 'app-ancestor-acl-before.txt').write_text(run(['getfacl', '-p'] + [str(p) for p in ancestors]))
    os.chmod(stash / 'app-ancestor-acl-before.txt', 0o600)
    (stash / 'rollback-plan.json').write_text(json.dumps({'environment': env, 'app': str(app), 'role': role,
        'os_user': osuser, 'pool': str(pool), 'wallets': [str(p) for p in wallets],
        'original_uid': 1001, 'original_gid': 1001, 'source_backup': str(stash),
        'instructions': ['Stop only target PROD push worker when applicable.',
        'Restore target .env files and bootstrap/cache from private backup, target pool and target unit.',
        'Restore target app/wallet ACLs with setfacl --restore=acl-before.txt.',
        'Keep /home/runcloud and /home/runcloud/webapps root-owned755 while either environment remains isolated; never blindly restore ancestor ownership.',
        'Remove only this dedicated user ACL from wallet parent directories; preserve the other environment.',
        'Restore only cron lines naming target app from private cron copy; preserve the other environment.',
        'Remove only the five-line target runtime HBA block; preserve the other environment.',
        'Test FPM config; reload PostgreSQL auth and shared FPM; start target push worker.',
        'Verify old runtime restored before dropping new role. DROP OWNED BY new role in target DB then DROP ROLE in postgres.',
        'Keep new OS user until all new worker processes exited; do not delete files or data.']}))
    os.chmod(stash / 'rollback-plan.json', 0o600)
    # Protect the entire ancestor chain: a shared-site process must not replace
    # an isolated app or its webapps parent with attacker-controlled directories.
    # Existing home child directories retain their owners; runcloud can still
    # use .ssh/logs/etc but cannot create/rename top-level home entries.
    for ancestor in ancestors:
        if ancestor.is_symlink() or not ancestor.is_dir():
            raise RuntimeError('Unexpected app ancestor')
        run(['setfacl', '-b', str(ancestor)])
        os.chown(ancestor, 0, 0)
        os.chmod(ancestor, 0o755)
    password = secrets.token_urlsafe(48)
    verifier = scram(password)
    statements = f'''BEGIN;
CREATE ROLE {role} LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '{verifier}';
GRANT CONNECT ON DATABASE {database} TO {role};
GRANT USAGE ON SCHEMA clubunio TO {role};
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA clubunio TO {role};
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA clubunio TO {role};
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA clubunio TO {role};
ALTER ROLE {role} IN DATABASE {database} SET search_path TO clubunio;
'''
    for owner in ['postgres', 'userunio']:
        statements += f'''ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA clubunio GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {role};
ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA clubunio GRANT USAGE, SELECT ON SEQUENCES TO {role};
ALTER DEFAULT PRIVILEGES FOR ROLE {owner} IN SCHEMA clubunio GRANT EXECUTE ON FUNCTIONS TO {role};
'''
    sql(database, statements + 'COMMIT;')
    # PUBLIC CONNECT exists on other databases. HBA binds this role to one DB
    # without revoking PUBLIC or changing shared legacy roles.
    rules = f'''# ClubUnio dedicated runtime {env}
local {database} {role} scram-sha-256
hostssl {database} {role} all scram-sha-256
local all {role} reject
host all {role} all reject
'''
    hba.write_text(rules + hba.read_text())
    if sql('postgres', 'SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL;').strip() != '0':
        raise RuntimeError('HBA rules invalid; restore backup before proceeding')
    sql('postgres', 'SELECT pg_reload_conf();')
    run(['useradd', '--system', '--user-group', '--no-create-home', '--home-dir', str(app), '--shell', '/usr/sbin/nologin', osuser])
    account = pwd.getpwnam(osuser)
    # Stop only this instance's long-running worker. Shared FPM is reloaded later.
    if env == 'prod':
        run(['systemctl', 'stop', 'clubunio-prod-mobile-push.service'])
    replace_env(app / '.env.postgresql.local', {'PGUSER': role, 'PGPASSWORD': password})
    # Root owns source. Dedicated runtime can read source but only write its cache/storage.
    run(['setfacl', '-Rb', str(app)])
    for root, dirs, files_in_dir in os.walk(app):
        p = Path(root)
        writable = p == app / 'storage' or app / 'storage' in p.parents or p == app / 'bootstrap/cache' or app / 'bootstrap/cache' in p.parents
        os.chown(p, account.pw_uid if writable else 0, account.pw_gid if writable else 0)
        os.chmod(p, 0o700 if writable else 0o750)
        for name in files_in_dir:
            f = p / name
            os.chown(f, account.pw_uid if writable else 0, account.pw_gid if writable else 0)
            os.chmod(f, 0o600 if writable else 0o640)
    run(['setfacl', '-R', '-m', 'u:' + osuser + ':r-X', str(app)])
    # Runtime ownership already grants access; avoid redundant ACL masks making
    # private cache/secret files appear group-readable.
    for runtime in [app / 'storage', app / 'bootstrap/cache']:
        run(['setfacl', '-Rb', str(runtime)])
        for root, dirs, names in os.walk(runtime):
            os.chmod(root, 0o700)
            for name in names:
                os.chmod(Path(root) / name, 0o600)
    # Environment files are private to this instance, including integration/DB credentials.
    for f in app.glob('.env*'):
        run(['setfacl', '-b', str(f)])
        os.chown(f, account.pw_uid, account.pw_gid)
        os.chmod(f, 0o600)
    run(['setfacl', '-m', 'u:runcloud-www:--x', str(app)])
    run(['setfacl', '-R', '-m', 'u:runcloud-www:r-X', str(app / 'public')])
    for wallet in wallets:
        # Shared runcloud sites must not rename/replace isolated wallet directories.
        # Keep traversal for the other environment until its own switch.
        os.chown(wallet.parent, 0, 0)
        os.chmod(wallet.parent, 0o700)
        run(['setfacl', '-m', 'u:runcloud:--x', str(wallet.parent)])
        run(['setfacl', '-m', 'u:' + osuser + ':--x', str(wallet.parent)])
        for root, dirs, names in os.walk(wallet):
            p = Path(root)
            os.chown(p, account.pw_uid, account.pw_gid)
            os.chmod(p, 0o700)
            for name in names:
                f = p / name
                if f.is_symlink():
                    raise RuntimeError('Unexpected wallet symlink')
                os.chown(f, account.pw_uid, account.pw_gid)
                os.chmod(f, 0o600)
    text = pool.read_text()
    text = re.sub(r'(?m)^user\s*=\s*runcloud\s*$', 'user = ' + osuser, text)
    text = re.sub(r'(?m)^group\s*=\s*runcloud\s*$', 'group = ' + osuser, text)
    turbo_key = 'php_admin_value[realpath_turbo.open_basedir]'
    turbo_pattern = r'(?m)^' + re.escape(turbo_key) + r'\s*=([^\r\n]*)$'
    if len(re.findall(turbo_pattern, text)) != 1:
        raise RuntimeError('Expected exactly one RealpathTurbo allowlist')
    def update_turbo(match):
        paths = [p for p in match.group(1).strip().split(':') if p]
        additions = ['/run/clamav'] + (['/home/runcloud/firebase/prod'] if env == 'prod' else [])
        for path in additions:
            if path not in paths:
                paths.append(path)
        return turbo_key + ' = ' + ':'.join(paths)
    text = re.sub(turbo_pattern, update_turbo, text)
    pool.write_text(text)
    cron.write_text('\n'.join(l.replace(' runcloud ', ' ' + osuser + ' ') if str(app) in l else l
                              for l in cron.read_text().splitlines()) + '\n')
    if env == 'prod':
        unit.write_text(unit.read_text().replace('User=runcloud', 'User=' + osuser).replace('Group=runcloud', 'Group=' + osuser))
        run(['systemctl', 'daemon-reload'])
    run(['/RunCloud/Packages/php83rc/sbin/php-fpm', '--fpm-config', '/etc/php83rc/php-fpm.conf', '-t'])
    # Verify effective traversal/read/write boundaries as the new runtime UID.
    for ancestor in [Path('/home'), Path('/home/runcloud'), Path('/home/runcloud/webapps'), app]:
        run(['/usr/bin/test', '-x', str(ancestor)], user=osuser)
    run(['/usr/bin/test', '-r', str(app / '.env.postgresql.local')], user=osuser)
    run(['/usr/bin/test', '!', '-w', str(app / 'app')], user=osuser)
    run(['/usr/bin/test', '-w', str(app / 'storage')], user=osuser)
    run(['/usr/bin/test', '-w', str(app / 'bootstrap/cache')], user=osuser)
    other_title = 'Prod' if env == 'stage' else 'Stage'
    run(['/usr/bin/test', '!', '-r', '/home/runcloud/webapps/ClubUnio' + other_title + '/.env.postgresql.local'], user=osuser)
    for secret_dir in wallets:
        run(['/usr/bin/test', '-x', str(secret_dir)], user=osuser)
    run([PHP83, str(app / 'artisan'), 'config:cache'], user=osuser)
    # Root must run deployment/owner migrations in future, not the runtime account.
    run(['systemctl', 'reload', 'php83rc-fpm'])
    if env == 'prod':
        run(['systemctl', 'start', 'clubunio-prod-mobile-push.service'])
    print(json.dumps({'complete': True, 'private_backup': str(stash), 'requires_http_and_access_boundary_verification': True}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Never echo config, passwords or upstream command output.
        print('Isolation stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
