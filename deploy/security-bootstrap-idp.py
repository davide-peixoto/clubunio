"""One-time official Keycloak temporary service admin bootstrap, root only."""
import json
import os
from pathlib import Path
import secrets
import shlex
import socket
import subprocess
import time
import urllib.request

BASE = Path('/root/clubunio-releases/security-20261125/idp-review')


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    BASE.mkdir(mode=0o700, exist_ok=True)
    descriptor = BASE / 'admin.json'
    if descriptor.exists():
        raise RuntimeError('Temporary admin descriptor exists; inspect before retrying')
    admin = {'client_id': 'clubunio-security-review-' + secrets.token_hex(8), 'client_secret': secrets.token_urlsafe(48)}
    descriptor.write_text(json.dumps(admin))
    environment = os.environ.copy()
    for line in Path('/etc/keycloak/keycloak.env').read_text().splitlines():
        line = line.strip()
        if not line or line.startswith('#'): continue
        key, separator, value = line.partition('=')
        if not separator or not key.replace('_', '').isalnum(): raise RuntimeError('Unexpected systemd environment syntax')
        parts = shlex.split(value)
        if len(parts) != 1: raise RuntimeError('Unexpected systemd environment value')
        environment[key] = parts[0]
    environment['KC_SECURITY_REVIEW_SECRET'] = admin['client_secret']
    subprocess.run(['systemctl', 'stop', 'keycloak'], check=True)
    try:
        result = subprocess.run(['runuser', '-u', 'keycloak', '--', '/opt/keycloak/bin/kc.sh', 'bootstrap-admin', 'service',
                                 '--optimized', '--client-id', admin['client_id'], '--client-secret:env=KC_SECURITY_REVIEW_SECRET', '--no-prompt'],
                                env=environment, capture_output=True)
        (BASE / 'bootstrap-private.log').write_bytes(result.stdout + result.stderr)
        if result.returncode: raise RuntimeError('Official service bootstrap failed; private diagnostics saved')
    finally:
        environment.pop('KC_SECURITY_REVIEW_SECRET', None)
        subprocess.run(['systemctl', 'start', 'keycloak'], check=True)
    for _ in range(25):
        try:
            with urllib.request.urlopen('https://auth.clubunio.com/realms/clubunio/.well-known/openid-configuration', timeout=3) as response:
                if response.status == 200:
                    print(json.dumps({'temporary_service_admin_created': True, 'keycloak_discovery': 200, 'human_admin_changed': False}))
                    return
        except Exception:
            pass
        time.sleep(1)
    raise RuntimeError('Keycloak discovery not ready; inspect service status')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok': False, 'error_type': type(error).__name__}))
        raise SystemExit(1)
