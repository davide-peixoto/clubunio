"""Local, synthetic archive validation tests. No application boot/SSH/database."""
import hashlib
import io
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = (ROOT / 'deploy/release-clubunio-pitch.sh').read_text()
VALIDATOR = SCRIPT.split("<<'PY'\n", 1)[1].split('\nPY\n', 1)[0]


class PitchArchiveTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.package = self.base / 'package'
        self.release = self.base / 'release'
        self.package.mkdir()
        (self.release / 'app').mkdir(parents=True)
        self.target = self.release / 'app/example.php'
        self.target.write_bytes(b'old')
        self.new = b'<?php // synthetic\n'
        self.manifest('app/example.php')

    def manifest(self, name):
        (self.package / 'pitch.manifest').write_text(hashlib.sha256(self.new).hexdigest() + '  ' + name + '\n')

    def archive(self, entries):
        with tarfile.open(self.package / 'pitch.tar.gz', 'w:gz') as tar:
            for name, data, kind in entries:
                entry = tarfile.TarInfo(name)
                entry.mode = 0o644
                entry.type = kind
                entry.size = len(data) if kind == tarfile.REGTYPE else 0
                entry.linkname = 'app/example.php' if kind != tarfile.REGTYPE else ''
                tar.addfile(entry, io.BytesIO(data) if entry.size else None)

    def run_validator(self):
        with patch.object(sys, 'argv', ['validator', str(self.package), str(self.release)]):
            exec(compile(VALIDATOR, '<pitch archive validator>', 'exec'), {})

    def rejected(self):
        with self.assertRaises(AssertionError):
            self.run_validator()
        self.assertEqual(b'old', self.target.read_bytes())

    def test_exact_regular_payload(self):
        self.archive([('app/example.php', self.new, tarfile.REGTYPE)])
        self.run_validator()
        self.assertEqual(self.new, self.target.read_bytes())

    def test_extra_file_never_overlays(self):
        self.archive([('app/example.php', self.new, tarfile.REGTYPE), ('app/foreign.php', b'extra', tarfile.REGTYPE)])
        self.rejected()
        self.assertFalse((self.release / 'app/foreign.php').exists())

    def test_duplicate_member_never_overlays(self):
        self.archive([('app/example.php', self.new, tarfile.REGTYPE)] * 2)
        self.rejected()

    def test_symlink_member_never_overlays(self):
        self.archive([('app/example.php', b'', tarfile.SYMTYPE)])
        self.rejected()

    def test_hardlink_member_never_overlays(self):
        self.archive([('app/example.php', b'', tarfile.LNKTYPE)])
        self.rejected()

    def test_wrong_bytes_never_overlays(self):
        self.archive([('app/example.php', b'wrong', tarfile.REGTYPE)])
        self.rejected()

    def test_traversal_never_overlays(self):
        self.manifest('app/../../escape.php')
        self.archive([('app/../../escape.php', self.new, tarfile.REGTYPE)])
        self.rejected()

    def test_environment_file_never_overlays(self):
        self.manifest('.env')
        self.archive([('.env', self.new, tarfile.REGTYPE)])
        self.rejected()

    def test_nested_environment_file_never_overlays(self):
        self.manifest('app/.env')
        self.archive([('app/.env', self.new, tarfile.REGTYPE)])
        self.rejected()

    def test_destination_link_never_overlays(self):
        linked = self.release / 'app/link'
        try:
            linked.symlink_to(self.base, target_is_directory=True)
        except OSError:
            self.skipTest('OS does not permit synthetic directory symlinks')
        self.manifest('app/link/escape.php')
        self.archive([('app/link/escape.php', self.new, tarfile.REGTYPE)])
        self.rejected()


if __name__ == '__main__':
    unittest.main()
