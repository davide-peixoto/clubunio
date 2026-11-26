"""Read-only normalized application-source inventory; excludes private/runtime trees."""
import argparse
import hashlib
import json
import tarfile
from pathlib import Path

ROOTS = ['app', 'config', 'lang', 'resources/views', 'resources/js', 'routes', 'public/css', 'public/js']
EXTENSIONS = {'.php', '.js', '.mjs', '.jsx', '.css', '.json'}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('root')
    parser.add_argument('output')
    parser.add_argument('--archive')
    args = parser.parse_args()
    root = Path(args.root).resolve()
    files = {}
    for directory in ROOTS:
        for path in (root / directory).rglob('*'):
            if not path.is_file() or path.is_symlink() or path.suffix not in EXTENSIONS:
                continue
            relative = path.relative_to(root).as_posix()
            content = path.read_bytes().replace(b'\r\n', b'\n')
            if path.suffix == '.php':
                content = content.replace(b'clubora.', b'clubunio.')
            files[relative] = hashlib.sha256(content).hexdigest()
    Path(args.output).write_text(json.dumps(files, sort_keys=True, indent=2), encoding='utf-8')
    if args.archive:
        with tarfile.open(args.archive, 'w:gz') as archive:
            for relative in files:
                archive.add(root / relative, arcname=relative, recursive=False)
    print(json.dumps({'files': len(files), 'root': str(root)}))


if __name__ == '__main__':
    main()
