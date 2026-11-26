"""Read-only, normalized comparison of a release inventory archive and sources."""
import argparse
import difflib
from pathlib import Path
import tarfile


def normalized(data, name):
    value = data.decode('utf-8-sig').replace('\r\n', '\n')
    return value.replace('clubora.', 'clubunio.') if name.endswith('.php') else value


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('archive')
    parser.add_argument('paths', nargs='*')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    with tarfile.open(args.archive) as archive:
        for member in archive.getmembers():
            name = member.name.removeprefix('./')
            if not member.isfile() or (args.paths and name not in args.paths):
                continue
            local = root / name
            if not local.is_file():
                continue
            before = normalized(archive.extractfile(member).read(), name).splitlines(True)
            after = normalized(local.read_bytes(), name).splitlines(True)
            diff = list(difflib.unified_diff(before, after, fromfile='runtime/' + name, tofile='source/' + name, n=3))
            if diff:
                if args.paths:
                    print(''.join(diff))
                else:
                    print(f'{name}: +{sum(line.startswith("+") and not line.startswith("+++") for line in diff)} -{sum(line.startswith("-") and not line.startswith("---") for line in diff)}')


if __name__ == '__main__':
    main()
