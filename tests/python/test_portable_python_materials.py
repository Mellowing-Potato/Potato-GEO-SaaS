"""Bundled Python runtime and report font archives must pass provenance, platform, and safety checks."""
import argparse
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('portable_package', ROOT / 'scripts/build-local-package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


def write_tar(path: Path, files: dict[str, bytes], symlinks: dict[str, str] | None = None) -> None:
    with tarfile.open(path, 'w:gz') as out:
        for name, data in files.items():
            item = tarfile.TarInfo(name)
            item.size = len(data)
            item.mode = 0o755 if name.endswith(('/python3', '/python.exe')) else 0o644
            out.addfile(item, io.BytesIO(data))
        for name, target in (symlinks or {}).items():
            item = tarfile.TarInfo(name)
            item.type = tarfile.SYMTYPE
            item.linkname = target
            out.addfile(item)


class PortablePythonMaterialsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='yanji-py-materials-')
        self.root = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def _valid_python_tar(self, platform='macos-arm64'):
        exe = 'python/python.exe' if platform == 'windows-x86_64' else 'python/bin/python3'
        path = self.root / f'python-{platform}.tar.gz'
        write_tar(path, {
            exe: b'#!/bin/sh\n',
            'python/build-manifest.json': json.dumps({'platform': platform}).encode(),
            'python/notices/CPython-LICENSE.txt': b'PSF-2.0',
        })
        return path

    def _valid_fonts_tar(self):
        path = self.root / 'fonts.tar.gz'
        write_tar(path, {
            'fonts/DejaVuSans.ttf': b'ttf1',
            'fonts/DejaVuSans-Bold.ttf': b'ttf2',
            'fonts/NotoSerifCJK-Regular.ttc': b'ttc',
            'fonts/notices/dejavu/LICENSE': b'dejavu-license',
        })
        return path

    def test_valid_python_and_fonts_archives_pass_inspection(self):
        py = self._valid_python_tar('macos-arm64')
        fonts = self._valid_fonts_tar()
        args = argparse.Namespace(
            platform='macos-arm64',
            python_runtime=py, python_runtime_sha256=package.digest(py),
            fonts=fonts, fonts_sha256=package.digest(fonts),
        )
        self.assertEqual(('python', package.digest(py)), package.inspect_python_runtime(args))
        self.assertEqual(('fonts', package.digest(fonts)), package.inspect_fonts(args))

    def test_mismatched_platform_or_symlink_or_missing_notices_are_rejected(self):
        wrong_plat = self._valid_python_tar('linux-x86_64')
        args = argparse.Namespace(
            platform='macos-arm64',
            python_runtime=wrong_plat, python_runtime_sha256=package.digest(wrong_plat),
        )
        with self.assertRaisesRegex(ValueError, 'platform differs'):
            package.inspect_python_runtime(args)

        symlink_tar = self.root / 'python-symlink.tar.gz'
        write_tar(symlink_tar, {
            'python/bin/python3': b'bin',
            'python/build-manifest.json': json.dumps({'platform': 'macos-arm64'}).encode(),
            'python/notices/LICENSE.txt': b'ok',
        }, symlinks={'python/bin/python': 'python3'})
        args = argparse.Namespace(
            platform='macos-arm64',
            python_runtime=symlink_tar, python_runtime_sha256=package.digest(symlink_tar),
        )
        with self.assertRaisesRegex(ValueError, 'Unsafe Python archive'):
            package.inspect_python_runtime(args)

        no_notices = self.root / 'fonts-missing-notices.tar.gz'
        write_tar(no_notices, {
            'fonts/DejaVuSans.ttf': b'ttf1',
            'fonts/DejaVuSans-Bold.ttf': b'ttf2',
            'fonts/NotoSerifCJK-Regular.ttc': b'ttc',
        })
        args = argparse.Namespace(fonts=no_notices, fonts_sha256=package.digest(no_notices))
        with self.assertRaisesRegex(ValueError, 'license notices'):
            package.inspect_fonts(args)

    def test_portable_python_manifest_pins_all_four_platforms(self):
        spec_data = json.loads((ROOT / 'licenses/portable-python.json').read_text(encoding='utf-8'))
        for plat in ('macos-arm64', 'linux-aarch64', 'linux-x86_64', 'windows-x86_64'):
            entry = spec_data['platforms'][plat]
            self.assertTrue(entry['url'].startswith('https://'))
            self.assertEqual(64, len(entry['sha256']))


if __name__ == '__main__':
    unittest.main()
