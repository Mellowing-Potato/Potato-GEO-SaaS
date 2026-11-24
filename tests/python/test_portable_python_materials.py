"""Bundled Python runtime and report font archives must pass provenance, platform, and safety checks."""
import argparse
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import venv
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import zipfile


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('portable_package', ROOT / 'scripts/build-local-package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)

boot_spec = importlib.util.spec_from_file_location('release_bootstrap', ROOT / 'scripts/runtime/make-release-bootstrap.py')
bootstrap = importlib.util.module_from_spec(boot_spec)
boot_spec.loader.exec_module(bootstrap)

merge_spec = importlib.util.spec_from_file_location('release_merge', ROOT / 'scripts/runtime/merge-release-dir.py')
merger = importlib.util.module_from_spec(merge_spec)
merge_spec.loader.exec_module(merger)


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
        files = {
            exe: b'#!/bin/sh\n',
            'python/build-manifest.json': json.dumps({'platform': platform}).encode(),
            'python/notices/CPython-LICENSE.txt': b'PSF-2.0',
        }
        if platform == 'windows-x86_64':
            # Mirror real python-build-standalone layout which ships nested venv launchers.
            files['python/Lib/venv/scripts/nt/python.exe'] = b'venv-launcher'
        write_tar(path, files)
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

    def test_python_probe_keeps_writable_runtime_bytes_and_forces_utf8(self):
        runtime = self.root / 'python'
        venv.EnvBuilder(with_pip=False, symlinks=sys.platform != 'win32').create(runtime)
        python = runtime / ('Scripts/python.exe' if sys.platform == 'win32' else 'bin/python')
        site_packages = Path(subprocess.check_output(
            [str(python), '-I', '-c', 'import sysconfig; print(sysconfig.get_path("purelib"))'], text=True,
        ).strip())
        for name in ('bs4', 'markdown', 'reportlab', 'pypdf'):
            (site_packages / (name + '.py')).write_text('# probe fixture\n', encoding='utf-8')
        (site_packages / 'docx.py').write_text(
            'import sys\n'
            'def Document():\n'
            '    if not sys.dont_write_bytecode or sys.flags.utf8_mode != 1:\n'
            '        raise OSError("Probe must disable bytecode and enable UTF-8")\n', encoding='utf-8',
        )
        before = {p.relative_to(runtime): p.read_bytes() for p in runtime.rglob('*') if p.is_file()}
        code = r"""
require $argv[1].'/portable/src/Installation.php';
$probe = new ReflectionMethod(\Yanji\Portable\Installation::class, 'probePython');
echo json_encode($probe->invoke(null, $argv[2]), JSON_THROW_ON_ERROR);
"""
        for _ in range(2):
            result = subprocess.run(['php', '-r', code, str(ROOT), str(python)], capture_output=True, text=True, check=True)
            self.assertTrue(json.loads(result.stdout)['basic'], 'Probe must pass with -B and explicit UTF-8 in isolated mode')
            after = {p.relative_to(runtime): p.read_bytes() for p in runtime.rglob('*') if p.is_file()}
            self.assertEqual(before, after, 'Python probing modified the writable runtime inventory')

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

    def test_windows_x86_64_builds_verified_zip_with_python_fonts_and_dlls(self):
        source = self.root / 'src'
        source.mkdir()
        for name, data in {
            'composer.lock': '{"packages":[]}',
            'package-lock.json': '{"packages":{"":{}}}',
            'scripts/install-local.sh': '#!/bin/sh\n',
            'scripts/install-local.ps1': '# windows installer\n',
            'VERSION': '0.1.0\n',
        }.items():
            t = source / name
            t.parent.mkdir(parents=True, exist_ok=True)
            t.write_text(data, encoding='utf-8')
        assets = self.root / 'assets'
        assets.mkdir()
        (assets / 'manifest.json').write_text('{}', encoding='utf-8')
        vendor = self.root / 'vendor'
        (vendor / 'composer').mkdir(parents=True)
        (vendor / 'composer/installed.json').write_text('{"dev":false,"packages":[]}', encoding='utf-8')
        notices = self.root / 'npm'
        notices.mkdir()
        (notices / 'inventory.json').write_text('[]', encoding='utf-8')
        skills = self.root / 'skills'
        skills.mkdir()
        for name in ('registry/skills.json', 'LICENSE'):
            t = skills / name
            t.parent.mkdir(parents=True, exist_ok=True)
            t.write_text('fixture', encoding='utf-8')
        lock = {
            'files': {},
            'registry_sha256': package.digest(skills / 'registry/skills.json'),
            'license_sha256': package.digest(skills / 'LICENSE'),
            'upstream_commit': '1' * 40,
        }
        (source / 'resources/skills').mkdir(parents=True)
        (source / 'resources/skills/upstream-lock.json').write_text(json.dumps(lock), encoding='utf-8')
        for git_args in (['init', '-q'], ['add', '.'], ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com', 'commit', '-qm', 'Fixture']):
            subprocess.run(['git', *git_args], cwd=source, check=True, capture_output=True)

        cli = self.root / 'php-windows-x86_64.tar.gz'
        write_tar(cli, {'runtime/bin/php.exe': b'win-php', 'runtime/bin/php8ts.dll': b'tsdll', 'runtime/ca/cacert.pem': b'ca'})
        cli_kit = self.root / 'php-windows-x86_64-source-relink.tar.gz'
        write_tar(cli_kit, {'LICENSE': b'php'})
        web_kit = self.root / 'web-windows-x86_64-source-relink.tar.gz'
        write_tar(web_kit, {'LICENSE': b'web'})
        web_rt = self.root / 'web-windows-x86_64-runtime.tar.gz'
        manifest = {
            'binary_sha256': hashlib.sha256(b'win-frankenphp').hexdigest(),
            'source_kit': {'name': web_kit.name, 'sha256': package.digest(web_kit)},
        }
        write_tar(web_rt, {
            'web/bin/frankenphp.exe': b'win-frankenphp',
            'web/bin/brotlienc.dll': b'brotli-dll',
            'web/build-manifest.json': json.dumps(manifest).encode(),
            'web/notices/LICENSE': b'web-license',
        })
        py_rt = self._valid_python_tar('windows-x86_64')
        fonts = self._valid_fonts_tar()
        out = self.root / 'out-win'
        args = argparse.Namespace(
            platform='windows-x86_64', source=source, vendor=vendor, skills=skills, assets=assets,
            npm_licenses=notices, cli=cli, cli_sha256=package.digest(cli), cli_source_kit=cli_kit,
            cli_source_kit_sha256=package.digest(cli_kit), web_runtime=web_rt, web_runtime_sha256=package.digest(web_rt),
            web_source_kit=web_kit, web_source_kit_sha256=package.digest(web_kit),
            python_runtime=py_rt, python_runtime_sha256=package.digest(py_rt),
            fonts=fonts, fonts_sha256=package.digest(fonts),
            ca=None, ca_notice=None, ca_sha256=None, output=out,
        )
        win_info = '''fixture: go1.27.4
        dep github.com/caddyserver/caddy/v2 v2.11.9
        dep github.com/dunglas/frankenphp v1.13.6
        dep github.com/dunglas/caddy-cbrotli v1.0.1
        build -tags=nomercure,novulcain,nowatcher
        build CGO_ENABLED=1
        build GOOS=windows
        build GOARCH=amd64
'''
        with patch.object(package, 'APP_PATHS', ('VERSION', 'composer.lock', 'resources', 'scripts/install-local.ps1')), \
             patch.object(package, 'read_go_build_info', return_value=win_info):
            package.build(args)

        zip_path = out / 'yanji-windows-x86_64.zip'
        self.assertTrue(zip_path.is_file())
        with zipfile.ZipFile(zip_path) as zf:
            names = set(zf.namelist())
            for req in ('runtime/bin/php.exe', 'runtime/bin/frankenphp.exe', 'runtime/bin/brotlienc.dll',
                        'runtime/python/python.exe', 'runtime/fonts/DejaVuSans.ttf', 'PACKAGE-FILES.sha256', 'release.json'):
                self.assertIn(req, names)
            receipt = json.loads(zf.read('release.json'))
            self.assertEqual('windows-x86_64', receipt['platform'])
            self.assertEqual(package.digest(py_rt), receipt['python_runtime_sha256'])
            self.assertEqual(package.digest(fonts), receipt['fonts_sha256'])
        sums = out / 'SHA256SUMS'
        self.assertIn('yanji-windows-x86_64.zip', sums.read_text(encoding='utf-8'))

        # Verify one-line release bootstrap generator against the Windows-only SHA256SUMS
        boot_out = self.root / 'boot'
        res = bootstrap.generate('https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.0', sums, boot_out)
        sh_file = boot_out / 'get-yanji.sh'
        ps1_file = boot_out / 'get-yanji.ps1'
        self.assertFalse(sh_file.exists())
        self.assertTrue(ps1_file.is_file())
        ps1_text = ps1_file.read_text(encoding='utf-8')
        self.assertIn(res['manifest_sha256'], ps1_text)
        self.assertIn('& {', ps1_text)
        self.assertIn('WebException', ps1_text)
        self.assertNotIn('exit $LASTEXITCODE', ps1_text)
        with self.assertRaisesRegex(ValueError, 'HTTPS'):
            bootstrap.generate('http://example.com/release', sums, boot_out)

    def test_bootstrap_regeneration_removes_only_obsolete_platform_entry_points(self):
        sums = self.root / 'SHA256SUMS'
        output = self.root / 'boot'
        output.mkdir()
        (output / 'keep.txt').write_bytes(b'unrelated release material')
        windows = ['install-local.ps1', 'yanji-windows-x86_64.zip']
        unix = ['install-local.sh', 'yanji-linux-x86_64.tar.gz']
        for names, expected in (
            (windows + unix, {'get-yanji.sh', 'get-yanji.ps1'}),
            (windows, {'get-yanji.ps1'}),
            (unix, {'get-yanji.sh'}),
            (windows + unix, {'get-yanji.sh', 'get-yanji.ps1'}),
        ):
            with self.subTest(manifest=names):
                sums.write_text(''.join('0' * 64 + '  ' + name + '\n' for name in names), encoding='utf-8')
                result = bootstrap.generate('https://example.com/release', sums, output)
                self.assertEqual(expected | {'keep.txt'}, {p.name for p in output.iterdir()})
                self.assertEqual(expected, set(result) - {'manifest_sha256'})
                for name in expected:
                    self.assertIn(result['manifest_sha256'], (output / name).read_text(encoding='utf-8'))
                self.assertEqual(b'unrelated release material', (output / 'keep.txt').read_bytes())

    def test_merge_release_dirs_unifies_multi_platform_sums_and_rejects_conflicts(self):
        win_dir = self.root / 'cand-win'
        mac_dir = self.root / 'cand-mac'
        win_dir.mkdir()
        mac_dir.mkdir()

        def write_cand(d: Path, files: dict[str, bytes]) -> None:
            lines = []
            for name, content in sorted(files.items()):
                p = d / name
                p.write_bytes(content)
                lines.append(f'{hashlib.sha256(content).hexdigest()}  {name}\n')
            (d / 'SHA256SUMS').write_text(''.join(lines), encoding='utf-8')

        write_cand(win_dir, {
            'install-local.ps1': b'# win installer\r\n',
            'yanji-windows-x86_64.zip': b'win-zip-bytes',
        })
        write_cand(mac_dir, {
            'install-local.sh': b'#!/bin/sh\necho ok\n',
            'yanji-macos-arm64.tar.gz': b'mac-tar-bytes',
        })

        # Windows-only merge works without requiring install-local.sh
        win_only = self.root / 'merged-win-only'
        win_res = merger.merge_release_dirs([win_dir], win_only, 'https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.0')
        self.assertTrue((win_only / 'get-yanji.ps1').is_file())
        self.assertFalse((win_only / 'get-yanji.sh').exists())
        self.assertIn(win_res['manifest_sha256'], (win_only / 'get-yanji.ps1').read_text(encoding='utf-8'))

        # Combined Windows + macOS merge generates both pinned bootstrappers
        combined = self.root / 'merged-all'
        all_res = merger.merge_release_dirs([win_dir, mac_dir], combined, 'https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.0')
        self.assertEqual(
            ['install-local.ps1', 'install-local.sh', 'yanji-macos-arm64.tar.gz', 'yanji-windows-x86_64.zip'],
            all_res['files'],
        )
        self.assertTrue((combined / 'get-yanji.sh').is_file() and (combined / 'get-yanji.ps1').is_file())
        sh_text = (combined / 'get-yanji.sh').read_text(encoding='utf-8')
        self.assertIn(all_res['manifest_sha256'], sh_text)
        self.assertNotIn('exec /bin/sh', sh_text)
        self.assertIn(all_res['manifest_sha256'], (combined / 'get-yanji.ps1').read_text(encoding='utf-8'))
        subprocess.run(['sh', '-n', str(combined / 'get-yanji.sh')], check=True)

        # Conflicting shared filename with different content must be rejected
        conflict_dir = self.root / 'cand-conflict'
        conflict_dir.mkdir()
        write_cand(conflict_dir, {'install-local.ps1': b'# tampered installer\r\n'})
        with self.assertRaisesRegex(ValueError, 'Conflicting content'):
            merger.merge_release_dirs([win_dir, conflict_dir], self.root / 'merged-conflict')

        # Tampered file mismatching input SHA256SUMS must be rejected
        (win_dir / 'yanji-windows-x86_64.zip').write_bytes(b'corrupted')
        with self.assertRaisesRegex(ValueError, 'SHA-256 mismatch'):
            merger.merge_release_dirs([win_dir], self.root / 'merged-tampered')


if __name__ == '__main__':
    unittest.main()
