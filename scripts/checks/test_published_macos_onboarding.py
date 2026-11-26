"""Offline guards and mocked interfaces for published reader installation; never install locally."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import MagicMock, patch


SOURCE = Path(__file__).resolve().parents[1] / 'test-published-macos-onboarding.py'
SPEC = importlib.util.spec_from_file_location('published_macos_onboarding', SOURCE)
onboarding = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(onboarding)
VERSION = 'v0.1.1'
COMMIT = 'a' * 40


class PublishedMacosOnboardingTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='yanji-reader-interface-')
        self.addCleanup(self.temporary.cleanup)
        self.home = Path(self.temporary.name)
        self.base = self.home / 'Library' / 'Application Support' / 'Yanji'
        environment = {
            'GITHUB_ACTIONS': 'true', 'RUNNER_OS': 'macOS',
            'RUNNER_ENVIRONMENT': 'github-hosted', 'GITHUB_REPOSITORY': onboarding.REPOSITORY,
            'GITHUB_REF_TYPE': 'tag', 'GITHUB_REF_NAME': VERSION, 'GITHUB_REF': 'refs/tags/' + VERSION,
            'GITHUB_SHA': COMMIT,
        }
        self.enterContext(patch.dict(os.environ, environment))
        self.enterContext(patch.object(onboarding.Path, 'home', return_value=self.home))
        self.enterContext(patch.object(onboarding.sys, 'platform', 'darwin'))
        self.enterContext(patch('pwd.getpwuid', return_value=SimpleNamespace(pw_dir=str(self.home))))
        self.socket = self.enterContext(patch.object(onboarding.socket, 'socket'))

    def fixture_installation(self):
        instance = self.base / 'instance'
        release = self.base / 'program' / 'releases' / ('a' * 64)
        (release / 'app').mkdir(parents=True)
        instance.mkdir(parents=True, exist_ok=True)
        (instance / 'yanji').write_bytes(b'# synthetic launcher, never executed\n')
        (instance / 'current').symlink_to(release, target_is_directory=True)
        (instance / 'instance.json').write_text(json.dumps({'port': 8000}))
        (release / 'release.json').write_text(json.dumps({'platform': 'macos-arm64', 'commit': COMMIT}))
        (release / 'app' / 'VERSION').write_text(VERSION[1:] + '\n')
        return instance, release

    def processes(self, curl_exit=0, sh_exit=0):
        curl, shell = MagicMock(), MagicMock()
        curl.stdout = io.BytesIO(b'# synthetic bootstrap; never executed\n')
        for process, status in ((curl, curl_exit), (shell, sh_exit)):
            process.returncode = status
            process.poll.return_value = status
        return curl, shell

    def test_non_ci_invocation_rejects_before_any_process_or_filesystem_installation(self):
        os.environ.pop('GITHUB_ACTIONS')
        with patch.object(onboarding.subprocess, 'Popen') as popen, patch.object(onboarding, 'stop_instance') as stop:
            for scenario in ('fresh', 'interrupted'):
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    status = onboarding.main(['--version', VERSION, '--scenario', scenario])
                self.assertEqual(status, 1)
                self.assertEqual(json.loads(output.getvalue()), {
                    'published_reader_entry': 'failed', 'code': 'fresh_github_hosted_macos_required'})
                self.assertNotIn(str(self.home), output.getvalue())
        self.assertFalse(self.base.exists())
        self.socket.assert_not_called()
        popen.assert_not_called()
        stop.assert_not_called()

    def test_wrong_platform_self_hosted_repository_or_tag_ref_is_rejected(self):
        for key, value in [('RUNNER_OS', 'Linux'), ('RUNNER_ENVIRONMENT', 'self-hosted'),
                           ('GITHUB_REPOSITORY', 'example/project'), ('GITHUB_REF_TYPE', 'branch'),
                           ('GITHUB_REF_NAME', 'v0.0.0'), ('GITHUB_REF', 'refs/heads/main')]:
            with self.subTest(key=key), patch.dict(os.environ, {key: value}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'fresh_github_hosted_macos_required'):
                    onboarding.preflight(VERSION)
        with patch.object(onboarding.sys, 'platform', 'linux'):
            with self.assertRaises(onboarding.AcceptanceError):
                onboarding.preflight(VERSION)
        self.socket.assert_not_called()

    def test_existing_default_directory_and_broken_link_cannot_be_installed_or_stopped(self):
        for linked in (False, True):
            with self.subTest(linked=linked):
                self.base.parent.mkdir(parents=True, exist_ok=True)
                if linked:
                    self.base.symlink_to(self.home / 'missing')
                else:
                    self.base.mkdir()
                with patch.object(onboarding.subprocess, 'Popen') as popen, patch.object(onboarding, 'stop_instance') as stop:
                    with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_yanji_directory_already_exists'):
                        onboarding.run_acceptance(VERSION)
                    with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_yanji_directory_already_exists'):
                        onboarding.run_acceptance(VERSION, 'interrupted')
                    popen.assert_not_called()
                    stop.assert_not_called()
                self.base.unlink() if linked else self.base.rmdir()
        self.socket.assert_not_called()

    def test_overridden_home_is_rejected_before_binding_or_installing(self):
        with patch('pwd.getpwuid', return_value=SimpleNamespace(pw_dir='/Users/example')):
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_runner_home_required'):
                onboarding.preflight(VERSION)
        self.socket.assert_not_called()

    def test_default_port_must_be_free_and_only_approved_url_is_constructed(self):
        self.assertEqual(onboarding.preflight(VERSION), self.base)
        self.socket.return_value.__enter__.return_value.bind.assert_called_once_with(('127.0.0.1', 8000))
        self.socket.return_value.__enter__.return_value.bind.side_effect = OSError('synthetic occupied port')
        with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_port_already_in_use'):
            onboarding.preflight(VERSION)
        self.assertEqual(onboarding.release_url(VERSION),
                         'https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1/get-yanji.sh')
        for version in ('../v0.1.1', 'v0.1.1;echo test', 'https://example.com/test', 'v0.1.1\n'):
            with self.subTest(version=version), self.assertRaises(onboarding.AcceptanceError):
                onboarding.release_url(version)

    def test_real_pipeline_interface_preserves_reader_command_utf8_and_no_terminal(self):
        curl, shell = self.processes()
        with patch.object(onboarding.subprocess, 'Popen', side_effect=[curl, shell]) as popen:
            self.assertEqual(onboarding.run_published_pipeline(VERSION), {'curl_exit': 0, 'sh_exit': 0})
        first, second = popen.call_args_list
        self.assertEqual(first.args[0], ['curl', '-fsSL', onboarding.release_url(VERSION)])
        self.assertEqual(second.args[0], ['/bin/sh'])
        self.assertEqual(first.kwargs['stdin'], subprocess.DEVNULL)
        self.assertIs(second.kwargs['stdin'], curl.stdout)
        for call in (first, second):
            self.assertIs(call.kwargs['start_new_session'], True)
            self.assertEqual(call.kwargs['env']['LANG'], 'en_US.UTF-8')
            self.assertEqual(call.kwargs['env']['LC_ALL'], 'en_US.UTF-8')
            self.assertEqual(call.kwargs['env'], dict(os.environ, LANG='en_US.UTF-8', LC_ALL='en_US.UTF-8'))
            self.assertEqual('HOME' in call.kwargs['env'], 'HOME' in os.environ)
            self.assertEqual(call.kwargs['env'].get('HOME'), os.environ.get('HOME'))
        self.assertTrue(curl.stdout.closed)
        shell.wait.assert_called_once_with(timeout=900)
        curl.wait.assert_called_once_with(timeout=30)

    def test_both_pipeline_exit_statuses_are_checked_including_empty_download(self):
        for curl_exit, shell_exit, code in [(22, 0, 'published_bootstrap_download_failed'),
                                            (0, 1, 'published_reader_installation_failed')]:
            with self.subTest(curl=curl_exit, sh=shell_exit):
                processes = self.processes(curl_exit, shell_exit)
                with patch.object(onboarding.subprocess, 'Popen', side_effect=processes):
                    with self.assertRaisesRegex(onboarding.AcceptanceError, code):
                        onboarding.run_published_pipeline(VERSION)

    def test_installed_version_platform_and_default_port_are_read_from_actual_release(self):
        instance, release = self.fixture_installation()
        self.assertEqual(onboarding.check_installed_release(self.base, VERSION), instance)
        for path, bad, original, code in [
            (release / 'app' / 'VERSION', '0.0.0\n', VERSION[1:] + '\n', 'installed_version_differs_from_release_tag'),
            (release / 'release.json', '{"platform":"linux-x86_64"}', json.dumps({'platform': 'macos-arm64', 'commit': COMMIT}), 'installed_platform_is_not_macos_arm64'),
            (instance / 'instance.json', '{"port":8001}', '{"port":8000}', 'installed_instance_did_not_use_default_port'),
        ]:
            with self.subTest(code=code):
                path.write_text(bad)
                with self.assertRaisesRegex(onboarding.AcceptanceError, code):
                    onboarding.check_installed_release(self.base, VERSION)
                path.write_text(original)

    def test_same_version_old_source_and_missing_source_are_rejected(self):
        _, release = self.fixture_installation()
        for commit in ('b' * 40, '', None):
            with self.subTest(commit=commit):
                (release / 'release.json').write_text(json.dumps({'platform': 'macos-arm64', 'commit': commit}))
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'installed_source_differs_from_release_run'):
                    onboarding.check_installed_release(self.base, VERSION)
        for value in ('', 'not-a-commit'):
            with self.subTest(environment=value), patch.dict(os.environ, {'GITHUB_SHA': value}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'fixed_release_source_required'):
                    onboarding.preflight(VERSION)

    def test_services_require_explicit_ready_and_all_three_healthy_roles(self):
        record = {'ready': True, 'writes_enabled': True,
                  'services': {role: {'healthy': True} for role in ('web', 'worker', 'scheduler')}}
        with patch.object(onboarding.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=json.dumps(record).encode())):
            onboarding.check_services(self.base / 'instance')
        for invalid in ({}, {'ready': True, 'writes_enabled': True, 'services': {}},
                        dict(record, ready=1), dict(record, writes_enabled=False),
                        dict(record, services={'web': {'healthy': True}}),
                        dict(record, services={role: {'healthy': 1} for role in ('web', 'worker', 'scheduler')})):
            with self.subTest(record=invalid), patch.object(onboarding.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=json.dumps(invalid).encode())):
                with self.assertRaises(onboarding.AcceptanceError):
                    onboarding.check_services(self.base / 'instance')

    def test_success_reuses_cookie_api_helper_and_always_stops_new_instance(self):
        instance, _ = self.fixture_installation()
        authorize = MagicMock(return_value={'user_id': 1, 'tenant_id': 1})
        marker_writer = MagicMock(side_effect=lambda path: path.write_bytes(b'desktop-fixture\n'))
        onboarding_helper = MagicMock()
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'curl_exit': 0, 'sh_exit': 0}), \
             patch.object(onboarding, 'check_services') as services, \
             patch.object(onboarding, 'load_desktop_helpers', return_value={
                 'write_acceptance_marker': marker_writer, 'authorize_and_check': authorize,
                 'onboarding_and_check': onboarding_helper}), \
             patch.object(onboarding, 'stop_instance') as stop:
            result = onboarding.run_acceptance(VERSION)
        onboarding_helper.assert_called_once_with(instance, 8000)
        authorize.assert_called_once_with(instance, 8000)
        marker_writer.assert_called_once_with(instance / '.portable-acceptance')
        services.assert_called_once_with(instance)
        stop.assert_called_once_with(instance)
        self.assertEqual((instance / '.portable-acceptance').stat().st_mode & 0o777, 0o600)
        self.assertEqual(result['published_reader_entry'], 'passed')
        self.assertEqual(result['browser_gui'], 'not_verified')
        self.assertNotIn(str(self.home), json.dumps(result))

    def test_interrupted_fixture_is_exactly_unkeyed_empty_and_private(self):
        onboarding.prepare_interrupted_fixture(self.base)
        instance = self.base / 'instance'
        database = instance / 'database' / 'database.sqlite'
        environment = (instance / '.env').read_text()
        self.assertEqual(environment.count('\nAPP_KEY=\n'), 1)
        self.assertNotIn('YANJI_DESKTOP_MODE=', environment)
        self.assertIn('DB_DATABASE="' + str(database) + '"\n', environment)
        self.assertIn('\nSESSION_COOKIE=yanji_', environment)
        self.assertNotIn('YANJI_DESKTOP_USER_ID', environment)
        self.assertNotIn('YANJI_DESKTOP_TENANT_ID', environment)
        self.assertEqual(database.read_bytes(), b'')
        self.assertEqual((instance / '.portable-layout').read_bytes(), b'yanji-portable-v1\n')
        self.assertEqual(json.loads((instance / 'instance.json').read_bytes()), {'port': 8000})
        self.assertEqual({str(path.relative_to(instance)) for path in instance.rglob('*') if path.is_file()},
                         {'.env', '.portable-layout', 'instance.json', 'database/database.sqlite'})
        for path in (self.base, instance, database.parent):
            self.assertEqual(path.stat().st_mode & 0o777, 0o700)
        for path in instance.rglob('*'):
            if path.is_file():
                self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        with self.assertRaises(FileExistsError):
            onboarding.prepare_interrupted_fixture(self.base)
        self.assertEqual((instance / '.env').read_text(), environment)

    def test_interrupted_scenario_prepares_only_after_fresh_guard_then_uses_same_pipeline_and_helper(self):
        events = []
        real_preflight = onboarding.preflight

        def guarded(version):
            self.assertFalse(self.base.exists())
            events.append('guard')
            return real_preflight(version)

        def installed(version):
            self.assertEqual(events, ['guard'])
            self.assertIn('\nAPP_KEY=\n', (self.base / 'instance' / '.env').read_text())
            self.assertEqual((self.base / 'instance' / 'database' / 'database.sqlite').stat().st_size, 0)
            events.append('pipeline')
            self.fixture_installation()
            return {'curl_exit': 0, 'sh_exit': 0}

        authorize = MagicMock(return_value={'user_id': 1, 'tenant_id': 1})
        with patch.object(onboarding, 'preflight', side_effect=guarded), \
             patch.object(onboarding, 'run_published_pipeline', side_effect=installed) as pipeline, \
             patch.object(onboarding, 'check_services'), \
             patch.object(onboarding, 'load_desktop_helpers', return_value={
                 'write_acceptance_marker': lambda path: path.write_bytes(b'desktop-fixture\n'),
                 'authorize_and_check': authorize, 'onboarding_and_check': MagicMock()}), \
             patch.object(onboarding, 'stop_instance') as stop:
            result = onboarding.run_acceptance(VERSION, 'interrupted')
        self.assertEqual(events, ['guard', 'pipeline'])
        pipeline.assert_called_once_with(VERSION)
        authorize.assert_called_once_with(self.base / 'instance', 8000)
        stop.assert_called_once_with(self.base / 'instance')
        self.assertEqual(result['scenario'], 'interrupted')

    def test_failed_installation_still_stops_only_this_new_instance(self):
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', side_effect=onboarding.AcceptanceError('published_reader_installation_failed')), \
             patch.object(onboarding, 'stop_instance') as stop:
            with self.assertRaises(onboarding.AcceptanceError):
                onboarding.run_acceptance(VERSION)
        stop.assert_called_once_with(self.base / 'instance')

    def test_unexpected_tool_errors_cannot_echo_credentials_or_private_paths(self):
        secret = 'sk-test-never-print-private-diagnostic'
        with patch.object(onboarding, 'run_acceptance', side_effect=subprocess.CalledProcessError(1, [str(self.home), secret])):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(onboarding.main(['--version', VERSION]), 1)
        self.assertNotIn(secret, output.getvalue())
        self.assertNotIn(str(self.home), output.getvalue())
        self.assertEqual(json.loads(output.getvalue())['code'], 'unexpected_acceptance_failure')


if __name__ == '__main__':
    unittest.main()
