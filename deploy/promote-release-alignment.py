"""Code-only Stage/PROD alignment with guarded scope, backup and automatic rollback."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tarfile

PHP = '/RunCloud/Packages/php83rc/bin/php'
ROOT = Path('/root/clubunio-releases/alignment-20261126')


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def run(args, user=None, cwd=None):
    if user:
        args = ['runuser', '-u', user, '--'] + args
    result = subprocess.run(args, cwd=cwd, capture_output=True)
    if result.returncode:
        with (ROOT / 'errors-private.log').open('ab') as log:
            log.write(result.stdout + result.stderr + b'\n')
        raise RuntimeError('Release command failed: ' + Path(args[0]).name)
    return result.stdout


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('environment', choices=['stage', 'prod'])
    args = parser.parse_args()
    assert os.geteuid() == 0 and socket.getfqdn() == 'vps.clubunio.com'
    os.umask(0o077)
    env = args.environment
    user = 'clubunio-' + env
    app = Path('/home/runcloud/webapps/ClubUnio' + env.capitalize())
    origin = 'https://stage.clubunio.com' if env == 'stage' else 'https://app.clubunio.com'
    ROOT.mkdir(mode=0o700, exist_ok=True)
    home = ROOT / env
    home.mkdir(mode=0o700)
    candidate = home / 'candidate'
    candidate.mkdir(mode=0o700)
    with tarfile.open(ROOT / 'release-alignment-20261126.tar.gz') as bundle:
        for member in bundle.getmembers():
            assert member.isfile() and not member.name.startswith('/') and '..' not in Path(member.name).parts
            assert member.name.startswith(('app/', 'lang/', 'public/', 'resources/', 'deploy/check-release-alignment.php'))
        bundle.extractall(candidate, filter='data')
    manifest = json.loads((ROOT / 'release-alignment-20261126.json').read_text())
    paths = sorted(manifest)
    assert all(sha(candidate / p) == digest for p, digest in manifest.items())
    # Compare the target against the read-only inventory captured before review.
    baseline = json.loads(Path('/tmp/source-' + env + '-20261126.json').read_text())
    for p in paths:
        if p.startswith('deploy/'):
            continue
        value = (app / p).read_bytes().replace(b'\r\n', b'\n') if (app / p).is_file() else None
        if value is not None and p.endswith('.php'):
            value = value.replace(b'clubora.', b'clubunio.')
        assert (hashlib.sha256(value).hexdigest() if value is not None else None) == baseline.get(p), 'Concurrent source change: ' + p
    protected = {p: sha(app / p) for p in ['.env', '.env.postgresql.local', 'composer.lock', 'config/auth.php',
        'app/ClubUnio/Services/AccountSecurity.php', 'app/ClubUnio/Http/PlatformOidcController.php',
        'app/Providers/Filament/ClubUnioPanelProvider.php', 'app/ClubUnio/Services/AzureDocumentReader.php']}
    before = home / 'before'
    before.mkdir(mode=0o700)
    originals = {p: sha(app / p) for p in paths}
    acls = {}
    for p, digest in originals.items():
        if digest:
            dest = before / p
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(app / p, dest)
            acls[p] = run(['getfacl', '-p', str(app / p)]).decode()
    (home / 'baseline.json').write_text(json.dumps({'files': originals, 'protected': protected, 'acls': acls}, indent=2))
    assert not (app / 'storage/framework/down').exists(), 'Target already in maintenance'
    for p in candidate.rglob('*.php'):
        run([PHP, '-l', str(p)])
    applied = False
    try:
        run([PHP, str(app / 'artisan'), 'down', '--retry=30'], user=user, cwd=app)
        applied = True
        for p in paths:
            dest = app / p
            missing = []
            parent = dest.parent
            while not parent.exists():
                missing.append(parent)
                parent = parent.parent
            for parent in reversed(missing):
                parent.mkdir(mode=0o750)
                run(['setfacl', '-m', 'u:' + user + ':r-x,g:users-rc:---', str(parent)])
                if p.startswith('public/'):
                    run(['setfacl', '-m', 'u:runcloud-www:r-x', str(parent)])
            shutil.copyfile(candidate / p, dest)
            os.chown(dest, 0, 0)
            os.chmod(dest, 0o640)
            run(['setfacl', '-m', 'u:' + user + ':r--,g:users-rc:---', str(dest)])
            if p.startswith('public/'):
                run(['setfacl', '-m', 'u:runcloud-www:r--', str(dest)])
        run([PHP, str(app / 'artisan'), 'view:cache'], user=user, cwd=app)
        evidence = json.loads(run([PHP, str(app / 'deploy/check-release-alignment.php'), env], user=user, cwd=app))
        assert all(sha(app / p) == digest for p, digest in manifest.items())
        assert all(sha(app / p) == digest for p, digest in protected.items())
        run([PHP, str(app / 'artisan'), 'up'], user=user, cwd=app)
        run(['systemctl', 'reload', 'php83rc-fpm'])
        http = {}
        for path, expected in [('/up', 200), ('/login', 302), ('/css/clubunio-assistant.css', 200),
                               ('/js/react-ui/manifest.json', 200)]:
            status = int(run(['curl', '--retry', '2', '--max-time', '30', '--silent', '--show-error', '-o', '/dev/null', '-w', '%{http_code}', origin + path]))
            http[path] = status
            assert status == expected, 'Unexpected HTTP status for ' + path
        report = evidence | {'files': len(paths), 'protected_unchanged': True, 'http': http, 'hashes': manifest}
        (home / 'complete.json').write_text(json.dumps(report, indent=2))
        print(json.dumps({k: v for k, v in report.items() if k != 'hashes'}))
    except Exception:
        if applied:
            for p, digest in originals.items():
                dest = app / p
                if digest:
                    shutil.copyfile(before / p, dest)
                    acl = home / 'restore.acl'
                    acl.write_text(acls[p])
                    run(['setfacl', '--restore=' + str(acl)])
                elif dest.is_file():
                    dest.unlink()
            run([PHP, str(app / 'artisan'), 'view:cache'], user=user, cwd=app)
            run([PHP, str(app / 'artisan'), 'up'], user=user, cwd=app)
            run(['systemctl', 'reload', 'php83rc-fpm'])
        raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok': False, 'error': str(error) if isinstance(error, (RuntimeError, AssertionError)) else type(error).__name__}))
        raise SystemExit(1)
