#!/usr/bin/env python3
"""Exercise the published curl | sh reader entry point only on a fresh GitHub-hosted Mac.

No installation options, redirected home, model requests or local-machine installation are allowed.
Captured installer output is never printed because it can contain private paths.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import signal
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
    return f'https://github.com/{REPOSITORY}/releases/download/{version}/get-yanji.sh'


def preflight(version):
    release_url(version)
    expected = {
        'GITHUB_ACTIONS': 'true', 'RUNNER_OS': 'macOS',
        'RUNNER_ENVIRONMENT': 'github-hosted', 'GITHUB_REPOSITORY': REPOSITORY,
        'GITHUB_REF_TYPE': 'tag', 'GITHUB_REF_NAME': version, 'GITHUB_REF': 'refs/tags/' + version,
    }
    if sys.platform != 'darwin' or any(os.environ.get(key) != value for key, value in expected.items()):
        raise AcceptanceError('fresh_github_hosted_macos_required')
    import pwd
    home = Path.home()
    account_home = Path(pwd.getpwuid(os.getuid()).pw_dir)
    if home != account_home or not home.is_absolute() or not home.is_dir() or home.is_symlink():
        raise AcceptanceError('default_runner_home_required')
    base = home / 'Library' / 'Application Support' / 'Yanji'
    if base.exists() or base.is_symlink():
        raise AcceptanceError('default_yanji_directory_already_exists')
    if any(parent.is_symlink() for parent in (base.parent, base.parent.parent)):
        raise AcceptanceError('default_runner_directory_is_linked')
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(('127.0.0.1', PORT))
    except OSError:
        raise AcceptanceError('default_port_already_in_use') from None
    return base


def prepare_interrupted_fixture(base):
    """Create only an empty, unkeyed old-install fixture after the fresh-runner guard."""
    instance = base / 'instance'
    database = instance / 'database' / 'database.sqlite'
    template = (ROOT / 'portable' / 'instance.env.example').read_text(encoding='utf-8')
    quoted_database = '"' + str(database).replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'
    replacements = {
        'APP_KEY': '', 'DB_DATABASE': quoted_database,
        'SESSION_COOKIE': 'yanji_' + hashlib.sha256(str(instance).encode()).hexdigest()[:24],
    }
    for name, value in replacements.items():
        template, count = re.subn(r'^' + name + r'=.*$', lambda _: name + '=' + value,
                                 template, flags=re.MULTILINE)
        if count != 1:
            raise AcceptanceError('interrupted_fixture_template_invalid')
    template = re.sub(r'^YANJI_DESKTOP_MODE=.*\n?', '', template, flags=re.MULTILINE)
    # Exclusive creation refuses any directory that appeared after preflight; no existing files are reused.
    base.mkdir(mode=0o700, parents=True)
    instance.mkdir(mode=0o700)
    database.parent.mkdir(mode=0o700)
    files = {
        instance / '.env': template.encode(), database: b'',
        instance / '.portable-layout': b'yanji-portable-v1\n',
        instance / 'instance.json': b'{"port":8000}\n',
    }
    for path, contents in files.items():
        path.touch(mode=0o600, exist_ok=False)
        path.write_bytes(contents)


def terminate_pipeline_process(process):
    if process is None or process.poll() is not None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
        process.wait(timeout=5)
    except (OSError, subprocess.TimeoutExpired):
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except OSError:
            pass
        process.wait(timeout=5)


def run_published_pipeline(version):
    # Equivalent to the documented curl -fsSL URL | sh, with no installer arguments.
    # Separate sessions prevent the bootstrap's /dev/tty fallback from requesting input.
    environment = dict(os.environ, LANG='en_US.UTF-8', LC_ALL='en_US.UTF-8')
    curl = shell = None
    with tempfile.TemporaryFile() as curl_errors, tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
        try:
            curl = subprocess.Popen(['curl', '-fsSL', release_url(version)], stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=curl_errors, env=environment,
                                    start_new_session=True)
            shell = subprocess.Popen(['/bin/sh'], stdin=curl.stdout, stdout=output, stderr=errors,
                                     env=environment, start_new_session=True)
            curl.stdout.close()
            shell.wait(timeout=900)
            curl.wait(timeout=30)
            if curl.returncode != 0:
                raise AcceptanceError('published_bootstrap_download_failed')
            if shell.returncode != 0:
                raise AcceptanceError('published_reader_installation_failed')
            return {'curl_exit': curl.returncode, 'sh_exit': shell.returncode}
        except subprocess.TimeoutExpired:
            raise AcceptanceError('published_reader_installation_timed_out') from None
        finally:
            terminate_pipeline_process(shell)
            terminate_pipeline_process(curl)


def read_json(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 1048576:
        raise AcceptanceError('installed_metadata_invalid')
    return json.loads(path.read_text(encoding='utf-8'))


def check_installed_release(base, version):
    instance = base / 'instance'
    launcher = instance / 'yanji'
    if launcher.is_symlink() or not launcher.is_file():
        raise AcceptanceError('default_instance_launcher_missing')
    release = (instance / 'current').resolve(strict=True)
    if release.parent != (base / 'program' / 'releases').resolve(strict=True):
        raise AcceptanceError('installed_release_outside_default_program')
    if (release / 'app' / 'VERSION').read_text(encoding='utf-8').strip() != version[1:]:
        raise AcceptanceError('installed_version_differs_from_release_tag')
    receipt = read_json(release / 'release.json')
    if not isinstance(receipt, dict) or receipt.get('platform') != 'macos-arm64':
        raise AcceptanceError('installed_platform_is_not_macos_arm64')
    config = read_json(instance / 'instance.json')
    if not isinstance(config, dict) or type(config.get('port')) is not int or config['port'] != PORT:
        raise AcceptanceError('installed_instance_did_not_use_default_port')
    return instance


def check_services(instance):
    status = subprocess.run([str(instance / 'yanji'), 'status'], stdin=subprocess.DEVNULL,
                            capture_output=True, timeout=30, check=False)
    if status.returncode != 0 or len(status.stdout) > 1048576:
        raise AcceptanceError('installed_services_status_failed')
    record = json.loads(status.stdout)
    if not isinstance(record, dict) or record.get('ready') is not True or record.get('writes_enabled') is not True:
        raise AcceptanceError('installed_services_not_ready')
    services = record.get('services')
    if not isinstance(services, dict) or any(
        not isinstance(services.get(role), dict) or services[role].get('healthy') is not True
        for role in ('web', 'worker', 'scheduler')
    ):
        raise AcceptanceError('installed_service_roles_not_healthy')


def load_desktop_helpers():
    return runpy.run_path(str(ROOT / 'scripts' / 'test-portable-desktop.py'))


def stop_instance(instance):
    launcher = instance / 'yanji'
    if launcher.is_symlink():
        raise AcceptanceError('cleanup_launcher_is_linked')
    if not launcher.is_file():
        return
    try:
        stopped = subprocess.run([str(launcher), 'stop'], stdin=subprocess.DEVNULL,
                                 stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                 timeout=180, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise AcceptanceError('installed_instance_cleanup_failed') from None
    if stopped.returncode != 0:
        raise AcceptanceError('installed_instance_cleanup_failed')


def run_acceptance(version, scenario='fresh'):
    if scenario not in ('fresh', 'interrupted'):
        raise AcceptanceError('invalid_acceptance_scenario')
    # Refusing an existing directory happens before the try/finally: never stop a prior instance.
    base = preflight(version)
    instance = base / 'instance'
    if scenario == 'interrupted':
        prepare_interrupted_fixture(base)
    try:
        pipeline = run_published_pipeline(version)
        check_installed_release(base, version)
        check_services(instance)
        helpers = load_desktop_helpers()
        marker = instance / '.portable-acceptance'
        helpers['write_acceptance_marker'](marker)
        marker.chmod(0o600)
        identity = helpers['authorize_and_check'](instance, PORT)
        if not isinstance(identity, dict) or any(type(identity.get(key)) is not int or identity[key] <= 0
                                                 for key in ('user_id', 'tenant_id')):
            raise AcceptanceError('desktop_workspace_identity_invalid')
    finally:
        stop_instance(instance)
    return {'published_reader_entry': 'passed', 'version': version, 'scenario': scenario, **pipeline,
            'default_location_and_port': True, 'services_ready': True,
            'workspace_cookie_and_api': True, 'anonymous_and_unbound_tokens_rejected': True,
            'instance_stopped': True, 'model_or_magpie_requests': 'not_performed',
            'browser_gui': 'not_verified'}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', required=True, help='The release tag from the GitHub tag event')
    parser.add_argument('--scenario', choices=('fresh', 'interrupted'), default='fresh')
    arguments = parser.parse_args(argv)
    try:
        result = run_acceptance(arguments.version, arguments.scenario)
    except AcceptanceError as error:
        result = {'published_reader_entry': 'failed', 'code': error.code}
    except Exception:
        # Exceptions from tools/helpers can include credentials, command arguments or private paths.
        result = {'published_reader_entry': 'failed', 'code': 'unexpected_acceptance_failure'}
    print(json.dumps(result, sort_keys=True))
    return 0 if result['published_reader_entry'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())
