"""Read-only hourly drift/backup checks. Never executes runtime-owned PHP as root."""
import json
import calendar
import os
from pathlib import Path
import pwd
import re
import socket
import subprocess
import time


def command(args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=20)
    if result.returncode: raise RuntimeError('Runtime check failed')
    return result.stdout


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    checks = {}
    for env, title in [('stage', 'Stage'), ('prod', 'Prod')]:
        app = Path('/home/runcloud/webapps/ClubUnio' + title)
        user = 'clubunio-' + env
        account = pwd.getpwnam(user)
        code = '$c=require $argv[1];echo json_encode([$c["database"]["connections"]["clubunio"]["username"],$c["app"]["debug"],$c["clubunio-keycloak-backchannel"]["state_enforced"]],JSON_THROW_ON_ERROR);'
        settings = json.loads(command(['runuser', '-u', user, '--', '/RunCloud/Packages/php83rc/bin/php', '-r', code, str(app / 'bootstrap/cache/config.php')]))
        checks[env + '_runtime_config'] = settings == ['clubunio_' + env + '_app', False, True]
        pool = Path('/etc/php83rc/fpm.d/ClubUnio' + title + '.conf').read_text()
        checks[env + '_pool_uid'] = bool(re.search(r'^user\s*=\s*' + re.escape(user) + r'\s*$', pool, re.M))
        checks[env + '_private_env'] = (app / '.env.postgresql.local').stat().st_uid == account.pw_uid and (app / '.env.postgresql.local').stat().st_mode & 0o077 == 0
        other = Path('/home/runcloud/webapps/ClubUnio' + ('Prod' if env == 'stage' else 'Stage')) / '.env.postgresql.local'
        checks[env + '_no_cross_read'] = subprocess.run(['runuser', '-u', user, '--', 'test', '!', '-r', str(other)]).returncode == 0
    roles = command(['runuser', '-u', 'postgres', '--', 'psql', '-XAt', '-d', 'postgres', '-c', "SELECT count(*) FROM pg_roles WHERE rolname IN ('clubunio_stage_app','clubunio_prod_app') AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolbypassrls;"])
    checks['two_least_privilege_roles'] = roles.strip() == '2'
    checks['parent_replacement_blocked'] = all(Path(p).stat().st_uid == 0 and Path(p).stat().st_mode & 0o022 == 0 for p in ['/home/runcloud', '/home/runcloud/webapps'])
    latest = json.loads(Path('/home/dbbackups/clubunio-postgresql/latest.json').read_text())
    checks['recent_encrypted_backup'] = time.time() - calendar.timegm(time.strptime(latest['completed_utc'], '%Y-%m-%dT%H:%M:%SZ')) < 36 * 3600
    checks['backup_files_present'] = all((Path(latest['folder']) / name).is_file() for name in latest['files'])
    checks['backup_timer_active'] = command(['systemctl', 'is-active', 'clubunio-postgresql-backup.timer']).strip() == 'active'
    ssh = command(['/usr/sbin/sshd', '-T'])
    checks['root_password_login_disabled'] = 'permitrootlogin without-password' in ssh or 'permitrootlogin prohibit-password' in ssh
    warnings = ['automatic_offsite_backup_requires_storage_box_key_authorization']
    if Path('/run/reboot-required').exists(): warnings.append('scheduled_vps_reboot_required')
    ok = all(checks.values())
    print(json.dumps({'critical_local_checks_ok': ok, 'checks': checks, 'warnings': warnings, 'go_live_ready': False}))
    raise SystemExit(0 if ok else 1)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'critical_local_checks_ok': False, 'error_class': type(error).__name__}))
        raise SystemExit(1)
