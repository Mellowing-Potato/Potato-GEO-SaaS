#!/usr/bin/env python3
"""Verify the native no-account desktop path in disposable instances without a GUI or paid requests."""
import argparse
import http.cookiejar
import html.parser
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile


ROOT = Path(__file__).resolve().parents[1]
IS_WIN = os.name == 'nt'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        return None


class PrivateLaunchPage(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self.target = None

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag.lower() == 'meta' and attributes.get('http-equiv', '').lower() == 'refresh':
            refresh = attributes.get('content', '')
            if refresh.startswith('0;url='):
                self.target = refresh[len('0;url='):]


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def manage(launcher, *arguments, check=True):
    command = [str(launcher), *arguments]
    if IS_WIN:
        command = 'cmd.exe /d /s /c "' + subprocess.list2cmdline(command) + '"'
    return subprocess.run(command, check=check, stdin=subprocess.DEVNULL,
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=180)


def client(jar=None):
    handlers = [urllib.request.ProxyHandler({}), NoRedirect()]
    if jar is not None:
        handlers.append(urllib.request.HTTPCookieProcessor(jar))
    return urllib.request.build_opener(*handlers)


def request(opener, origin, path, *, method='GET', body=None, token=None):
    headers = {'Accept': 'application/json', 'Origin': origin}
    data = None
    if body is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(body).encode('utf-8')
    if token is not None:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(origin + path, data=data, headers=headers, method=method)
    try:
        response = opener.open(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.code, response.headers, response.read(1048576)


def resolve_release(instance):
    if IS_WIN:
        return Path((instance / 'current-release.txt').read_text(encoding='utf-8').strip())
    return (instance / 'current').resolve()


def checked_identity(user, tenant):
    if not isinstance(user, dict) or not isinstance(tenant, dict):
        raise AssertionError('Desktop authorization did not return an account and workspace object')
    user_id = user.get('id')
    tenant_id = user.get('tenant_id')
    related_tenant_id = tenant.get('id')
    if any(type(value) is not int or value <= 0 for value in (user_id, tenant_id, related_tenant_id)):
        raise AssertionError('Desktop authorization returned a missing or invalid account/workspace identity')
    if tenant_id != related_tenant_id:
        raise AssertionError('Desktop authorization returned an account assigned to a different workspace')
    return {'user_id': user_id, 'tenant_id': tenant_id}


def private_fixture_diagnostic(raw):
    if len(raw) > 16384:
        return None
    try:
        record = json.loads(raw)
    except (ValueError, UnicodeDecodeError):
        return None
    if not isinstance(record, dict) or record.get('fixture') != 'portable-desktop':
        return None
    allowed = {
        'stage': {'argument_guard', 'instance_guard', 'release_resolve', 'runtime_load', 'instance_construct',
                  'capture_guard', 'desktop_launch', 'capture_write', 'application_environment',
                  'application_bootstrap', 'application_launch_file', 'diagnostic_process'},
        'code': {'fixture_exception', 'unexpected_output', 'desktop_disabled', 'launch_path_rejected',
                 'owner_not_unique', 'instance_key_missing', 'run_directory_untrusted', 'ticket_path_untrusted',
                 'ticket_file_open_failed', 'ticket_file_lock_failed', 'ticket_file_changed', 'ticket_write_failed',
                 'launch_output_open_failed', 'file_permissions_rejected', 'windows_acl_write_failed',
                 'launcher_rejected', 'launch_html_untrusted', 'spawn_failed',
                 'application_probe_passed', 'application_probe_failed'},
        'exception': {'RuntimeException', 'ErrorException', 'Error', 'TypeError', 'ValueError', 'JsonException',
                      'ParseError', 'LogicException', 'InvalidArgumentException',
                      'Illuminate\\Database\\QueryException', 'Throwable', 'none'},
        'file': {'Platform.php', 'Instance.php', 'Installation.php', 'DesktopLauncher.php',
                 'PortableDesktopAccess.php', 'portable-desktop.php', 'unknown'},
    }

    def clean(item, depth=0):
        result = {key: value for key, value in item.items()
                  if key in allowed and isinstance(value, str) and value in allowed[key]}
        for key in ('line', 'stdout_bytes', 'stderr_bytes', 'exit_code'):
            value = item.get(key)
            if type(value) is int and (-255 if key == 'exit_code' else 0) <= value <= 100000000:
                result[key] = value
        if isinstance(item.get('flags'), dict):
            flag_names = {'root_private', 'run_private', 'root_private_check_ok', 'run_private_check_ok',
                          'desktop_enabled', 'ticket_exists', 'ticket_is_link', 'fstat_ok', 'lstat_ok',
                          'handle_regular', 'path_regular', 'dev_equal', 'ino_equal', 'ticket_nonempty', 'probe_failed'}
            result['flags'] = {key: value for key, value in item['flags'].items()
                               if key in flag_names and type(value) is bool}
        if depth < 2:
            for key in ('application', 'detail'):
                if isinstance(item.get(key), dict):
                    result[key] = clean(item[key], depth + 1)
        return result

    return clean(record)


def authorize_and_check(instance, port):
    origin = f'http://127.0.0.1:{port}'
    anonymous = client()
    if request(anonymous, origin, '/api/v1/projects')[0] != 401:
        raise AssertionError('An anonymous desktop request reached private project data')
    code, headers, _ = request(anonymous, origin, '/login')
    if code != 302 or not headers.get('Location', '').endswith('/local-start'):
        raise AssertionError('A desktop instance unexpectedly requires an account login form')

    release = resolve_release(instance)
    php = release / ('runtime/bin/php.exe' if IS_WIN else 'runtime/bin/php')
    output = instance / 'run' / ('browser-launch-' + secrets.token_hex(12) + '.json')
    minted = subprocess.run([str(php), '-n', str(ROOT / 'tests/fixtures/portable-desktop.php'),
                             str(instance), str(output)], stdin=subprocess.DEVNULL,
                            capture_output=True, timeout=60)
    if minted.returncode != 0 or minted.stdout or minted.stderr:
        detail = private_fixture_diagnostic(minted.stderr)
        raise AssertionError('The native launcher failed to write a silent private desktop authorization: '
                             f'exit={minted.returncode}, stdout_bytes={len(minted.stdout)}, '
                             f'stderr_bytes={len(minted.stderr)}' + (f', diagnostic={detail}' if detail else ''))
    try:
        launch = json.loads(output.read_text(encoding='utf-8'))
    finally:
        output.unlink(missing_ok=True)
    file_uri = launch.get('launch_file_uri', '')
    parsed_file = urllib.parse.urlsplit(file_uri)
    if parsed_file.scheme != 'file' or parsed_file.netloc or parsed_file.fragment or parsed_file.query or 'ticket=' in file_uri:
        raise AssertionError('The desktop opener received an authorization instead of a private file URI')
    launch_page = Path(urllib.request.url2pathname(parsed_file.path))
    if launch_page.parent.resolve() != (instance / 'run').resolve() or not launch_page.name.startswith('browser-launch-') or launch_page.suffix != '.html':
        raise AssertionError('The desktop launch page is outside this instance private runtime directory')
    if not IS_WIN and launch_page.stat().st_mode & 0o077:
        raise AssertionError('The desktop launch page is readable by another system user')
    page = PrivateLaunchPage()
    page.feed(launch_page.read_text(encoding='utf-8'))
    parsed = urllib.parse.urlsplit(page.target or '')
    if parsed.scheme != 'http' or parsed.netloc != f'127.0.0.1:{port}' or parsed.path != '/local-start' or parsed.query:
        raise AssertionError('The private desktop authorization targets a different instance')
    fragment = urllib.parse.parse_qs(parsed.fragment)
    nonce = fragment.get('ticket', [''])[0]
    if len(nonce) != 64 or any(char not in '0123456789abcdef' for char in nonce):
        raise AssertionError('The native desktop authorization does not contain a valid nonce')
    jar = http.cookiejar.CookieJar()
    browser = client(jar)
    code, _, body = request(browser, origin, '/api/v1/auth/local-start', method='POST', body={'ticket': nonce})
    if code != 200:
        raise AssertionError('The native desktop nonce could not establish a browser session')
    authorized = json.loads(body)
    if not isinstance(authorized, dict):
        raise AssertionError('Desktop authorization did not return a session object')
    session_identity = checked_identity(authorized.get('user'), authorized.get('tenant'))
    token = authorized.get('token')
    if not isinstance(token, str) or not token:
        raise AssertionError('Desktop authorization did not issue an API credential')
    cookies = [cookie for cookie in jar if cookie.name.startswith('geo_session')]
    if len(cookies) != 1 or not any(key.lower() == 'httponly' for key in cookies[0]._rest):
        raise AssertionError('Desktop authorization did not issue a protected HttpOnly session cookie')
    code, _, body = request(browser, origin, '/api/v1/me', token=token)
    if code != 200:
        raise AssertionError('The browser session cannot access its authenticated workspace')
    identity = json.loads(body)
    if not isinstance(identity, dict):
        raise AssertionError('The authenticated workspace API did not return an account object')
    api_identity = checked_identity(identity, identity.get('tenant'))
    if api_identity != session_identity:
        raise AssertionError('The authenticated API identity differs from the authorized desktop session')
    if request(browser, origin, '/api/v1/projects', token=token)[0] != 200:
        raise AssertionError('The authenticated desktop session cannot access the workspace API')
    if request(client(), origin, '/api/v1/projects', token=token)[0] != 401:
        raise AssertionError('A desktop API token bypassed its browser-cookie binding')
    if request(client(), origin, '/api/v1/auth/local-start', method='POST', body={'ticket': nonce})[0] != 403:
        raise AssertionError('A consumed desktop nonce was accepted twice')
    return api_identity


def scenario(args, work, program=None):
    work.mkdir(parents=True, exist_ok=True)
    program = program or work / 'program'
    instance = work / ('中文 desktop' if IS_WIN else "中文 it's desktop")
    restored = work / 'desktop-restored-instance'
    port = free_port()
    if IS_WIN:
        install = ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                   str(args.package_dir / 'install-local.ps1'), '-Manifest', str(args.package_dir / 'SHA256SUMS'),
                   '-ManifestSha256', args.manifest_sha256, '-ProgramHome', str(program),
                   '-Instance', str(instance), '-Port', str(port), '-NoBrowser']
        if args.python_bin:
            install.extend(['-PythonBin', str(args.python_bin)])
    else:
        install = ['sh', str(args.package_dir / 'install-local.sh'), '--manifest', str(args.package_dir / 'SHA256SUMS'),
                   '--manifest-sha256', args.manifest_sha256, '--program-home', str(program),
                   '--instance', str(instance), '--port', str(port), '--no-browser']
        if args.python_bin:
            install.extend(['--python-bin', str(args.python_bin)])
    launcher_name = 'yanji.cmd' if IS_WIN else 'yanji'
    launcher = instance / launcher_name
    try:
        installed = subprocess.run(install, stdin=subprocess.DEVNULL, capture_output=True, timeout=180)
        if installed.returncode != 0:
            raise AssertionError('Fresh native desktop installation failed without email, password or terminal input')
        if b'ticket=' in installed.stdout or b'ticket=' in installed.stderr:
            raise AssertionError('Desktop installation disclosed its launch nonce in console output')
        (instance / '.portable-acceptance').write_text('desktop-fixture\n', encoding='utf-8')
        if not IS_WIN:
            (instance / '.portable-acceptance').chmod(0o600)
        first_identity = authorize_and_check(instance, port)

        # Restore must retain the original internal identity and regain the markers needed by nonce authorization.
        backup = work / 'desktop-backup.zip'
        manage(launcher, 'backup', '--output', str(backup), '--no-browser')
        with zipfile.ZipFile(backup) as archive:
            if any(any(marker in name for marker in ('browser-open-', 'browser-launch-', 'desktop-ticket.json')) for name in archive.namelist()):
                raise AssertionError('A backup included transient desktop authorization material')
        manage(launcher, 'stop')
        restored_port = free_port()
        restore = ['restore', '--from', str(backup), '--instance', str(restored), '--port', str(restored_port), '--no-browser']
        if args.python_bin:
            restore.extend(['--python-bin', str(args.python_bin)])
        manage(launcher, *restore)
        (restored / '.portable-acceptance').write_text('desktop-fixture\n', encoding='utf-8')
        if not IS_WIN:
            (restored / '.portable-acceptance').chmod(0o600)
        if authorize_and_check(restored, restored_port) != first_identity:
            raise AssertionError('Restoring a desktop backup changed its internal account or workspace')

        return {'desktop_no_account_input': True, 'desktop_nonce_cookie_api_verified': True,
                'desktop_anonymous_and_unbound_tokens_rejected': True, 'desktop_nonce_replay_rejected': True,
                'desktop_backup_restore_verified': True, 'desktop_system_browser_not_invoked': True,
                'desktop_native_opener_uses_private_file_uri': True, 'desktop_backup_excludes_launch_nonces': True}
    finally:
        for target in (instance, restored):
            managed = target / launcher_name
            if managed.exists():
                try:
                    manage(managed, 'stop', check=False)
                except subprocess.TimeoutExpired:
                    if IS_WIN:
                        control = target / 'run/control.json'
                        if control.is_file():
                            pid = json.loads(control.read_text(encoding='utf-8')).get('pid')
                            if isinstance(pid, int) and pid > 0:
                                subprocess.run(['taskkill', '/PID', str(pid), '/T', '/F'], check=False,
                                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package-dir', type=Path, required=True)
    parser.add_argument('--manifest-sha256', required=True)
    parser.add_argument('--python-bin', type=Path, default=None)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='yanji-desktop-native-') as directory:
        work = Path(directory)
        try:
            result = scenario(args, work)
        finally:
            if not IS_WIN:
                subprocess.run(['chmod', '-R', 'u+w', str(work)], check=False)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
