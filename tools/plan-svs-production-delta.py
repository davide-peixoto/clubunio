"""Verify private SVS snapshots and report source changes; never connect to a DB."""
import argparse
import collections
import gzip
import hashlib
import json
from pathlib import Path


def read_snapshot(directory):
    directory = Path(directory).resolve()
    manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('format') != 'clubunio-source-v1' or manifest.get('source') != 'db_acdf8d_mysvs' or manifest.get('status') != 'complete':
        raise ValueError('Complete, recognized source snapshot required')
    definitions = directory / 'definitions.json'
    if hashlib.sha256(definitions.read_bytes()).hexdigest() != manifest['definitions_sha256']:
        raise ValueError('Definition checksum mismatch')
    result = {}
    for name, meta in manifest['tables'].items():
        path = (directory / meta['file']).resolve()
        if path.parent != directory or path.suffix != '.gz':
            raise ValueError('Unsafe snapshot path')
        if hashlib.sha256(path.read_bytes()).hexdigest() != meta['file_sha256']:
            raise ValueError('File checksum mismatch')
        rows, digest, count = {}, hashlib.sha256(), 0
        multiset = collections.Counter()
        with gzip.open(path, 'rb') as stream:
            for line in stream:
                digest.update(line)
                row = json.loads(line)
                if list(row) != [c['name'] for c in meta['columns']]:
                    raise ValueError('Column order or shape mismatch')
                canonical = json.dumps(row, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()
                fingerprint = hashlib.sha256(canonical).hexdigest()
                multiset[fingerprint] += 1
                if meta['primary_key']:
                    key = tuple(row[k] for k in meta['primary_key'])
                    if None in key or key in rows:
                        raise ValueError('Missing or duplicate primary key')
                    rows[key] = fingerprint
                count += 1
        if count != meta['rows'] or digest.hexdigest() != meta['row_stream_sha256']:
            raise ValueError('Row count or stream checksum mismatch')
        result[name] = dict(meta=meta, rows=rows, multiset=multiset)
    return result


def compare(previous, current):
    plan = {'source_delta_only': True, 'apply_supported': False, 'tables': {}, 'blockers': []}
    for name in sorted(previous.keys() | current.keys()):
        old, new = previous.get(name), current.get(name)
        if old is None or new is None:
            plan['tables'][name] = {'status': 'source_table_added' if old is None else 'source_table_removed'}
            plan['blockers'].append(name + ': source table inventory changed')
            continue
        if old['meta']['columns'] != new['meta']['columns'] or old['meta']['primary_key'] != new['meta']['primary_key']:
            plan['tables'][name] = {'status': 'source_schema_changed'}
            plan['blockers'].append(name + ': schema mapping review required')
            continue
        if not new['meta']['primary_key']:
            changed = old['multiset'] != new['multiset']
            plan['tables'][name] = {'status': 'unkeyed_changed' if changed else 'unkeyed_unchanged', 'rows': new['meta']['rows']}
            if changed:
                plan['blockers'].append(name + ': reviewed stable natural key required')
            continue
        before, after = old['rows'], new['rows']
        added, removed = after.keys() - before.keys(), before.keys() - after.keys()
        changed = sum(before[k] != after[k] for k in before.keys() & after.keys())
        plan['tables'][name] = {'status': 'compared', 'insert_candidates': len(added), 'update_candidates': changed,
                               'source_missing_review': len(removed), 'unchanged': len(before.keys() & after.keys()) - changed}
    return plan


def field_decision(baseline, source, target):
    """Three-way rule for an explicitly mapped field, not an import implementation."""
    if source == baseline:
        return 'preserve_target'
    if target == baseline:
        return 'apply_source'
    if source == target:
        return 'already_converged'
    return 'conflict'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--previous', required=True)
    parser.add_argument('--current', required=True)
    args = parser.parse_args()
    print(json.dumps(compare(read_snapshot(args.previous), read_snapshot(args.current)), indent=2))


if __name__ == '__main__':
    main()
