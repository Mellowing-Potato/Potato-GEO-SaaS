#!/usr/bin/env python3
"""Check the published irm | iex reader command on a fresh GitHub-hosted Windows runner.

Never use on a reader's computer. No custom installation paths, models or real GUI assertions.
Installer output stays captured because it may contain local paths or private diagnostics.
"""
import argparse
import ctypes
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import socket
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = 'Mellowing-Potato/Potato-GEO-SaaS'
PORT = 8000


class AcceptanceError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def release_url(version):
    if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+', version):
        raise AcceptanceError('invalid_release_tag')
    return f'https://github.com/{REPOSITORY}/releases/download/{version}/get-yanji.ps1'


def windows_local_app_data():
    # CSIDL_LOCAL_APPDATA is resolved by Windows, independently of HOME/LOCALAPPDATA.
    value = ctypes.create_unicode_buffer(32768)
    if ctypes.windll.shell32.SHGetFolderPathW(None, 0x001C, None, 0, value) != 0:
        raise AcceptanceError('windows_known_folder_lookup_failed')
    return Path(value.value)


def linked(path):
    return path.is_symlink() or (hasattr(path, 'is_junction') and path.is_junction())


def preflight(version, source_commit=None):
    release_url(version)
    expected = {
        'GITHUB_ACTIONS': 'true', 'RUNNER_OS': 'Windows',
        'RUNNER_ENVIRONMENT': 'github-hosted', 'GITHUB_REPOSITORY': REPOSITORY,
    }
    if source_commit is None:
        expected.update({'GITHUB_REF_TYPE': 'tag', 'GITHUB_REF_NAME': version,
                         'GITHUB_REF': 'refs/tags/' + version})
    else:
        if not re.fullmatch(r'[a-f0-9]{40}', source_commit):
            raise AcceptanceError('fixed_release_source_required')
        expected.update({
            'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF_TYPE': 'branch',
            'GITHUB_REF_NAME': 'main', 'GITHUB_REF': 'refs/heads/main',
            'GITHUB_WORKFLOW_REF': REPOSITORY + '/.github/workflows/public-entry-verify.yml@refs/heads/main',
        })
    if sys.platform != 'win32' or any(os.environ.get(key) != value for key, value in expected.items()):
        raise AcceptanceError('fresh_github_hosted_windows_required')
    if not re.fullmatch(r'[a-f0-9]{40}', os.environ.get('GITHUB_SHA', '')):
        raise AcceptanceError('fixed_release_source_required')
    local = windows_local_app_data()
    configured = os.environ.get('LOCALAPPDATA', '')
    if not configured or not local.is_absolute() or not local.is_dir() \
            or Path(configured).resolve() != local.resolve():
        raise AcceptanceError('default_runner_local_app_data_required')
    if any(linked(parent) for parent in (local, *local.parents)):
        raise AcceptanceError('default_runner_directory_is_linked')
    base = local / 'Yanji'
    if base.exists() or linked(base):
        raise AcceptanceError('default_yanji_directory_already_exists')
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(('127.0.0.1', PORT))
    except OSError:
        raise AcceptanceError('default_port_already_in_use') from None
    return base


def interrupted_environment(instance):
    """Match the original installer's normalized paths, including on Windows."""
    database = instance / 'database' / 'database.sqlite'
    template = (ROOT / 'portable' / 'instance.env.example').read_text(encoding='utf-8')
    quoted_database = '"' + database.as_posix().replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'
    replacements = {
        'APP_KEY': '', 'DB_DATABASE': quoted_database,
        'SESSION_COOKIE': 'yanji_' + hashlib.sha256(instance.as_posix().encode()).hexdigest()[:24],
    }
    for name, value in replacements.items():
        template, count = re.subn(r'^' + name + r'=.*$', lambda _: name + '=' + value,
                                 template, flags=re.MULTILINE)
        if count != 1:
            raise AcceptanceError('interrupted_fixture_template_invalid')
    template = re.sub(r'^YANJI_DESKTOP_MODE=.*\n?', '', template, flags=re.MULTILINE)
    return template.encode()


def prepare_interrupted_fixture(base):
    """Only a fresh, unkeyed interrupted installation may be created after preflight."""
    instance = base / 'instance'
    database = instance / 'database' / 'database.sqlite'
    environment = interrupted_environment(instance)
    base.mkdir(parents=True)
    instance.mkdir()
    database.parent.mkdir()
    files = {
        instance / '.env': environment, database: b'',
        instance / '.portable-layout': b'yanji-portable-v1\n',
        instance / 'instance.json': b'{"port":8000}\n',
    }
    for path, contents in files.items():
        with path.open('xb') as output:
            output.write(contents)


def terminate_pipeline_process(process):
    if process is None or process.poll() is not None:
        return
    subprocess.run(['taskkill.exe', '/PID', str(process.pid), '/T', '/F'],
                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                   timeout=30, check=False)
    process.wait(timeout=10)


def run_published_pipeline(version):
    # The documented command is unchanged; process controls prevent an interactive account prompt.
    powershell = Path(os.environ['SystemRoot']) / 'System32/WindowsPowerShell/v1.0/powershell.exe'
    command = "$ErrorActionPreference = 'Stop'; irm '" + release_url(version) + "' | iex"
    process = None
    with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
        try:
            process = subprocess.Popen([str(powershell), '-NoProfile', '-NonInteractive',
                                        '-ExecutionPolicy', 'Bypass', '-Command', command],
                                       stdin=subprocess.DEVNULL, stdout=output, stderr=errors,
                                       env=dict(os.environ), creationflags=0x00000200)
            process.wait(timeout=900)
            if process.returncode != 0:
                raise AcceptanceError('published_reader_installation_failed')
            return {'powershell_exit': process.returncode}
        except subprocess.TimeoutExpired:
            raise AcceptanceError('published_reader_installation_timed_out') from None
        finally:
            terminate_pipeline_process(process)


def read_json(path):
    if linked(path) or not path.is_file() or path.stat().st_size > 1048576:
        raise AcceptanceError('installed_metadata_invalid')
    return json.loads(path.read_text(encoding='utf-8'))


def check_installed_release(base, version, source_commit=None):
    instance = base / 'instance'
    launcher = instance / 'yanji.cmd'
    pointer = instance / 'current-release.txt'
    if linked(launcher) or not launcher.is_file():
        raise AcceptanceError('default_instance_launcher_missing')
    if linked(pointer) or not pointer.is_file() or pointer.stat().st_size > 32768:
        raise AcceptanceError('installed_release_pointer_invalid')
    release = Path(pointer.read_text(encoding='utf-8').strip()).resolve(strict=True)
    if release.parent != (base / 'program' / 'releases').resolve(strict=True) or linked(release):
        raise AcceptanceError('installed_release_outside_default_program')
    if (release / 'app' / 'VERSION').read_text(encoding='utf-8').strip() != version[1:]:
        raise AcceptanceError('installed_version_differs_from_release_tag')
    receipt = read_json(release / 'release.json')
    if not isinstance(receipt, dict) or receipt.get('platform') != 'windows-x86_64':
        raise AcceptanceError('installed_platform_is_not_windows_x86_64')
    workflow_commit = os.environ.get('GITHUB_SHA', '')
    commit = source_commit if source_commit is not None else workflow_commit
    if not re.fullmatch(r'[a-f0-9]{40}', workflow_commit) or not re.fullmatch(r'[a-f0-9]{40}', commit) \
            or receipt.get('commit') != commit:
        raise AcceptanceError('installed_source_differs_from_release_run')
    config = read_json(instance / 'instance.json')
    if not isinstance(config, dict) or type(config.get('port')) is not int or config['port'] != PORT:
        raise AcceptanceError('installed_instance_did_not_use_default_port')
    return instance


def load_public_entry_support():
    return runpy.run_path(str(ROOT / 'scripts' / 'public-entry-support.py'))


def load_desktop_helpers():
    return runpy.run_path(str(ROOT / 'scripts' / 'test-portable-desktop.py'))


def check_services(instance, helpers):
    record, _ = helpers['read_management_json'](instance / 'yanji.cmd', 'status')
    if record.get('ready') is not True or record.get('writes_enabled') is not True:
        raise AcceptanceError('installed_services_not_ready')
    services = record.get('services')
    if not isinstance(services, dict) or any(
        not isinstance(services.get(role), dict) or services[role].get('healthy') is not True
        for role in ('web', 'worker', 'scheduler')
    ):
        raise AcceptanceError('installed_service_roles_not_healthy')


def stop_instance(instance, helpers):
    launcher = instance / 'yanji.cmd'
    if linked(launcher):
        raise AcceptanceError('cleanup_launcher_is_linked')
    if not launcher.is_file():
        return
    try:
        result = helpers['manage'](launcher, 'stop', check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise AcceptanceError('installed_instance_cleanup_failed') from None
    if result.returncode != 0:
        raise AcceptanceError('installed_instance_cleanup_failed')


def run_acceptance(version, scenario='fresh', source_commit=None):
    stage = 'preflight'
    try:
        if scenario not in ('fresh', 'interrupted'):
            raise AcceptanceError('invalid_acceptance_scenario')
        # Refuse existing data before loading helpers or reaching cleanup.
        base = preflight(version) if source_commit is None else preflight(version, source_commit=source_commit)
        instance = base / 'instance'
        helpers = load_desktop_helpers()
        support = load_public_entry_support()
        if scenario == 'interrupted':
            stage = 'interrupted_fixture'
            prepare_interrupted_fixture(base)
        try:
            stage = 'published_pipeline'
            pipeline = run_published_pipeline(version)
            stage = 'installed_release'
            if source_commit is None:
                check_installed_release(base, version)
            else:
                check_installed_release(base, version, source_commit=source_commit)
            stage = 'services'
            check_services(instance, helpers)
            stage = 'initial_authorization_settle'
            support['wait_for_initial_authorization_settle'](instance)
            stage = 'onboarding'
            helpers['onboarding_and_check'](instance, PORT)
            stage = 'acceptance_marker'
            helpers['write_acceptance_marker'](instance / '.portable-acceptance')
            stage = 'workspace_authorization'
            identity = helpers['authorize_and_check'](instance, PORT)
            if not isinstance(identity, dict) or any(type(identity.get(key)) is not int or identity[key] <= 0
                                                     for key in ('user_id', 'tenant_id')):
                raise AcceptanceError('desktop_workspace_identity_invalid')
        except Exception as error:
            error._published_stage = stage
            raise
        finally:
            try:
                stop_instance(instance, helpers)
            except Exception as error:
                error._published_stage = 'cleanup'
                raise
        return {'published_reader_entry': 'passed', 'version': version, 'scenario': scenario, **pipeline,
                'default_location_and_port': True, 'fixed_release_source': True, 'services_ready': True,
                'agent_onboarding_and_entry': True, 'workspace_cookie_and_api': True,
                'anonymous_and_unbound_tokens_rejected': True, 'instance_stopped': True,
                'model_or_magpie_requests': 'not_performed', 'browser_gui': 'not_verified'}
    except Exception as error:
        if not hasattr(error, '_published_stage'):
            error._published_stage = stage
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', required=True)
    parser.add_argument('--scenario', choices=('fresh', 'interrupted'), default='fresh')
    parser.add_argument('--source-commit', help='Existing release source, only in the hosted main-branch verification workflow')
    arguments = parser.parse_args(argv)
    try:
        result = (run_acceptance(arguments.version, arguments.scenario) if arguments.source_commit is None
                  else run_acceptance(arguments.version, arguments.scenario, source_commit=arguments.source_commit))
    except Exception as error:
        support = load_public_entry_support()
        result = {'published_reader_entry': 'failed',
                  **support['safe_failure_diagnostic'](error, getattr(error, '_published_stage', 'acceptance'))}
    print(json.dumps(result, sort_keys=True))
    return 0 if result['published_reader_entry'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())
