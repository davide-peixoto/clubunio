#!/usr/bin/env python3
"""Quarantine historical config copies containing the former runtime secret.

Root-only, restricted to vps.clubunio.com. Default is read-only. Requires explicit
--apply --confirm-environment ENV after the new runtime role has been activated.
Credential values remain in memory and are never printed. Does not alter active
configuration or remove archives. Historical files are preserved under a private
root quarantine with identical SHA256 and an evidence manifest.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import time

ROOT = Path('/root/clubunio-releases/security-20261125')
PHP = '/RunCloud/Packages/php83rc/bin/php'


def execute(argv):
    result = subprocess.run(argv, capture_output=True)
    if result.returncode:
        raise RuntimeError('Private credential/config operation failed; details suppressed')
    return result.stdout


def settings(app, path):
    code = ('require ' + json.dumps(str(app / 'vendor/autoload.php')) + ';'
            '$v=Dotenv\\Dotenv::parse(file_get_contents(' + json.dumps(str(path)) + '));'
            'echo json_encode($v,JSON_THROW_ON_ERROR);')
    return json.loads(execute([PHP, '-r', code]))


def contains(path, needle):
    with path.open('rb') as stream:
        previous = b''
        while True:
            chunk = stream.read(1024 * 1024)
            if not chunk:
                return False
            data = previous + chunk
            if needle in data:
                return True
            previous = data[-(len(needle) - 1):] if len(needle) > 1 else b''


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()


def scan(app, needle):
    matches = []
    for root, dirs, files in os.walk(app, followlinks=False):
        dirs[:] = [name for name in dirs if name not in {'vendor', 'node_modules', '.git'}
                   and not (Path(root) / name).is_symlink()]
        for name in files:
            path = Path(root) / name
            if stat.S_ISREG(path.lstat().st_mode) and contains(path, needle):
                matches.append(path)
    return sorted(matches)


def historical(relative, env):
    # Only these two identified, obsolete release-cache layouts are eligible.
    patterns = [rf'storage/app/private/tenant-ai-releases/{env}-[0-9]{{8}}-[0-9]{{6}}/cache/config\.php',
                rf'storage/app/private/openai-model-upgrades/release-{env}-[0-9]{{8}}-[0-9]{{6}}/config\.php']
    return any(re.fullmatch(pattern, relative.as_posix()) for pattern in patterns)


def private_directory(path):
    if path.is_symlink():
        raise RuntimeError('Unexpected quarantine symlink')
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    # Every ancestor within the quarantine must be root-owned and private.
    current = path
    while current != ROOT:
        if current.is_symlink():
            raise RuntimeError('Unexpected quarantine ancestor symlink')
        os.chown(current, 0, 0)
        os.chmod(current, 0o700)
        current = current.parent


def tree_hashes(directory):
    result = {}
    total = 0
    for root, dirs, files in os.walk(directory, followlinks=False):
        for name in dirs + files:
            if (Path(root) / name).is_symlink():
                raise RuntimeError('Historical archive symlink requires review')
        for name in files:
            path = Path(root) / name
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode):
                raise RuntimeError('Historical archive special file requires review')
            total += info.st_size
            if len(result) >= 10000 or total > 8 * 1024 ** 3:
                raise RuntimeError('Historical archive inspection bound exceeded')
            result[path.relative_to(directory).as_posix()] = digest(path)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('environment', choices=['stage', 'prod'])
    parser.add_argument('--source', required=True, help='Original root-private .env.postgresql.local backup')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--confirm-environment', choices=['stage', 'prod'])
    parser.add_argument('--historical-directories', action='store_true',
                        help='Also isolate the two exact historical operator backup trees, including archives')
    args = parser.parse_args()
    if os.geteuid() != 0 or socket.getfqdn().rstrip('.') != 'vps.clubunio.com' or socket.gethostname().rstrip('.') != 'vps.clubunio.com':
        raise RuntimeError('Restricted to root on vps.clubunio.com')
    if args.apply and args.confirm_environment != args.environment:
        raise RuntimeError('Apply requires matching environment confirmation')
    env = args.environment
    app = Path('/home/runcloud/webapps/ClubUnio' + ('Stage' if env == 'stage' else 'Prod'))
    if not app.is_dir() or app.is_symlink():
        raise RuntimeError('Unexpected runtime path')
    source = Path(args.source)
    if source.is_symlink() or ROOT not in source.resolve().parents or source.name != '.env.postgresql.local':
        raise RuntimeError('Unexpected private source path')
    original = settings(app, source)
    if original.get('PGUSER') != 'userunio' or not isinstance(original.get('PGPASSWORD'), str) or not original['PGPASSWORD']:
        raise RuntimeError('Expected original userunio credential source')
    needle = original['PGPASSWORD'].encode()
    if original.get('PGDATABASE') != 'clubunio_' + env:
        raise RuntimeError('Credential source environment mismatch')
    current = settings(app, app / '.env.postgresql.local')
    if args.apply and (current.get('PGUSER') != 'clubunio_' + env + '_app'
                       or current.get('PGPASSWORD') == original['PGPASSWORD']):
        raise RuntimeError('Activate distinct runtime role before quarantine')
    matches = scan(app, needle)
    unexpected = [p for p in matches if not historical(p.relative_to(app), env)]
    for path in matches:
        print(json.dumps({'match_path': str(path), 'eligible_historical_config': path not in unexpected}))
    if unexpected:
        raise RuntimeError('Credential matches outside historical allowlist; review required, nothing moved')
    historical_trees = []
    if args.historical_directories:
        for name in ['tenant-ai-releases', 'openai-model-upgrades']:
            directory = app / 'storage/app/private' / name
            if directory.is_symlink():
                raise RuntimeError('Historical directory symlink requires review')
            if directory.exists():
                if not directory.is_dir() or directory.resolve() != directory:
                    raise RuntimeError('Unexpected historical directory')
                dest = ROOT / 'historical-archives' / env / name
                if dest.exists() or dest.is_symlink() or directory.stat().st_dev != ROOT.stat().st_dev:
                    raise RuntimeError('Historical tree destination or filesystem requires review')
                hashes = tree_hashes(directory)  # Bounded preflight before any move.
                historical_trees.append((directory, dest, hashes))
                print(json.dumps({'historical_directory': str(directory), 'file_count': len(hashes)}))
    if not args.apply:
        print(json.dumps({'dry_run': True, 'matches': len(matches), 'historical_directories': len(historical_trees)}))
        return
    quarantine = ROOT / 'quarantine' / env
    records = []
    # Check all destinations before making any move.
    for path in matches:
        dest = quarantine / path.relative_to(app)
        if dest.exists() or dest.is_symlink():
            raise RuntimeError('Quarantine destination exists; review required')
        if path.stat().st_dev != ROOT.stat().st_dev:
            raise RuntimeError('Atomic same-filesystem quarantine required')
    for path in matches:
        relative = path.relative_to(app)
        dest = quarantine / relative
        original_stat = path.lstat()
        if not stat.S_ISREG(original_stat.st_mode) or path.resolve() != path:
            raise RuntimeError('Historical source changed or contains symlinks')
        before = digest(path)
        private_directory(dest.parent)
        # Same-filesystem rename preserves original bytes; no deletion of content.
        os.rename(path, dest)
        relocated_stat = dest.lstat()
        if not stat.S_ISREG(relocated_stat.st_mode) or (original_stat.st_dev, original_stat.st_ino) != (relocated_stat.st_dev, relocated_stat.st_ino):
            raise RuntimeError('Historical source changed during quarantine; inspect private destination')
        os.chown(dest, 0, 0, follow_symlinks=False)
        execute(['setfacl', '-b', str(dest)])
        os.chmod(dest, 0o600)
        after = digest(dest)
        if before != after:
            raise RuntimeError('Quarantined file hash mismatch')
        record = {'source_path': str(path), 'quarantine_path': str(dest), 'sha256_before': before, 'sha256_after': after}
        records.append(record)
        print(json.dumps(record))
    tree_records = []
    for directory, dest, original_hashes in historical_trees:
        # Individual matching configs have already moved to the exact-file
        # quarantine. Preserve every remaining historical file/archive as well.
        before = tree_hashes(directory)
        expected = {name: value for name, value in original_hashes.items()
                    if directory / name not in matches}
        if before != expected:
            raise RuntimeError('Historical tree changed after preflight')
        private_directory(dest.parent)
        inode = directory.stat().st_ino
        os.rename(directory, dest)
        if dest.is_symlink() or not dest.is_dir() or dest.stat().st_ino != inode:
            raise RuntimeError('Historical directory changed during move')
        execute(['setfacl', '-Rb', str(dest)])
        for root, dirs, names in os.walk(dest, followlinks=False):
            os.chown(root, 0, 0)
            os.chmod(root, 0o700)
            for name in names:
                path = Path(root) / name
                if not stat.S_ISREG(path.lstat().st_mode):
                    raise RuntimeError('Historical file changed during move')
                os.chown(path, 0, 0, follow_symlinks=False)
                os.chmod(path, 0o600)
        after = tree_hashes(dest)
        if before != after:
            raise RuntimeError('Historical directory integrity mismatch')
        tree_records.append({'source_directory': str(directory), 'destination_directory': str(dest),
                             'file_count': len(after), 'sha256_before': before, 'sha256_after': after})
        print(json.dumps({'historical_directory_moved': str(directory), 'private_destination': str(dest),
                          'file_count': len(after), 'all_file_hashes_verified': True}))
    remaining = scan(app, needle)
    private_directory(quarantine)
    manifest = quarantine / ('manifest-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '.json')
    with manifest.open('x') as stream:
        os.chmod(manifest, 0o600)
        json.dump({'environment': env, 'moved': records, 'historical_directory_moves': tree_records,
                   'remaining_match_paths': [str(p) for p in remaining],
                   'scan_excludes': ['vendor', 'node_modules', '.git'], 'compressed_archives_not_decompressed': True}, stream)
    print(json.dumps({'quarantined_count': len(records), 'historical_directories_moved': len(tree_records),
                      'remaining_matches': len(remaining), 'manifest_path': str(manifest)}))
    if remaining:
        raise RuntimeError('Credential matches remain; review required')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Never echo unexpected Dotenv, PHP, filesystem or credential contents.
        print('Quarantine stopped; review safe path/count output and private state.', file=sys.stderr)
        sys.exit(1)
