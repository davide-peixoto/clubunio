"""Record a verified target source tree without checkout, reset, or lost history.

Only captured application sources are overlaid. Private/runtime files are never
read. Both the canonical source commit and previous environment tip are parents.
The command creates a review branch; publishing is a separate explicit action.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parent.parent
ALLOWED = ('app/', 'config/', 'lang/', 'resources/views/', 'resources/js/', 'routes/', 'public/css/', 'public/js/')


def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT)


def tree(ref):
    result = {}
    for row in git('ls-tree', '-rz', ref).split(b'\0'):
        if row:
            info, path = row.split(b'\t', 1)
            mode, kind, digest = info.decode().split()
            if kind == 'blob':
                result[path.decode()] = (mode, digest)
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('environment', choices=['stage', 'prod'])
    parser.add_argument('archive')
    parser.add_argument('canonical')
    parser.add_argument('previous')
    args = parser.parse_args()
    canonical = git('rev-parse', args.canonical).decode().strip()
    previous = git('rev-parse', args.previous).decode().strip()
    env = args.environment
    branch = 'refs/heads/codex/align-' + env + '-20261126'
    if subprocess.run(['git', 'show-ref', '--verify', '--quiet', branch], cwd=ROOT).returncode == 0:
        raise RuntimeError('Review branch already exists')
    entries = {}
    with tarfile.open(args.archive) as archive:
        for member in archive.getmembers():
            name = member.name.removeprefix('./')
            if not member.isfile() or not name.startswith(ALLOWED) or '..' in Path(name).parts:
                raise RuntimeError('Unexpected source member')
            data = archive.extractfile(member).read().replace(b'\r\n', b'\n')
            if re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|sk-proj-[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}', data):
                raise RuntimeError('Potential credential in ' + name)
            entries[name] = data
    manifest = json.loads((ROOT / 'storage/app/release-alignment-20261126.json').read_text())
    for name, digest in manifest.items():
        if not name.startswith('deploy/'):
            assert hashlib.sha256(entries[name]).hexdigest() == digest, 'Unverified package member: ' + name
    current = tree(canonical)
    old = tree(previous)
    identity = git('var', 'GIT_AUTHOR_IDENT').strip()
    message = ('Align ' + env.upper() + ' with reviewed common release while preserving active security and import sources\n').encode()
    stream = bytearray(b'commit ' + branch.encode() + b'\ncommitter ' + identity + b'\ndata ' + str(len(message)).encode() + b'\n' + message)
    stream += b'from ' + canonical.encode() + b'\nmerge ' + previous.encode() + b'\n'
    # Keep environment-only scripts and documentation in addition to their history.
    for name, (mode, digest) in sorted(old.items()):
        if name not in current and name not in entries and not name.startswith(('.env', 'storage/', 'vendor/', 'bootstrap/cache/')):
            stream += ('M ' + mode + ' ' + digest + ' ' + json.dumps(name) + '\n').encode()
    for name, data in sorted(entries.items()):
        stream += ('M 100644 inline ' + json.dumps(name) + '\ndata ' + str(len(data)) + '\n').encode() + data + b'\n'
    report = json.dumps({'environment': env, 'canonical': canonical, 'previous': previous,
        'runtime_sources': len(entries), 'common_release_files': len(manifest),
        'source_sha256': {p: hashlib.sha256(data).hexdigest() for p, data in sorted(entries.items())}}, indent=2).encode() + b'\n'
    stream += ('M 100644 inline deploy/evidence/alignment-20261126/' + env + '-sources.json\ndata ' + str(len(report)) + '\n').encode() + report + b'\n\ndone\n'
    result = subprocess.run(['git', 'fast-import', '--quiet'], cwd=ROOT, input=stream, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace'))
    commit = git('rev-parse', branch).decode().strip()
    assert subprocess.run(['git', 'merge-base', '--is-ancestor', previous, commit], cwd=ROOT).returncode == 0
    assert subprocess.run(['git', 'merge-base', '--is-ancestor', canonical, commit], cwd=ROOT).returncode == 0
    print(json.dumps({'environment': env, 'commit': commit, 'sources': len(entries), 'both_histories_preserved': True}))


if __name__ == '__main__':
    main()
