"""Offline interface tests; no Windows installation, browser or network access occurs here."""
import contextlib
import importlib.util
import io
import hashlib
import json
import os
from pathlib import Path, PureWindowsPath
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import MagicMock, patch


SOURCE = Path(__file__).resolve().parents[1] / 'test-published-windows-onboarding.py'
SPEC = importlib.util.spec_from_file_location('published_windows_onboarding', SOURCE)
onboarding = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(onboarding)
VERSION = 'v0.1.1'
COMMIT = 'a' * 40


class PublishedWindowsOnboardingTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='yanji-reader-windows-interface-')
        self.addCleanup(self.temporary.cleanup)
        self.local = Path(self.temporary.name).resolve()
        self.base = self.local / 'Yanji'
        environment = {
            'GITHUB_ACTIONS': 'true', 'RUNNER_OS': 'Windows',
            'RUNNER_ENVIRONMENT': 'github-hosted', 'GITHUB_REPOSITORY': onboarding.REPOSITORY,
            'GITHUB_REF_TYPE': 'tag', 'GITHUB_REF_NAME': VERSION,
            'GITHUB_REF': 'refs/tags/' + VERSION, 'GITHUB_SHA': COMMIT,
            'LOCALAPPDATA': str(self.local), 'SystemRoot': str(self.local / 'Windows'),
        }
        self.enterContext(patch.dict(os.environ, environment))
        self.enterContext(patch.object(onboarding.sys, 'platform', 'win32'))
        self.folder = self.enterContext(patch.object(onboarding, 'windows_local_app_data', return_value=self.local))
        # The macOS temporary directory may itself have a linked ancestor, unlike this fake runner.
        self.linked = self.enterContext(patch.object(onboarding, 'linked', side_effect=lambda path: path.is_symlink()))
        self.socket = self.enterContext(patch.object(onboarding.socket, 'socket'))
        original_support_loader = onboarding.load_public_entry_support
        self.wait_for_initial_authorization = MagicMock(return_value={'state': 'consumed', 'waited_seconds': 0})

        def support_loader():
            support = original_support_loader()
            support['wait_for_initial_authorization_settle'] = self.wait_for_initial_authorization
            return support

        self.enterContext(patch.object(onboarding, 'load_public_entry_support', side_effect=support_loader))
        self.helpers = {
            'read_management_json': MagicMock(return_value=({'ready': True, 'writes_enabled': True,
                'services': {role: {'healthy': True} for role in ('web', 'worker', 'scheduler')}}, b'{}')),
            'onboarding_and_check': MagicMock(),
            'write_acceptance_marker': MagicMock(side_effect=lambda path: path.write_bytes(b'desktop-fixture\n')),
            'authorize_and_check': MagicMock(return_value={'user_id': 1, 'tenant_id': 1}),
            'manage': MagicMock(return_value=SimpleNamespace(returncode=0)),
        }

    def fixture_installation(self):
        instance = self.base / 'instance'
        release = self.base / 'program' / 'releases' / ('a' * 64)
        (release / 'app').mkdir(parents=True)
        instance.mkdir(parents=True, exist_ok=True)
        (instance / 'yanji.cmd').write_bytes(b'@rem synthetic launcher, never executed\r\n')
        (instance / 'current-release.txt').write_bytes((str(release) + '\r\n').encode())
        (instance / 'instance.json').write_text('{"port":8000}')
        (release / 'release.json').write_text(json.dumps({'platform': 'windows-x86_64', 'commit': COMMIT}))
        (release / 'app' / 'VERSION').write_text(VERSION[1:] + '\n')
        return instance, release

    def test_non_ci_invocation_cannot_touch_installation_or_cleanup(self):
        os.environ.pop('GITHUB_ACTIONS')
        with patch.object(onboarding.subprocess, 'Popen') as popen, \
             patch.object(onboarding, 'load_desktop_helpers') as load, \
             patch.object(onboarding, 'stop_instance') as stop:
            for scenario in ('fresh', 'interrupted'):
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    status = onboarding.main(['--version', VERSION, '--scenario', scenario])
                self.assertEqual(status, 1)
                self.assertEqual(json.loads(output.getvalue()), {
                    'published_reader_entry': 'failed', 'code': 'fresh_github_hosted_windows_required', 'stage': 'preflight'})
                self.assertNotIn(str(self.local), output.getvalue())
        self.assertFalse(self.base.exists())
        self.folder.assert_not_called()
        self.socket.assert_not_called()
        popen.assert_not_called()
        load.assert_not_called()
        stop.assert_not_called()

    def test_wrong_platform_self_hosted_repository_or_source_is_rejected(self):
        for key, value in [('RUNNER_OS', 'Linux'), ('RUNNER_ENVIRONMENT', 'self-hosted'),
                           ('GITHUB_REPOSITORY', 'example/project'), ('GITHUB_REF_TYPE', 'branch'),
                           ('GITHUB_REF_NAME', 'v0.0.0'), ('GITHUB_REF', 'refs/heads/main')]:
            with self.subTest(key=key), patch.dict(os.environ, {key: value}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'fresh_github_hosted_windows_required'):
                    onboarding.preflight(VERSION)
        with patch.object(onboarding.sys, 'platform', 'darwin'):
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'fresh_github_hosted_windows_required'):
                onboarding.preflight(VERSION)
        for sha in ('', 'short', 'A' * 40):
            with self.subTest(sha=sha), patch.dict(os.environ, {'GITHUB_SHA': sha}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'fixed_release_source_required'):
                    onboarding.preflight(VERSION)
        self.folder.assert_not_called()
        self.socket.assert_not_called()

    def test_known_folder_must_match_installer_environment_before_binding(self):
        for configured in ('', str(self.local / 'other')):
            with self.subTest(configured=configured), patch.dict(os.environ, {'LOCALAPPDATA': configured}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_runner_local_app_data_required'):
                    onboarding.preflight(VERSION)
        self.socket.assert_not_called()
        self.assertEqual(onboarding.preflight(VERSION), self.base)
        self.socket.return_value.__enter__.return_value.bind.assert_called_once_with(('127.0.0.1', 8000))

    def test_existing_default_directory_or_link_is_never_installed_or_stopped(self):
        for symlink in (False, True):
            with self.subTest(symlink=symlink):
                self.base.symlink_to(self.local / 'missing') if symlink else self.base.mkdir()
                with patch.object(onboarding, 'run_published_pipeline') as pipeline, \
                     patch.object(onboarding, 'stop_instance') as stop:
                    for scenario in ('fresh', 'interrupted'):
                        with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_yanji_directory_already_exists'):
                            onboarding.run_acceptance(VERSION, scenario)
                    pipeline.assert_not_called()
                    stop.assert_not_called()
                self.base.unlink() if symlink else self.base.rmdir()
        self.socket.assert_not_called()

    def test_linked_ancestor_and_busy_port_are_rejected(self):
        self.linked.side_effect = lambda path: path == self.local
        with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_runner_directory_is_linked'):
            onboarding.preflight(VERSION)
        self.socket.assert_not_called()
        self.linked.side_effect = lambda path: path.is_symlink()
        self.socket.return_value.__enter__.return_value.bind.side_effect = OSError('synthetic busy port')
        with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_port_already_in_use'):
            onboarding.preflight(VERSION)

    def test_only_approved_release_url_can_enter_powershell_command(self):
        self.assertEqual(onboarding.release_url(VERSION),
                         'https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1/get-yanji.ps1')
        for version in ('../v0.1.1', "v0.1.1';echo test", 'https://example.com/test', 'v0.1.1\n'):
            with self.subTest(version=version), self.assertRaises(onboarding.AcceptanceError):
                onboarding.release_url(version)

    def test_pipeline_executes_standard_command_without_redirecting_paths_or_prompting(self):
        process = MagicMock(returncode=0)
        process.poll.return_value = 0
        with patch.object(onboarding.subprocess, 'Popen', return_value=process) as popen:
            self.assertEqual(onboarding.run_published_pipeline(VERSION), {'powershell_exit': 0})
        command = popen.call_args.args[0]
        self.assertEqual(Path(command[0]), Path(os.environ['SystemRoot']) / 'System32/WindowsPowerShell/v1.0/powershell.exe')
        self.assertEqual(command[1:-1], ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command'])
        self.assertEqual(command[-1], "$ErrorActionPreference = 'Stop'; irm '" + onboarding.release_url(VERSION) + "' | iex")
        self.assertEqual(popen.call_args.kwargs['env'], dict(os.environ))
        self.assertEqual(popen.call_args.kwargs['stdin'], subprocess.DEVNULL)
        self.assertEqual(popen.call_args.kwargs['creationflags'], 0x00000200)
        process.wait.assert_called_once_with(timeout=900)

    def test_pipeline_failure_and_timeout_cannot_report_success(self):
        for timeout in (False, True):
            with self.subTest(timeout=timeout):
                process = MagicMock(returncode=1)
                process.poll.return_value = 1
                if timeout:
                    process.wait.side_effect = subprocess.TimeoutExpired('synthetic', 900)
                with patch.object(onboarding.subprocess, 'Popen', return_value=process), \
                     patch.object(onboarding, 'terminate_pipeline_process') as terminate:
                    code = 'published_reader_installation_timed_out' if timeout else 'published_reader_installation_failed'
                    with self.assertRaisesRegex(onboarding.AcceptanceError, code):
                        onboarding.run_published_pipeline(VERSION)
                terminate.assert_called_once_with(process)

    def test_running_timeout_process_tree_is_stopped_without_printing_output(self):
        process = MagicMock(pid=12345)
        process.poll.return_value = None
        with patch.object(onboarding.subprocess, 'run') as run:
            onboarding.terminate_pipeline_process(process)
        self.assertEqual(run.call_args.args[0], ['taskkill.exe', '/PID', '12345', '/T', '/F'])
        self.assertEqual(run.call_args.kwargs['stdout'], subprocess.DEVNULL)
        self.assertEqual(run.call_args.kwargs['stderr'], subprocess.DEVNULL)
        process.wait.assert_called_once_with(timeout=10)

    def test_installation_must_match_version_platform_source_and_default_port(self):
        instance, release = self.fixture_installation()
        self.assertEqual(onboarding.check_installed_release(self.base, VERSION), instance)
        checks = [
            (release / 'app' / 'VERSION', '0.0.0\n', 'installed_version_differs_from_release_tag'),
            (release / 'release.json', json.dumps({'platform': 'linux-x86_64', 'commit': COMMIT}),
             'installed_platform_is_not_windows_x86_64'),
            (release / 'release.json', json.dumps({'platform': 'windows-x86_64', 'commit': 'b' * 40}),
             'installed_source_differs_from_release_run'),
            (release / 'release.json', json.dumps({'platform': 'windows-x86_64'}),
             'installed_source_differs_from_release_run'),
            (instance / 'instance.json', '{"port":8001}', 'installed_instance_did_not_use_default_port'),
            (instance / 'instance.json', '{"port":true}', 'installed_instance_did_not_use_default_port'),
        ]
        for path, contents, code in checks:
            original = path.read_bytes()
            with self.subTest(code=code):
                path.write_bytes(contents.encode())
                with self.assertRaisesRegex(onboarding.AcceptanceError, code):
                    onboarding.check_installed_release(self.base, VERSION)
                path.write_bytes(original)

    def test_launcher_and_pointer_cannot_redirect_to_other_installation(self):
        instance, _ = self.fixture_installation()
        pointer = instance / 'current-release.txt'
        pointer.write_text(str(self.local))
        with self.assertRaisesRegex(onboarding.AcceptanceError, 'installed_release_outside_default_program'):
            onboarding.check_installed_release(self.base, VERSION)
        (instance / 'yanji.cmd').unlink()
        with self.assertRaisesRegex(onboarding.AcceptanceError, 'default_instance_launcher_missing'):
            onboarding.check_installed_release(self.base, VERSION)

    def test_services_require_explicit_ready_writes_and_all_three_roles(self):
        instance = self.base / 'instance'
        onboarding.check_services(instance, self.helpers)
        self.helpers['read_management_json'].assert_called_once_with(instance / 'yanji.cmd', 'status')
        for record in ({}, {'ready': 1, 'writes_enabled': True},
                       {'ready': True, 'writes_enabled': True, 'services': {}},
                       {'ready': True, 'writes_enabled': True,
                        'services': {role: {'healthy': 1} for role in ('web', 'worker', 'scheduler')}}):
            with self.subTest(record=record):
                self.helpers['read_management_json'].return_value = (record, b'{}')
                with self.assertRaises(onboarding.AcceptanceError):
                    onboarding.check_services(instance, self.helpers)

    def test_fresh_success_checks_onboarding_cookie_and_api_then_stops_only_new_instance(self):
        instance, _ = self.fixture_installation()
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            result = onboarding.run_acceptance(VERSION)
        self.wait_for_initial_authorization.assert_called_once_with(instance)
        self.helpers['onboarding_and_check'].assert_called_once_with(instance, 8000)
        self.helpers['authorize_and_check'].assert_called_once_with(instance, 8000)
        self.helpers['manage'].assert_called_once_with(instance / 'yanji.cmd', 'stop', check=False)
        self.assertEqual((instance / '.portable-acceptance').read_bytes(), b'desktop-fixture\n')
        self.assertEqual(result['browser_gui'], 'not_verified')
        self.assertEqual(result['model_or_magpie_requests'], 'not_performed')
        self.assertNotIn(str(self.local), json.dumps(result))

    def test_windows_interrupted_environment_uses_the_original_installer_slash_format(self):
        instance = PureWindowsPath('C:/Users/example/AppData/Local/Yanji/instance')
        self.assertIn('\\', str(instance))
        environment = onboarding.interrupted_environment(instance).decode('utf-8')
        self.assertIn('\nDB_DATABASE="C:/Users/example/AppData/Local/Yanji/instance/database/database.sqlite"\n', environment)
        self.assertNotIn('\\', environment)
        self.assertIn('\nSESSION_COOKIE=yanji_' + hashlib.sha256(instance.as_posix().encode()).hexdigest()[:24] + '\n',
                      environment)
        self.assertIn('\nAPP_KEY=\n', environment)
        self.assertNotIn('YANJI_DESKTOP_MODE=', environment)

    def test_interrupted_fixture_has_no_existing_key_data_or_desktop_binding(self):
        onboarding.prepare_interrupted_fixture(self.base)
        instance = self.base / 'instance'
        environment = (instance / '.env').read_text()
        self.assertEqual(environment.count('\nAPP_KEY=\n'), 1)
        self.assertNotIn('YANJI_DESKTOP_MODE=', environment)
        self.assertNotIn('YANJI_DESKTOP_USER_ID', environment)
        self.assertNotIn('YANJI_DESKTOP_TENANT_ID', environment)
        self.assertEqual((instance / 'database/database.sqlite').read_bytes(), b'')
        self.assertEqual((instance / '.portable-layout').read_bytes(), b'yanji-portable-v1\n')
        self.assertEqual({path.relative_to(instance).as_posix() for path in instance.rglob('*') if path.is_file()},
                         {'.env', '.portable-layout', 'instance.json', 'database/database.sqlite'})
        with self.assertRaises(FileExistsError):
            onboarding.prepare_interrupted_fixture(self.base)
        self.assertEqual((instance / '.env').read_text(), environment)

    def test_interrupted_installation_uses_same_published_command_after_guard(self):
        events = []
        real_guard = onboarding.preflight

        def guarded(version):
            self.assertFalse(self.base.exists())
            events.append('guard')
            return real_guard(version)

        def installed(version):
            self.assertEqual(events, ['guard'])
            self.assertIn('\nAPP_KEY=\n', (self.base / 'instance/.env').read_text())
            events.append('pipeline')
            self.fixture_installation()
            return {'powershell_exit': 0}

        with patch.object(onboarding, 'preflight', side_effect=guarded), \
             patch.object(onboarding, 'run_published_pipeline', side_effect=installed) as pipeline, \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            result = onboarding.run_acceptance(VERSION, 'interrupted')
        self.assertEqual(events, ['guard', 'pipeline'])
        pipeline.assert_called_once_with(VERSION)
        self.assertEqual(result['scenario'], 'interrupted')
        self.helpers['manage'].assert_called_once_with(self.base / 'instance/yanji.cmd', 'stop', check=False)

    def test_failed_installation_still_cleans_up_only_the_new_default_instance(self):
        instance, _ = self.fixture_installation()
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', side_effect=onboarding.AcceptanceError('published_reader_installation_failed')), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'published_reader_installation_failed'):
                onboarding.run_acceptance(VERSION)
        self.helpers['manage'].assert_called_once_with(instance / 'yanji.cmd', 'stop', check=False)

    def test_failed_browser_authorization_cannot_be_claimed_as_ready(self):
        self.fixture_installation()
        self.helpers['authorize_and_check'].return_value = {'user_id': True, 'tenant_id': 1}
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'desktop_workspace_identity_invalid'):
                onboarding.run_acceptance(VERSION)
        self.helpers['manage'].assert_called_once()

    def dispatch_environment(self):
        return {
            'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF_TYPE': 'branch',
            'GITHUB_REF_NAME': 'main', 'GITHUB_REF': 'refs/heads/main',
            'GITHUB_WORKFLOW_REF': onboarding.REPOSITORY + '/.github/workflows/public-entry-verify.yml@refs/heads/main',
            'GITHUB_SHA': 'b' * 40,
        }

    def test_explicit_source_is_allowed_only_in_the_exact_hosted_main_verification_workflow(self):
        with patch.dict(os.environ, self.dispatch_environment()):
            self.assertEqual(onboarding.preflight(VERSION, source_commit=COMMIT), self.base)
            invalid = [
                ('GITHUB_EVENT_NAME', 'push'), ('GITHUB_REF_TYPE', 'tag'),
                ('GITHUB_REF_NAME', 'example'), ('GITHUB_REF', 'refs/heads/example'),
                ('GITHUB_WORKFLOW_REF', onboarding.REPOSITORY + '/.github/workflows/release.yml@refs/heads/main'),
                ('GITHUB_WORKFLOW_REF', 'example/project/.github/workflows/public-entry-verify.yml@refs/heads/main'),
                ('GITHUB_WORKFLOW_REF', onboarding.REPOSITORY + '/.github/workflows/public-entry-verify.yml@refs/heads/example'),
                ('RUNNER_ENVIRONMENT', 'self-hosted'), ('GITHUB_REPOSITORY', 'example/project'),
            ]
            for key, value in invalid:
                with self.subTest(key=key, value=value), patch.dict(os.environ, {key: value}):
                    with self.assertRaisesRegex(onboarding.AcceptanceError, 'fresh_github_hosted_windows_required'):
                        onboarding.preflight(VERSION, source_commit=COMMIT)
            with patch.dict(os.environ, {'GITHUB_SHA': 'invalid'}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'fixed_release_source_required'):
                    onboarding.preflight(VERSION, source_commit=COMMIT)

    def test_tag_flow_cannot_override_its_receipt_source_and_dispatch_cannot_omit_source(self):
        for supplied in (COMMIT, 'b' * 40):
            with self.subTest(source=supplied):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'fresh_github_hosted_windows_required'):
                    onboarding.preflight(VERSION, source_commit=supplied)
        with patch.dict(os.environ, self.dispatch_environment()):
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'fresh_github_hosted_windows_required'):
                onboarding.preflight(VERSION)
            for source in ('', 'short', 'A' * 40, COMMIT + '\n'):
                with self.subTest(source=source), self.assertRaisesRegex(onboarding.AcceptanceError, 'fixed_release_source_required'):
                    onboarding.preflight(VERSION, source_commit=source)

    def test_dispatch_checks_receipt_against_release_source_without_losing_test_source_binding(self):
        instance, release = self.fixture_installation()
        with patch.dict(os.environ, self.dispatch_environment()):
            self.assertEqual(onboarding.check_installed_release(self.base, VERSION, source_commit=COMMIT), instance)
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'installed_source_differs_from_release_run'):
                onboarding.check_installed_release(self.base, VERSION)
            (release / 'release.json').write_text(json.dumps({'platform': 'windows-x86_64', 'commit': 'c' * 40}))
            with self.assertRaisesRegex(onboarding.AcceptanceError, 'installed_source_differs_from_release_run'):
                onboarding.check_installed_release(self.base, VERSION, source_commit=COMMIT)
            with patch.dict(os.environ, {'GITHUB_SHA': ''}):
                with self.assertRaisesRegex(onboarding.AcceptanceError, 'installed_source_differs_from_release_run'):
                    onboarding.check_installed_release(self.base, VERSION, source_commit=COMMIT)

    def test_source_override_is_forwarded_only_when_explicitly_requested(self):
        self.fixture_installation()
        with patch.object(onboarding, 'preflight', return_value=self.base) as guard, \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers), \
             patch.dict(os.environ, self.dispatch_environment()):
            result = onboarding.run_acceptance(VERSION, source_commit=COMMIT)
        guard.assert_called_once_with(VERSION, source_commit=COMMIT)
        self.assertEqual(result['published_reader_entry'], 'passed')
        with patch.object(onboarding, 'run_acceptance', return_value={'published_reader_entry': 'passed'}) as run:
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(onboarding.main(['--version', VERSION, '--source-commit', COMMIT]), 0)
        run.assert_called_once_with(VERSION, 'fresh', source_commit=COMMIT)

    def test_initial_browser_authorization_settles_before_strict_read_only_onboarding(self):
        self.fixture_installation()
        events = []
        self.wait_for_initial_authorization.side_effect = lambda _: events.append('settle')
        self.helpers['onboarding_and_check'].side_effect = lambda *_: events.append('onboarding')
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            onboarding.run_acceptance(VERSION)
        self.assertEqual(events, ['settle', 'onboarding'])

    def test_assertion_diagnostics_keep_exact_stage_and_never_include_arbitrary_error_text(self):
        self.fixture_installation()
        private = 'sk-test-never-print-private-diagnostic ' + str(self.local)
        self.helpers['onboarding_and_check'].side_effect = AssertionError(private)
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(onboarding.main(['--version', VERSION]), 1)
        result = json.loads(output.getvalue())
        self.assertEqual(result['stage'], 'onboarding')
        self.assertEqual(result['published_reader_entry'], 'failed')
        self.assertNotIn(private, output.getvalue())
        self.assertNotIn(str(self.local), output.getvalue())
        self.assertNotIn('sk-test-', output.getvalue())
        self.helpers['manage'].assert_called_once()

    def test_fixed_onboarding_assertion_is_reported_with_a_public_code_and_stage(self):
        self.fixture_installation()
        self.helpers['onboarding_and_check'].side_effect = AssertionError(
            'Reading agent onboarding changed configuration, entries or authorization files')
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(onboarding.main(['--version', VERSION]), 1)
        self.assertEqual(json.loads(output.getvalue()), {
            'published_reader_entry': 'failed', 'stage': 'onboarding', 'code': 'onboarding_files_changed'})
        self.helpers['manage'].assert_called_once()

    def test_failed_initial_settle_stops_before_snapshot_or_new_authorization(self):
        self.fixture_installation()
        private = 'sk-test-private-settle-message ' + str(self.local)
        self.wait_for_initial_authorization.side_effect = RuntimeError(private)
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(onboarding.main(['--version', VERSION]), 1)
        result = json.loads(output.getvalue())
        self.assertEqual(result['stage'], 'initial_authorization_settle')
        self.assertNotIn(private, output.getvalue())
        self.helpers['onboarding_and_check'].assert_not_called()
        self.helpers['authorize_and_check'].assert_not_called()
        self.helpers['manage'].assert_called_once()

    def test_cleanup_does_not_relabel_the_original_acceptance_failure(self):
        self.fixture_installation()
        self.helpers['authorize_and_check'].side_effect = AssertionError('synthetic failure')
        with patch.object(onboarding, 'preflight', return_value=self.base), \
             patch.object(onboarding, 'run_published_pipeline', return_value={'powershell_exit': 0}), \
             patch.object(onboarding, 'load_desktop_helpers', return_value=self.helpers):
            with self.assertRaises(AssertionError) as caught:
                onboarding.run_acceptance(VERSION)
        self.assertEqual(caught.exception._published_stage, 'workspace_authorization')
        self.helpers['manage'].assert_called_once()

    def test_cleanup_error_and_unexpected_diagnostics_are_redacted(self):
        instance, _ = self.fixture_installation()
        self.helpers['manage'].return_value = SimpleNamespace(returncode=1)
        with self.assertRaisesRegex(onboarding.AcceptanceError, 'installed_instance_cleanup_failed'):
            onboarding.stop_instance(instance, self.helpers)
        secret = 'sk-test-never-print-private-diagnostic'
        with patch.object(onboarding, 'run_acceptance', side_effect=RuntimeError(str(self.local) + secret)):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(onboarding.main(['--version', VERSION]), 1)
        self.assertNotIn(secret, output.getvalue())
        self.assertNotIn(str(self.local), output.getvalue())
        self.assertEqual(json.loads(output.getvalue())['code'], 'unexpected_acceptance_failure')


if __name__ == '__main__':
    unittest.main()
