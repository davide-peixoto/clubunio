"""Synthetic archive tests; no application/database/server access."""
import hashlib
import io
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = (Path(__file__).resolve().parents[1] / 'deploy/release-clubunio-web-chat.sh').read_text()
VALIDATOR = SCRIPT.split("<<'PY'\n", 1)[1].split('\nPY\n', 1)[0]


class WebChatArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.package, self.release = self.base / 'package', self.base / 'release'
        self.package.mkdir()
        (self.release / 'app').mkdir(parents=True)
        self.target = self.release / 'app/example.php'
        self.target.write_bytes(b'old')
        self.data = b'<?php // fixture\n'
        (self.package / 'chat.manifest').write_text(hashlib.sha256(self.data).hexdigest() + '  app/example.php\n')

    def archive(self, names, kind=tarfile.REGTYPE, data=None):
        with tarfile.open(self.package / 'chat.tar.gz', 'w:gz') as archive:
            for name in names:
                entry = tarfile.TarInfo(name)
                entry.mode, entry.type = 0o644, kind
                content = self.data if data is None else data
                entry.size = len(content) if kind == tarfile.REGTYPE else 0
                entry.linkname = 'app/example.php' if kind != tarfile.REGTYPE else ''
                archive.addfile(entry, io.BytesIO(content) if entry.size else None)

    def run_validator(self):
        with patch.object(sys, 'argv', ['validator', str(self.package), str(self.release)]):
            exec(compile(VALIDATOR, '<webchat archive validator>', 'exec'), {})

    def rejected(self):
        with self.assertRaises(AssertionError):
            self.run_validator()
        self.assertEqual(b'old', self.target.read_bytes())

    def test_exact_regular_overlay(self):
        self.archive(['app/example.php'])
        self.run_validator()
        self.assertEqual(self.data, self.target.read_bytes())

    def test_duplicate(self):
        self.archive(['app/example.php'] * 2)
        self.rejected()

    def test_extra(self):
        self.archive(['app/example.php', '.env'])
        self.rejected()

    def test_traversal(self):
        self.archive(['../example.php'])
        self.rejected()

    def test_symlink(self):
        self.archive(['app/example.php'], tarfile.SYMTYPE)
        self.rejected()

    def test_hardlink(self):
        self.archive(['app/example.php'], tarfile.LNKTYPE)
        self.rejected()

    def test_hash_mismatch(self):
        self.archive(['app/example.php'], data=b'wrong')
        self.rejected()


if __name__ == '__main__':
    unittest.main()
