"""Audit the adopted snapshot and list legacy API literals without executing it."""
import hashlib
import json
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
MOBILE = ROOT / 'mobile'

def audit():
    manifest = json.loads((MOBILE / 'adoption-manifest.json').read_text(encoding='utf-8'))
    source = Path(r'C:\Projects\SVS\app')
    failures = []
    for entry in manifest['files']:
        original = source / entry['source']
        if hashlib.sha256(original.read_bytes()).hexdigest() != entry['source_sha256']:
            failures.append(entry['source'])
    if failures:
        raise SystemExit('Source changed since adoption: ' + ', '.join(failures))
    print(f"Source unchanged: {len(manifest['files'])} file hashes verified.")
    refs = []
    for path in sorted((MOBILE / 'lib').rglob('*.dart')):
        for number, line in enumerate(path.read_text(encoding='utf-8-sig').splitlines(), 1):
            for match in re.finditer(r'''['"](/api/[^'"\s]*)['"]''', line):
                refs.append({'file': path.relative_to(MOBILE).as_posix(), 'line': number, 'literal': match[1]})
    result = {'note': 'Static legacy path literals, not a complete operation inventory. Interpolated paths and method-dependent workflows require manual review. No inferred v1 support.', 'references': refs}
    destination = ROOT / 'deploy/CLUBUNIO-MOBILE-ENDPOINTS.json'
    output = json.dumps(result, ensure_ascii=False, indent=2) + '\n'
    if not destination.exists() or destination.read_text(encoding='utf-8') != output:
        destination.write_text(output, encoding='utf-8')
    print(f'Legacy path references: {len(refs)}; source and adopted credentials excluded.')

if __name__ == '__main__':
    audit()
