"""Exercise the real Unix HTTPS installer and generated bootstrap without a network or install."""
import hashlib
import os
from pathlib import Path
import platform
import runpy
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
BASE_URL = 'https://example.com/releases/v0.1.1'
BAD_PACKAGE = b'corrupted-package-fixture\n'
EXPECTED_PACKAGE = b'authenticated-package-fixture\n'

# No network fallback: only these exact fixture URLs and HTTPS transport arguments are accepted.
FAKE_CURL = r'''#!/bin/sh
set -eu
output= url= proto= redirect= timeout= fail= location=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --fail) fail=1; shift;;
        --location) location=1; shift;;
        --silent|--show-error) shift;;
        --output) output=$2; shift 2;;
        --proto) proto=$2; shift 2;;
        --proto-redir) redirect=$2; shift 2;;
        --connect-timeout) timeout=$2; shift 2;;
        https://*) [ -z "$url" ]; url=$1; shift;;
        *) exit 91;;
    esac
done
[ "$fail:$location:$proto:$redirect:$timeout" = '1:1:=https:=https:30' ]
[ -n "$output" ]
case "$url" in
    https://example.com/releases/v0.1.1/install-local.sh) name=install-local.sh;;
    https://example.com/releases/v0.1.1/SHA256SUMS) name=SHA256SUMS;;
    "https://example.com/releases/v0.1.1/$YANJI_DOWNLOAD_NAME") name=$YANJI_DOWNLOAD_NAME;;
    *) exit 92;;
esac
cp "$YANJI_DOWNLOAD_FIXTURE/$name" "$output"
printf '%s\n' "$name" >> "$YANJI_DOWNLOAD_EVENTS"
'''


def utf8_locales():
    # macOS /bin/sh is bash 3.2: both locales must exercise its multibyte variable parsing.
    return ('en_US.UTF-8', 'zh_CN.UTF-8') if platform.system() == 'Darwin' else ('C.UTF-8',)


def archive_name():
    target = {
        ('Darwin', 'arm64'): 'macos-arm64',
        ('Linux', 'aarch64'): 'linux-aarch64',
        ('Linux', 'arm64'): 'linux-aarch64',
        ('Linux', 'x86_64'): 'linux-x86_64',
    }.get((platform.system(), platform.machine()))
    if target is None:
        raise AssertionError('Unix download regression requires a supported native Unix platform')
    return f'yanji-{target}.tar.gz'


def download_attempt(installer: Path, locale_name: str, *, bootstrap=False, local=False,
                     corrupt_manifest=False, corrupt_script_digest=False):
    """Run authenticated entry points until the intentionally bad archive is rejected."""
    name = archive_name()
    with tempfile.TemporaryDirectory(prefix='yanji-download-fixture-') as directory:
        work = Path(directory)
        materials = work / 'materials'
        materials.mkdir()
        script = materials / 'install-local.sh'
        script.write_bytes(installer.read_bytes())
        (materials / name).write_bytes(BAD_PACKAGE)
        script_sha = hashlib.sha256(script.read_bytes()).hexdigest()
        if corrupt_script_digest:
            script_sha = hashlib.sha256(b'different-installer-fixture\n').hexdigest()
        sums = materials / 'SHA256SUMS'
        sums.write_bytes((f'{script_sha}  install-local.sh\n'
                          f'{hashlib.sha256(EXPECTED_PACKAGE).hexdigest()}  {name}\n').encode('ascii'))
        pin = hashlib.sha256(sums.read_bytes()).hexdigest()
        if bootstrap:
            generator = runpy.run_path(str(ROOT / 'scripts/runtime/make-release-bootstrap.py'))
            generator['generate'](BASE_URL, sums, work / 'bootstrap')
            command = ['/bin/sh', str(work / 'bootstrap/get-yanji.sh')]
        else:
            command = ['/bin/sh', str(script), '--manifest', BASE_URL + '/SHA256SUMS',
                       '--manifest-sha256', pin]
        if corrupt_manifest:
            sums.write_bytes(sums.read_bytes() + b'\n')
        if local:
            command.extend(['--package', str(materials / name)])
        program, instance = work / 'program', work / 'instance'
        command.extend(['--program-home', str(program), '--instance', str(instance), '--no-browser'])
        fake_bin = work / 'bin'
        fake_bin.mkdir()
        curl = fake_bin / 'curl'
        curl.write_bytes(FAKE_CURL.encode('ascii'))
        curl.chmod(0o700)
        events = work / 'requests'
        environment = dict(os.environ, PATH=str(fake_bin) + os.pathsep + os.environ.get('PATH', ''),
                           LC_ALL=locale_name, LANG=locale_name, YANJI_DOWNLOAD_FIXTURE=str(materials),
                           YANJI_DOWNLOAD_NAME=name, YANJI_DOWNLOAD_EVENTS=str(events))
        if locale_name != 'C':
            charmap = subprocess.run(['locale', 'charmap'], env=environment, capture_output=True,
                                     text=True, encoding='utf-8', check=True, timeout=10)
            if charmap.stdout.strip().upper().replace('-', '') != 'UTF8' or charmap.stderr:
                raise AssertionError('Unix download regression requires an active UTF-8 locale')
        result = subprocess.run(command, env=environment, input='', capture_output=True, text=True,
                                encoding='utf-8', errors='replace', timeout=20)
        if program.exists() or instance.exists():
            raise AssertionError('Bad archive unexpectedly reached program or instance creation')
        return {'exit_code': result.returncode, 'stdout': result.stdout, 'stderr': result.stderr,
                'downloads': events.read_text(encoding='ascii').splitlines() if events.exists() else [],
                'name': name}


def assert_checksum_rejection(attempt, *, bootstrap=False, local=False):
    expected = (['install-local.sh'] if bootstrap else []) + ['SHA256SUMS']
    if not local:
        expected.append(attempt['name'])
    if attempt['downloads'] != expected:
        raise AssertionError('Authenticated installer did not finish the expected fixture downloads')
    progress = (f"正在读取本地安装包 {attempt['name']}..." if local else
                f"正在下载安装包 {attempt['name']}（文件较大，请稍候）...")
    if progress not in attempt['stderr']:
        raise AssertionError('Installer progress did not preserve the complete archive filename')
    if attempt['exit_code'] != 1 or attempt['stdout']:
        raise AssertionError('Bad archive did not exit cleanly at checksum rejection')
    if 'unbound' in attempt['stderr'] or not attempt['stderr'].endswith('安装包校验失败。\n'):
        raise AssertionError('Installer failed before the expected archive checksum rejection')


def verify_installer_download(installer: Path):
    for locale_name in utf8_locales():
        assert_checksum_rejection(download_attempt(installer, locale_name))
        assert_checksum_rejection(download_attempt(installer, locale_name, bootstrap=True), bootstrap=True)
    return {'unix_https_download_utf8': True, 'unix_bootstrap_download_utf8': True}


@unittest.skipIf(os.name == 'nt', 'Unix installer runs in native Unix acceptance jobs')
class UnixInstallerDownloadTest(unittest.TestCase):
    installer = ROOT / 'scripts/install-local.sh'

    def test_https_installer_and_generated_bootstrap_preserve_utf8_download_progress(self):
        self.assertEqual(verify_installer_download(self.installer),
                         {'unix_https_download_utf8': True, 'unix_bootstrap_download_utf8': True})

    def test_local_archive_progress_preserves_the_complete_filename(self):
        for locale_name in utf8_locales():
            with self.subTest(locale=locale_name):
                assert_checksum_rejection(download_attempt(self.installer, locale_name, local=True), local=True)

    def test_real_manifest_pin_stops_changed_materials_before_package_download(self):
        for bootstrap in (False, True):
            with self.subTest(bootstrap=bootstrap):
                result = download_attempt(self.installer, utf8_locales()[0], bootstrap=bootstrap,
                                          corrupt_manifest=True)
                self.assertEqual(result['exit_code'], 1)
                self.assertEqual(result['downloads'],
                                 (['install-local.sh'] if bootstrap else []) + ['SHA256SUMS'])
                self.assertTrue(result['stderr'].endswith('发行清单校验失败。\n'))
                self.assertNotIn('unbound', result['stderr'])

    def test_real_script_digest_stops_untrusted_installer_before_package_download(self):
        for bootstrap in (False, True):
            with self.subTest(bootstrap=bootstrap):
                result = download_attempt(self.installer, utf8_locales()[0], bootstrap=bootstrap,
                                          corrupt_script_digest=True)
                self.assertEqual(result['exit_code'], 1)
                self.assertEqual(result['downloads'], ['install-local.sh'] if bootstrap else ['SHA256SUMS'])
                self.assertTrue(result['stderr'].endswith('安装脚本校验失败。\n') if bootstrap else
                                result['stderr'].endswith('请先将匹配清单的安装脚本保存到本地，再执行。\n'))
                self.assertNotIn('unbound', result['stderr'])


if __name__ == '__main__':
    unittest.main()
