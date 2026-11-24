"""A portable release must not silently ship an unaccounted Web executable."""
import argparse
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('portable_package', Path(__file__).resolve().parents[2] / 'scripts/build-local-package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)
BUILD_RECIPE = Path(__file__).resolve().parents[2] / 'scripts/runtime/build-web-go.sh'


def archive(path, files):
    with tarfile.open(path, 'w:gz') as output:
        for name, value in files.items():
            item = tarfile.TarInfo(name)
            item.size = len(value)
            item.mode = 0o755 if name.endswith(('/php', '/frankenphp')) else 0o644
            output.addfile(item, io.BytesIO(value))


INFO = '''fixture: go1.27.4
        dep github.com/caddyserver/caddy/v2 v2.11.9
        dep github.com/dunglas/frankenphp v1.13.6
        dep github.com/dunglas/caddy-cbrotli v1.0.1
        build -tags=nomercure,novulcain,nowatcher
        build CGO_ENABLED=1
        build GOOS=linux
        build GOARCH=arm64
'''


class PortableWebMaterialsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='yanji-package-test-')
        self.root = Path(self.temporary.name)
        source = self.root / 'source'
        source.mkdir()
        for name, data in {
            'composer.lock': '{"packages":[]}', 'package-lock.json': '{"packages":{"":{}}}',
            'scripts/install-local.sh': '#!/bin/sh\n', 'scripts/install-local.ps1': '# fixture\n',
            'VERSION': 'test\n',
        }.items():
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(data)
        assets = self.root / 'assets'
        assets.mkdir()
        (assets / 'manifest.json').write_text('{}')
        vendor = self.root / 'vendor'
        (vendor / 'composer').mkdir(parents=True)
        (vendor / 'composer/installed.json').write_text('{"dev":false,"packages":[]}')
        notices = self.root / 'npm'
        notices.mkdir()
        (notices / 'inventory.json').write_text('[]')
        skills = self.root / 'skills'
        skills.mkdir()
        for name in ['registry/skills.json', 'LICENSE']:
            target = skills / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('fixture')
        lock = {'files': {}, 'registry_sha256': package.digest(skills / 'registry/skills.json'),
                'license_sha256': package.digest(skills / 'LICENSE'), 'upstream_commit': '1' * 40}
        (source / 'resources/skills').mkdir(parents=True)
        (source / 'resources/skills/upstream-lock.json').write_text(json.dumps(lock))
        for args in [['init', '-q'], ['add', '.'], ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com',
                                                   'commit', '-qm', 'Fixture']]:
            subprocess.run(['git', *args], cwd=source, check=True, capture_output=True)
        cli = self.root / 'cli.tar.gz'
        archive(cli, {'runtime/bin/php': b'fixture cli', 'runtime/ca/cacert.pem': b'fixture ca'})
        web = self.root / 'frankenphp'
        web.write_bytes(b'legacy unaccounted Web')
        cli_kit = self.root / 'php-linux-aarch64-source-relink.tar.gz'
        archive(cli_kit, {'LICENSE': b'fixture'})
        web_kit = self.root / 'web-linux-aarch64-source-relink.tar.gz'
        archive(web_kit, {'LICENSE': b'fixture web source'})
        runtime = self.root / 'web-runtime.tar.gz'
        manifest = {'binary_sha256': hashlib.sha256(b'fixture web').hexdigest(),
                    'source_kit': {'name': web_kit.name, 'sha256': package.digest(web_kit)}}
        archive(runtime, {'web/bin/frankenphp': b'fixture web', 'web/build-manifest.json': json.dumps(manifest).encode(),
                          'web/notices/LICENSE': b'fixture notice'})
        self.args = argparse.Namespace(source=source, vendor=vendor, skills=skills, assets=assets,
            npm_licenses=notices, cli=cli, cli_sha256=package.digest(cli), cli_source_kit=cli_kit,
            cli_source_kit_sha256=package.digest(cli_kit), web=web, web_sha256=package.digest(web),
            web_runtime=runtime, web_runtime_sha256=package.digest(runtime), web_source_kit=web_kit,
            web_source_kit_sha256=package.digest(web_kit), platform='linux-aarch64', output=self.root / 'output')

    def tearDown(self):
        self.temporary.cleanup()

    def build(self):
        with patch.object(package, 'APP_PATHS', ('VERSION', 'composer.lock', 'resources')):
            package.build(self.args)

    def test_raw_web_without_licenses_and_source_is_rejected_before_publishing(self):
        self.args.web_runtime = None
        self.args.web_source_kit = None
        with self.assertRaisesRegex(ValueError, 'Web runtime.*corresponding-source'):
            self.build()
        self.assertFalse(self.args.output.exists())

    def test_release_carries_web_notices_and_pins_both_source_kits(self):
        with patch.object(package, 'read_go_build_info', return_value=INFO):
            self.build()
        with tarfile.open(self.args.output / 'yanji-linux-aarch64.tar.gz') as built:
            self.assertEqual(b'fixture cli', built.extractfile('runtime/bin/php').read())
            self.assertEqual(b'fixture web', built.extractfile('runtime/bin/frankenphp').read())
            self.assertEqual(b'fixture notice', built.extractfile('runtime/web/notices/LICENSE').read())
            receipt = json.load(built.extractfile('release.json'))
            self.assertEqual(hashlib.sha256(b'fixture web').hexdigest(), receipt['web_sha256'])
            self.assertEqual(self.args.web_source_kit_sha256, receipt['web_source_kit_sha256'])
            inventory = built.extractfile('PACKAGE-FILES.sha256').read().decode()
            self.assertIn('runtime/web/notices/LICENSE', inventory)
        manifest = (self.args.output / 'SHA256SUMS').read_text()
        for kit in (self.args.cli_source_kit, self.args.web_source_kit):
            self.assertEqual(kit.read_bytes(), (self.args.output / kit.name).read_bytes())
            self.assertIn(package.digest(kit) + '  ' + kit.name, manifest)

    def test_changed_corresponding_source_is_rejected_before_publishing(self):
        self.args.web_source_kit.write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'Web material digest mismatch'):
            self.build()
        self.assertFalse(self.args.output.exists())

    def test_a_valid_different_source_kit_cannot_be_paired_with_this_binary(self):
        other = self.root / 'other-web-linux-aarch64-source-relink.tar.gz'
        archive(other, {'LICENSE': b'different build with its own valid digest'})
        self.args.web_source_kit = other
        self.args.web_source_kit_sha256 = package.digest(other)
        with patch.object(package, 'read_go_build_info', return_value=INFO):
            with self.assertRaisesRegex(ValueError, 'are not paired'):
                self.build()
        self.assertFalse(self.args.output.exists())

    def test_standard_tar_dot_prefix_keeps_web_provenance_and_notices(self):
        with tarfile.open(self.args.web_runtime) as original:
            files = {m.name.replace('web/', './', 1): original.extractfile(m).read() for m in original}
        archive(self.args.web_runtime, files)
        self.args.web_runtime_sha256 = package.digest(self.args.web_runtime)
        with patch.object(package, 'read_go_build_info', return_value=INFO):
            self.build()
        with tarfile.open(self.args.output / 'yanji-linux-aarch64.tar.gz') as built:
            self.assertEqual(b'fixture notice', built.extractfile('runtime/web/notices/LICENSE').read())
            self.assertEqual(b'fixture web', built.extractfile('runtime/bin/frankenphp').read())

    def test_unsafe_web_archive_cannot_escape_its_namespace(self):
        for bad_name, kind in [('web/notices/../../../escape', tarfile.REGTYPE),
                               ('web/notices/link', tarfile.SYMTYPE),
                               ('web/notices/LICENSE', tarfile.REGTYPE)]:
            with self.subTest(path=bad_name, kind=kind):
                with tarfile.open(self.args.web_runtime, 'w:gz') as output:
                    for name, value in {'web/bin/frankenphp': b'fixture web',
                                        'web/build-manifest.json': b'{}',
                                        'web/notices/LICENSE': b'fixture notice'}.items():
                        item = tarfile.TarInfo(name)
                        item.mode = 0o755
                        item.size = len(value)
                        output.addfile(item, io.BytesIO(value))
                    item = tarfile.TarInfo(bad_name)
                    item.type = kind
                    item.linkname = '/etc/passwd' if kind == tarfile.SYMTYPE else ''
                    output.addfile(item)
                self.args.web_runtime_sha256 = package.digest(self.args.web_runtime)
                with self.assertRaisesRegex(ValueError, 'Unsafe|Duplicate'):
                    self.build()
                self.assertFalse(self.args.output.exists())

    def test_linked_agpl_modules_wrong_target_and_missing_tags_are_rejected(self):
        invalid = [INFO + '        dep github.com/dunglas/mercure v1.1.6\n',
                   INFO + '        dep github.com/dunglas/vulcain/caddy v1.4.6\n',
                   INFO.replace('GOARCH=arm64', 'GOARCH=amd64'),
                   INFO.replace('nomercure,novulcain,nowatcher', 'nowatcher'),
                   INFO.replace('go1.27.4', 'go1.27.1'),
                   INFO.replace('v2.11.9', 'v2.11.8'),
                   INFO.replace('v1.0.1', 'v1.0.0')]
        for info in invalid:
            with self.subTest(info=info), patch.object(package, 'read_go_build_info', return_value=info):
                with self.assertRaises(ValueError):
                    self.build()
                self.assertFalse(self.args.output.exists())

    def test_modified_upstream_locks_are_rejected_before_compiling(self):
        source = self.root / 'changed-upstream'
        (source / 'caddy').mkdir(parents=True)
        for name in ['go.mod', 'go.sum', 'caddy/go.mod', 'caddy/go.sum']:
            (source / name).write_text('modified lock\n')
        tools = self.root / 'tools'
        tools.mkdir()
        go = tools / 'go'
        go.write_text('''#!/bin/sh
case "$1:$2" in
    env:GOVERSION) printf 'go1.27.4\\n' ;;
    list:*) printf 'github.com/dunglas/caddy-cbrotli v1.0.1\\ngithub.com/caddyserver/caddy/v2 v2.11.9\\n' ;;
    build:*) touch "$WEB_TEST_MARKER" ;;
    *) exit 2 ;;
esac
''')
        go.chmod(0o700)
        marker = self.root / 'compiler-was-called'
        env = dict(os.environ, PATH=str(tools) + os.pathsep + os.environ['PATH'],
                   CGO_CFLAGS='fixture', CGO_LDFLAGS='fixture', WEB_TEST_MARKER=str(marker))
        result = subprocess.run(['sh', str(BUILD_RECIPE), str(source), str(self.root / 'unused-output')],
                                env=env, capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('upstream dependency lock changed', result.stderr)
        self.assertFalse(marker.exists())


if __name__ == '__main__':
    unittest.main()
