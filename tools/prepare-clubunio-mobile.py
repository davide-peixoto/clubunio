"""Allowlisted one-time Flutter adoption. Source project stays read only."""
import hashlib
import json
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
SOURCE = Path(r'C:\Projects\SVS\app')
TARGET = ROOT / 'mobile'

def main():
    if TARGET.exists():
        raise SystemExit('mobile exists; refusing to overwrite adopted work')
    paths = [SOURCE / name for name in ('pubspec.yaml', 'pubspec.lock', 'analysis_options.yaml')]
    paths += sorted((SOURCE / 'lib').rglob('*.dart'))
    paths += sorted((SOURCE / 'test').rglob('*.dart'))
    paths += sorted((SOURCE / 'assets' / 'images').glob('*.png'))
    manifest = []
    for source in paths:
        relative = source.relative_to(SOURCE)
        original = source.read_bytes()
        adopted = Path('lib/legacy_main.dart') if relative.as_posix() == 'lib/main.dart' else relative
        result = original
        if source.suffix in ('.dart', '.yaml'):
            content = original.decode('utf-8-sig').replace('package:sportverein_sissach/', 'package:clubunio_mobile/')
            if relative.name == 'pubspec.yaml':
                content = content.replace('name: sportverein_sissach', 'name: clubunio_mobile')
                content = content.replace('version: 1.1.8+46', 'version: 0.1.0+1')
                content = content.replace('description: Mobile App fuer den Sportverein Sissach. MVP fuer Spesen, modular fuer weitere Vereinsfunktionen.', 'description: ClubUnio mobile application, adopted from My SV Sissach.')
            if relative.as_posix() == 'lib/core/api/api_config.dart':
                content = content.replace('https://my.svsissach.ch', 'https://legacy-api-disabled.invalid').replace('https://dev-my.svsissach.ch', 'https://legacy-api-disabled.invalid')
            if relative.as_posix() in ('lib/core/api/token_storage.dart', 'lib/core/api/environment_storage.dart'):
                content = content.replace("'svs.", "'clubunio.")
            result = content.encode('utf-8')
        destination = TARGET / adopted
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(result)
        manifest.append({'source': relative.as_posix(), 'adopted': adopted.as_posix(), 'source_sha256': hashlib.sha256(original).hexdigest(), 'adopted_sha256': hashlib.sha256(result).hexdigest()})
    (TARGET / 'adoption-manifest.json').write_text(json.dumps({'source_project': 'My SV Sissach', 'adopted_on': '2026-10-26', 'source_revision': None, 'revision_note': 'Source .git inaccessible; SHA256 hashes identify exact adopted file content.', 'excluded': ['.git', 'native platform projects', 'Firebase configuration', 'builds', 'runtime data', 'store screenshots', 'SQL/PHP backend tests', 'source docs'], 'files': manifest}, indent=2) + '\n', encoding='utf-8')
    print(f'Adopted {len(manifest)} files; source project unchanged.')

if __name__ == '__main__':
    main()
