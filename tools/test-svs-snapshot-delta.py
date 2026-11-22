"""Root-only end-to-end fixture test against an already imported isolated copy."""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import uuid


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--lab-root', type=Path, required=True)
    parser.add_argument('--baseline-db', required=True)
    args = parser.parse_args()
    if os.geteuid() != 0 or not re.fullmatch(r'clubunio_svs_rehearsal_[a-f0-9]{8}', args.baseline_db):
        raise ValueError('Root and isolated baseline DB required; PROD is forbidden')
    root = args.lab_root.resolve()
    suffix = uuid.uuid4().hex[:8]
    variant = root / ('source-delta-fixture-' + suffix)
    shutil.copytree(root / 'source', variant)
    manifest = json.loads((variant / 'manifest.json').read_text())
    meta = manifest['tables']['dbo.Bemerkungen']
    file = variant / meta['file']
    with gzip.open(file, 'rt', encoding='utf-8') as stream:
        rows = [json.loads(line) for line in stream]
    removed = rows[1]['BemerkungsID']
    rows[0]['Bemerkung'] = 'Synthetic raw-snapshot update'
    added = dict(rows[0], BemerkungsID='900000002', Bemerkung='Synthetic raw-snapshot insert')
    rows = [row for row in rows if row['BemerkungsID'] != removed] + [added]
    raw = b''.join((json.dumps(row, ensure_ascii=False, separators=(',', ':')) + '\n').encode() for row in rows)
    file.write_bytes(gzip.compress(raw))
    meta.update(rows=len(rows), row_stream_sha256=hashlib.sha256(raw).hexdigest(), file_sha256=hashlib.sha256(file.read_bytes()).hexdigest())
    manifest['synthetic_rehearsal_fixture'] = True
    (variant / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))
    digest = hashlib.sha256((variant / 'manifest.json').read_bytes()).hexdigest()
    source, target = 'clubunio_stage_svs_candidate_' + suffix, 'clubunio_svs_rehearsal_' + suffix
    subprocess.run(['sudo', '-u', 'postgres', 'createdb', '--template=template0', '--owner=userunio', source], check=True, capture_output=True)
    subprocess.run(['sudo', '-u', 'postgres', 'createdb', '--template=' + args.baseline_db, '--owner=userunio', target], check=True, capture_output=True)
    php = ['/RunCloud/Packages/php83rc/bin/php', '-d', 'memory_limit=1G']
    result = subprocess.run(php + ['tools/build-svs-import-candidate.php', source, str(variant), digest], cwd=root / 'code', capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('Fixture projection failed; inspect private lab logs')
    env = dict(os.environ, CLUBUNIO_SVS_SNAPSHOT_DIR=str(variant))
    receipts = {}
    for label in ['apply', 'replay']:
        result = subprocess.run(php + ['tools/run-svs-delta-import.php', '--apply', source, target, digest], cwd=root / 'code', env=env, capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError('Fixture merge failed; no source values logged')
        report = json.loads(result.stdout)
        expected = (1, 1, 1) if label == 'apply' else (0, 0, 1)
        if (report['inserted'], report['updated'], report['missing_review']) != expected:
            raise AssertionError('Unexpected source delta write counts')
        receipts[label] = {k: v for k, v in report.items() if k != 'tables'}
    receipts['source'] = source
    receipts['target'] = target
    (root / ('raw-snapshot-delta-' + suffix + '.json')).write_text(json.dumps(receipts, indent=2))
    print(json.dumps(receipts, indent=2))


if __name__ == '__main__':
    main()
