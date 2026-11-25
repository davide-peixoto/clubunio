"""Disable root password authentication with a timed rollback safety net."""
import json
import os
from pathlib import Path
import socket
import subprocess

BASE = Path('/root/clubunio-releases/security-20261125')
TARGET = Path('/etc/ssh/sshd_config.d/00-clubunio-root-key-only.conf')


def main():
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    assert not TARGET.exists()
    rollback = BASE / 'ssh-key-only-rollback.py'
    rollback.write_text("from pathlib import Path\nimport subprocess\np=Path('/etc/ssh/sshd_config.d/00-clubunio-root-key-only.conf')\nif p.is_file() and not p.is_symlink():p.unlink()\nsubprocess.run(['/usr/sbin/sshd','-t'],check=True)\nsubprocess.run(['systemctl','reload','ssh'],check=True)\n")
    os.chmod(rollback, 0o600)
    subprocess.run(['systemd-run', '--unit=clubunio-ssh-rollback-20261125', '--on-active=2min', '/usr/bin/python3', str(rollback)], check=True, capture_output=True)
    TARGET.write_text('# Verified public-key administration; passwords remain available to non-root accounts.\nPermitRootLogin prohibit-password\n')
    os.chmod(TARGET, 0o644)
    subprocess.run(['/usr/sbin/sshd', '-t'], check=True)
    config = subprocess.check_output(['/usr/sbin/sshd', '-T'], text=True)
    assert 'permitrootlogin without-password' in config or 'permitrootlogin prohibit-password' in config
    subprocess.run(['systemctl', 'reload', 'ssh'], check=True)
    print(json.dumps({'root_password_disabled': True, 'key_login_must_be_verified': True, 'timed_rollback_armed': True}))


if __name__ == '__main__':
    main()
