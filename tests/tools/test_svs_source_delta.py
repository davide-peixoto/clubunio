import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('delta', Path(__file__).resolve().parents[2] / 'tools/plan-svs-production-delta.py')
delta = importlib.util.module_from_spec(spec)
spec.loader.exec_module(delta)


class DeltaTests(unittest.TestCase):
    def snapshot(self, root, name, rows, keys=None):
        path = Path(root) / name
        path.mkdir()
        raw = b''.join((json.dumps(r) + '\n').encode() for r in rows)
        (path / 'table.jsonl.gz').write_bytes(gzip.compress(raw))
        (path / 'definitions.json').write_text('[]')
        manifest = {'format': 'clubunio-source-v1', 'source': 'db_acdf8d_mysvs', 'status': 'complete',
                    'definitions_sha256': hashlib.sha256(b'[]').hexdigest(), 'tables': {'dbo.Members': {
                        'file': 'table.jsonl.gz', 'rows': len(rows), 'primary_key': ['id'] if keys is None else keys,
                        'columns': [{'name': 'id'}, {'name': 'name'}],
                        'file_sha256': hashlib.sha256((path / 'table.jsonl.gz').read_bytes()).hexdigest(),
                        'row_stream_sha256': hashlib.sha256(raw).hexdigest()}}}
        (path / 'manifest.json').write_text(json.dumps(manifest))
        return path

    def test_insert_update_missing_and_replay(self):
        with tempfile.TemporaryDirectory() as root:
            old = delta.read_snapshot(self.snapshot(root, 'a', [{'id':'1','name':'A'}, {'id':'2','name':'B'}]))
            new = delta.read_snapshot(self.snapshot(root, 'b', [{'id':'1','name':'C'}, {'id':'3','name':'D'}]))
            counts = delta.compare(old, new)['tables']['dbo.Members']
            self.assertEqual((counts['insert_candidates'], counts['update_candidates'], counts['source_missing_review']), (1, 1, 1))
            replay = delta.compare(new, new)['tables']['dbo.Members']
            self.assertEqual((replay['insert_candidates'], replay['update_candidates'], replay['source_missing_review']), (0,0,0))

    def test_target_edits_are_preserved_and_conflicts_block(self):
        self.assertEqual(delta.field_decision('old','old','local'), 'preserve_target')
        self.assertEqual(delta.field_decision('old','source','old'), 'apply_source')
        self.assertEqual(delta.field_decision('old','source','source'), 'already_converged')
        self.assertEqual(delta.field_decision('old','source','local'), 'conflict')

    def test_changed_unkeyed_table_blocks(self):
        with tempfile.TemporaryDirectory() as root:
            old = delta.read_snapshot(self.snapshot(root, 'a', [{'id':'1','name':'A'}], []))
            new = delta.read_snapshot(self.snapshot(root, 'b', [{'id':'1','name':'B'}], []))
            self.assertTrue(delta.compare(old,new)['blockers'])

    def test_corrupted_snapshot_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            path = self.snapshot(root, 'a', [{'id':'1','name':'A'}])
            (path/'table.jsonl.gz').write_bytes(b'corrupted')
            with self.assertRaises(ValueError): delta.read_snapshot(path)

    def test_duplicate_primary_key_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            path = self.snapshot(root, 'a', [{'id':'1','name':'A'}, {'id':'1','name':'B'}])
            with self.assertRaises(ValueError): delta.read_snapshot(path)

    def test_source_schema_drift_blocks(self):
        with tempfile.TemporaryDirectory() as root:
            old = delta.read_snapshot(self.snapshot(root,'a',[{'id':'1','name':'A'}]))
            new = delta.read_snapshot(self.snapshot(root,'b',[{'id':'1','name':'A'}]))
            new['dbo.Members']['meta']['columns'][1]['type_name'] = 'new_type'
            self.assertTrue(delta.compare(old,new)['blockers'])


if __name__ == '__main__':
    unittest.main()
