"""Root-only, private FastCGI probe; creates no public diagnostic route."""
import argparse
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import socket
import struct
import subprocess


def record(kind, data):
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data


def length_prefix(value):
    length = len(value)
    return bytes([length]) if length < 128 else struct.pack('!I', length | 0x80000000)


def read_exact(connection, length):
    value = b''
    while len(value) < length:
        part = connection.recv(length - len(value))
        if not part: raise RuntimeError('Truncated FastCGI response')
        value += part
    return value


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('environment', choices=['stage', 'prod'])
    args = parser.parse_args()
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    title = 'Stage' if args.environment == 'stage' else 'Prod'
    app = Path('/home/runcloud/webapps/ClubUnio' + title)
    pool = Path('/etc/php83rc/fpm.d/ClubUnio' + title + '.conf').read_text()
    listener = re.search(r'^listen\s*=\s*(/[^\s]+)\s*$', pool, re.M).group(1)
    path = app / 'storage/app/private' / ('security-fpm-probe-' + secrets.token_hex(8) + '.php')
    assert path.parent.is_dir() and not path.exists()
    account = pwd.getpwnam('clubunio-' + args.environment)
    source = Path(__file__).with_suffix('.php').read_text().replace('__APP_ROOT__', str(app))
    path.write_text(source)
    os.chown(path, account.pw_uid, account.pw_gid)
    os.chmod(path, 0o600)
    try:
        fields = {'SCRIPT_FILENAME': str(path), 'SCRIPT_NAME': '/__private_security_probe.php',
                  'REQUEST_URI': '/__private_security_probe.php', 'REQUEST_METHOD': 'GET',
                  'SERVER_PROTOCOL': 'HTTP/1.1', 'REMOTE_ADDR': '127.0.0.1',
                  'SECURITY_REVIEW_PROBE': '20261125', 'SECURITY_EXPECTED_UID': str(account.pw_uid), 'HTTPS': 'on', 'SERVER_NAME': title}
        parameters = b''.join(length_prefix(k.encode()) + length_prefix(v.encode()) + k.encode() + v.encode() for k, v in fields.items())
        output = b''
        with socket.socket(socket.AF_UNIX) as connection:
            connection.settimeout(30)
            connection.connect(listener)
            connection.sendall(record(1, struct.pack('!HB5s', 1, 0, b'\0' * 5)) + record(4, parameters) + record(4, b'') + record(5, b''))
            while True:
                version, kind, request, size, padding, _ = struct.unpack('!BBHHBB', read_exact(connection, 8))
                data = read_exact(connection, size)
                if padding: read_exact(connection, padding)
                if kind == 6: output += data
                if kind == 3: break
        body = output.split(b'\r\n\r\n', 1)[-1]
        result = json.loads(body)
        processes = subprocess.check_output(['ps', '-eo', 'uid=,args='], text=True)
        workers = [int(line.strip().split(None, 1)[0]) for line in processes.splitlines() if 'php-fpm: pool ClubUnio' + title in line]
        result['checks']['dedicated_uid'] = bool(workers) and all(uid == account.pw_uid for uid in workers)
        result['passed'] = result.get('passed') and result['checks']['dedicated_uid']
        print(json.dumps(result))
        if not result.get('passed'): raise SystemExit(1)
    finally:
        if path.parent == app / 'storage/app/private' and path.name.startswith('security-fpm-probe-'):
            path.unlink()


if __name__ == '__main__':
    main()
