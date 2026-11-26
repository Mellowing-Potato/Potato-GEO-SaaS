"""Regression checks for Windows GBK/Latin-1 PowerShell parsing, curl proxy downloads, and root installers."""
import codecs
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import runpy
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class WindowsBootstrapAndRootInstallersTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.bootstrap_mod = runpy.run_path(str(ROOT / 'scripts/runtime/make-release-bootstrap.py'))
        cls.publish_mod = runpy.run_path(str(ROOT / 'scripts/runtime/publish-portable-release.py'))

    def test_raw_utf8_without_bom_corrupts_quotes_in_gbk_and_windows1252_while_generated_ps1_stays_pure_ascii(self):
        # Prove why the previous BOM-less UTF-8 get-yanji.ps1 failed on Chinese GBK PowerShell 5.1 and Latin-1 irm:
        legacy_line = 'throw "安装脚本返回失败（退出码 $LASTEXITCODE）。"'
        legacy_utf8 = legacy_line.encode('utf-8')
        self.assertEqual(legacy_utf8[-2:], b'\x82"', 'UTF-8 fullwidth period leaves a dangling CP936 lead byte 0x82 right before the closing quote')

        legacy_redirect = "if ($current -notmatch '^https://') { throw '远端安装脚本与重定向仅接受 HTTPS。' }"
        cp1252_decoded = legacy_redirect.encode('utf-8').decode('cp1252', errors='replace')
        self.assertIn('\u2018', cp1252_decoded, 'UTF-8 0x91 inside 向 becomes a PowerShell closing smart quote in CP1252')

        with tempfile.TemporaryDirectory(prefix='yanji-ps1-ascii-') as tmp:
            tmp_dir = Path(tmp)
            sums = tmp_dir / 'SHA256SUMS'
            installer_sha = '1' * 64
            pkg_sha = '2' * 64
            sums.write_text(f'{installer_sha}  install-local.ps1\n{pkg_sha}  yanji-windows-x86_64.zip\n', encoding='utf-8')
            base_url = 'https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1'
            info = self.bootstrap_mod['generate'](base_url, sums, tmp_dir)
            ps1_bytes = (tmp_dir / 'get-yanji.ps1').read_bytes()

            self.assertTrue(all(b < 128 for b in ps1_bytes), 'get-yanji.ps1 must contain only 7-bit ASCII bytes')
            ascii_text = ps1_bytes.decode('ascii')
            for encoding in ('utf-8', 'gbk', 'cp936', 'cp1252', 'latin1', 'big5'):
                self.assertEqual(ps1_bytes.decode(encoding), ascii_text)

            # Release publisher constant verification must still parse BaseUrl, ManifestSha256 and InstallerSha256.
            constants = self.publish_mod['bootstrap_constants'](ps1_bytes, powershell=True)
            self.assertEqual(constants, {
                'base': base_url,
                'manifest': info['manifest_sha256'],
                'installer': installer_sha,
            })

            # Unescaping Msg literals yields the expected Chinese diagnostics.
            escaped_literals = re.findall(r"Msg '([^']+)'", ascii_text)
            self.assertGreaterEqual(len(escaped_literals), 6)
            decoded_messages = [codecs.decode(lit, 'unicode_escape') for lit in escaped_literals]
            self.assertTrue(any('正在获取并校验言及安装脚本' in m for m in decoded_messages))
            self.assertTrue(any('退出码' in m for m in decoded_messages))

    def test_windows_scripts_keep_utf8_bom_or_pure_ascii_and_prefer_system32_curl_with_https_enforcement(self):
        install_local = (ROOT / 'scripts/install-local.ps1').read_bytes()
        self.assertTrue(install_local.startswith(b'\xef\xbb\xbf'), 'scripts/install-local.ps1 must retain UTF-8 BOM for PowerShell 5.1 -File')

        for rel in ('install.ps1', 'install.cmd'):
            raw = (ROOT / rel).read_bytes()
            self.assertTrue(all(b < 128 for b in raw), f'{rel} must stay pure 7-bit ASCII for all Windows codepages')

        for text in (
            install_local.decode('utf-8-sig'),
            (ROOT / 'install.ps1').read_text(encoding='ascii'),
        ):
            self.assertIn(r"System32\curl.exe", text)
            self.assertIn("'--proto', '=https'", text)
            self.assertIn("'--proto-redir', '=https'", text)
            self.assertIn('GetSystemWebProxy', text)
            self.assertIn('HTTPS_PROXY', text)

    def test_release_pin_matches_documented_https_release_and_sha256_format(self):
        pin = json.loads((ROOT / 'portable/release-pin.json').read_text(encoding='utf-8'))
        self.assertRegex(pin['tag'], r'^v\d+\.\d+\.\d+$')
        self.assertEqual(
            pin['base_url'],
            f"https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/{pin['tag']}",
        )
        self.assertRegex(pin['manifest_sha256'], r'^[a-f0-9]{64}$')
        self.assertIn(pin['manifest_sha256'], (ROOT / 'PORTABLE.md').read_text(encoding='utf-8'))

    @unittest.skipIf(os.name == 'nt', 'POSIX sh root installer runs on Unix hosts')
    def test_root_install_sh_reuses_already_initialized_matching_release_and_rejects_bad_manifest(self):
        arch_map = {
            ('Darwin', 'arm64'): 'yanji-macos-arm64.tar.gz',
            ('Linux', 'aarch64'): 'yanji-linux-aarch64.tar.gz',
            ('Linux', 'arm64'): 'yanji-linux-aarch64.tar.gz',
            ('Linux', 'x86_64'): 'yanji-linux-x86_64.tar.gz',
        }
        pkg_name = arch_map.get((platform.system(), platform.machine()))
        if pkg_name is None:
            self.skipTest('Unsupported Unix architecture for portable root installer test')

        with tempfile.TemporaryDirectory(prefix='yanji-root-sh-') as tmp:
            work = Path(tmp)
            home = work / 'home'
            base = (home / 'Library/Application Support/Yanji') if platform.system() == 'Darwin' else (home / '.local/share/yanji')
            pkg_sha = '3' * 64
            installer_bytes = (ROOT / 'scripts/install-local.sh').read_bytes()
            installer_sha = hashlib.sha256(installer_bytes).hexdigest()
            sums = work / 'SHA256SUMS'
            sums.write_bytes(f'{installer_sha}  install-local.sh\n{pkg_sha}  {pkg_name}\n'.encode('ascii'))
            sums_sha = hashlib.sha256(sums.read_bytes()).hexdigest()

            # Prepare an already-initialized matching portable instance to verify Day-2 instant launch without re-downloading.
            rel_dir = base / 'program/releases' / pkg_sha
            inst_dir = base / 'instance'
            rel_dir.mkdir(parents=True)
            inst_dir.mkdir(parents=True)
            (inst_dir / '.portable-initialized').write_text('verified\n', encoding='utf-8')
            (inst_dir / 'current').symlink_to(rel_dir)
            marker = work / 'launched.txt'
            yanji_bin = inst_dir / 'yanji'
            yanji_bin.write_text(f'#!/bin/sh\nprintf "%s\\n" "$*" > "{marker}"\n', encoding='utf-8')
            yanji_bin.chmod(0o700)

            env = dict(os.environ, HOME=str(home), XDG_DATA_HOME=str(home / '.local/share'))
            fast = subprocess.run(
                ['/bin/sh', str(ROOT / 'install.sh'), '--manifest', str(sums), '--manifest-sha256', sums_sha, '--no-browser'],
                env=env, capture_output=True, text=True, timeout=15,
            )
            self.assertEqual(fast.returncode, 0, fast.stderr)
            self.assertEqual(marker.read_text(encoding='utf-8').strip(), 'start --no-browser')

            # Tampered manifest SHA-256 must fail closed before launching anything.
            marker.unlink()
            bad = subprocess.run(
                ['/bin/sh', str(ROOT / 'install.sh'), '--manifest', str(sums), '--manifest-sha256', 'f' * 64],
                env=env, capture_output=True, text=True, timeout=15,
            )
            self.assertNotEqual(bad.returncode, 0)
            self.assertFalse(marker.exists())

            # Older initialized release at the default instance must automatically receive --upgrade instead of failing overwrite.
            old_rel_dir = base / 'program/releases' / ('1' * 64)
            old_rel_dir.mkdir(parents=True)
            (inst_dir / 'current').unlink()
            (inst_dir / 'current').symlink_to(old_rel_dir)
            fake_stage_dir = work / 'upgrade-feed'
            fake_stage_dir.mkdir()
            fake_installer = fake_stage_dir / 'install-local.sh'
            captured_args = work / 'upgrade-args.txt'
            fake_installer.write_text(
                f'#!/bin/sh\nprintf "%s\\n" "$@" > "{captured_args}"\n',
                encoding='utf-8',
            )
            fake_installer.chmod(0o700)
            fake_installer_sha = hashlib.sha256(fake_installer.read_bytes()).hexdigest()
            (fake_stage_dir / pkg_name).write_bytes(b'placeholder')
            up_sums = fake_stage_dir / 'SHA256SUMS'
            up_sums.write_bytes(
                f'{fake_installer_sha}  install-local.sh\n{pkg_sha}  {pkg_name}\n'.encode('ascii')
            )
            up_sums_sha = hashlib.sha256(up_sums.read_bytes()).hexdigest()
            up_res = subprocess.run(
                ['/bin/sh', str(ROOT / 'install.sh'), '--manifest', str(up_sums), '--manifest-sha256', up_sums_sha, '--no-browser'],
                env=env, capture_output=True, text=True, timeout=15,
            )
            self.assertEqual(up_res.returncode, 0, up_res.stderr)
            self.assertIn('自动备份并平滑升级', up_res.stderr)
            forwarded_lines = captured_args.read_text(encoding='utf-8').splitlines()
            self.assertIn('--upgrade', forwarded_lines)
            self.assertIn('--no-browser', forwarded_lines)

    def test_windows_detacher_and_root_installer_auto_upgrade_contracts(self):
        platform_php = (ROOT / 'portable/src/Platform.php').read_text(encoding='utf-8')
        # Verify 3-tier breakaway from strict AI-agent Windows Job Objects (KILL_ON_JOB_CLOSE):
        self.assertIn('0x09000200u', platform_php, 'Tier 1 must attempt CREATE_BREAKAWAY_FROM_JOB')
        self.assertIn('Win32_Process', platform_php, 'Tier 2 must escape non-breakaway Job Objects via WMI Win32_Process')
        self.assertIn('0x08000200u', platform_php, 'Tier 3 must retain standard detached CreateProcessW fallback')

        root_ps1 = (ROOT / 'install.ps1').read_text(encoding='ascii')
        self.assertIn('$Upgrade = [switch]::Present', root_ps1)


if __name__ == '__main__':
    unittest.main()
