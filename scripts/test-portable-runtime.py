#!/usr/bin/env python3
"""Exercise a verified native package in an isolated local instance without paid services."""
import argparse
import http.server
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
IS_WIN = os.name == 'nt'


def until(predicate, seconds=20):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        value = predicate()
        if value:
            return value
        time.sleep(.1)
    raise AssertionError('Timed out waiting for the native runtime acceptance condition')


def protect_windows_file(path: Path) -> None:
    who = subprocess.check_output(['whoami', '/user', '/fo', 'csv', '/nh'], text=True).strip()
    sid = who.split('","')[-1].strip('"')
    subprocess.run(['icacls', str(path), '/inheritance:r', '/grant:r', f'*{sid}:F'], check=True, stdout=subprocess.DEVNULL)


def http_status(url: str, method: str = 'GET') -> int:
    req = urllib.request.Request(url, method=method)
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status
    except urllib.error.HTTPError as err:
        return err.code


def scenario(args, work):
    program = work / 'program'
    instance = work / ("中文 instance" if IS_WIN else "中文 it's instance")
    password = work / 'password'
    if IS_WIN:
        password.write_text(secrets.token_urlsafe(24) + '\n', encoding='utf-8')
        protect_windows_file(password)
    else:
        password.touch(mode=0o600)
        password.write_text(secrets.token_urlsafe(24) + '\n', encoding='utf-8')

    with socket.socket() as free:
        free.bind(('127.0.0.1', 0))
        port = free.getsockname()[1]

    if IS_WIN:
        install = [
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            str(args.package_dir / 'install-local.ps1'),
            '-Manifest', str(args.package_dir / 'SHA256SUMS'),
            '-ManifestSha256', args.manifest_sha256,
            '-Instance', str(instance),
            '-ProgramHome', str(program),
            '-Port', str(port),
            '-Email', 'portable@example.com',
            '-PasswordFile', str(password),
        ]
        if args.python_bin:
            install.extend(['-PythonBin', str(args.python_bin)])
        manage = ['cmd.exe', '/c', str(instance / 'yanji.cmd')]
    else:
        install = [
            'sh', str(args.package_dir / 'install-local.sh'),
            '--manifest', str(args.package_dir / 'SHA256SUMS'),
            '--manifest-sha256', args.manifest_sha256,
            '--instance', str(instance),
            '--program-home', str(program),
            '--port', str(port),
            '--email', 'portable@example.com',
            '--password-file', str(password),
        ]
        if args.python_bin:
            install.extend(['--python-bin', str(args.python_bin)])
        manage = [str(instance / 'yanji')]

    private = instance / 'storage/app/private'
    tag = secrets.token_hex(6)
    env = dict(os.environ, YANJI_TEST_RUN=tag)

    def release_path() -> Path:
        if IS_WIN:
            return Path((instance / 'current-release.txt').read_text(encoding='utf-8').strip())
        return (instance / 'current').resolve()

    def queue(action, *extra):
        rel = release_path()
        php_bin = rel / ('runtime/bin/php.exe' if IS_WIN else 'runtime/bin/php')
        cmd = [str(php_bin)]
        if IS_WIN:
            cmd.extend(['-c', str(instance / 'php.ini')])
        cmd.extend([str(ROOT / 'tests/fixtures/portable-queue.php'), str(instance), action, *extra])
        subprocess.run(cmd, check=True, env=env, stdout=subprocess.DEVNULL)

    def status():
        return json.loads(subprocess.check_output([*manage, 'status'], text=True))

    requests = []

    class BlockedResponse(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            requests.append(time.monotonic())
            time.sleep(25)
            try:
                self.send_response(204)
                self.end_headers()
            except OSError:
                pass

        def log_message(self, *_):
            pass

    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), BlockedResponse)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    restored_instance = work / 'restored-instance'
    try:
        subprocess.run(install, check=True)
        (instance / '.portable-acceptance').write_text('ok\n', encoding='utf-8')
        if not IS_WIN:
            (instance / '.portable-acceptance').chmod(0o600)

        # Verify no-arg launcher prints help cleanly
        help_res = subprocess.run(manage, capture_output=True, text=True, check=True)
        if 'yanji start | stop' not in help_res.stdout:
            raise AssertionError('No-arg launcher did not print usage help')

        # Verify doctor passes with bundled Python and permissions
        doctor_out = subprocess.check_output([*manage, 'doctor'], text=True)
        doctor = json.loads(doctor_out)
        if not doctor.get('permissions') or not doctor.get('database_key_admin_ready') or not doctor.get('python', {}).get('basic'):
            raise AssertionError(f'Doctor failed: {doctor_out}')

        original = status()
        if not original['ready'] or set(original['services']) != {'web', 'worker', 'scheduler'}:
            raise AssertionError('Web, worker and scheduler must report real readiness')

        # Verify private report static 404 guardrail & public static control
        priv_exec = instance / 'storage/app/public/executions/1/probe-secret.md'
        priv_analysis = instance / 'storage/app/public/analysis/probe-secret.md'
        pub_control = instance / 'storage/app/public/sitemaps/probe-public.xml'
        for p_file, content in ((priv_exec, 'private-fixture'), (priv_analysis, 'private-fixture'), (pub_control, 'public-fixture')):
            p_file.parent.mkdir(parents=True, exist_ok=True)
            p_file.write_text(content, encoding='utf-8')
        if http_status(f'http://127.0.0.1:{port}/storage/sitemaps/probe-public.xml', 'GET') != 200:
            raise AssertionError('Public static control /storage/sitemaps/probe-public.xml did not return 200')
        for p in ('/storage/executions/1/probe-secret.md', '/storage/analysis/probe-secret.md',
                  '/storage/executions', '/storage/executions%2f1%2fprobe-secret.md',
                  '/storage/%65xecutions/1/probe-secret.md', '/storage//executions/1/probe-secret.md',
                  '/storage/sitemaps/../executions/1/probe-secret.md'):
            for m in ('GET', 'HEAD'):
                code = http_status(f'http://127.0.0.1:{port}{p}', m)
                if code != 404:
                    raise AssertionError(f'Private static route {m} {p} returned {code}, expected 404')

        subprocess.run([*manage, 'start'], check=True, stdout=subprocess.DEVNULL)
        if original['manager_pid'] != status()['manager_pid']:
            raise AssertionError('Repeated start created another supervisor')

        queue('rotation')
        counts = private / ('portable-rotation-' + tag)

        def finished_rotation():
            if counts.exists():
                rows = [line.split() for line in counts.read_text(encoding='utf-8').splitlines()]
                return rows if len(rows) == 501 else None

        rows = until(finished_rotation, 90 if IS_WIN else 45)
        if [int(row[0]) for row in rows] != list(range(501)) or len({row[1] for row in rows}) != 2:
            raise AssertionError('The 501 jobs did not each finish once across two workers')

        queue('timeout', str(server.server_port))
        queue('after-timeout')
        until(lambda: (private / ('portable-after-timeout-finished-' + tag)).exists(), 20)
        elapsed = time.monotonic() - requests[0] if requests else float('inf')
        if len(requests) != 1 or elapsed >= 12 or (private / ('portable-timeout-finished-' + tag)).exists():
            raise AssertionError(f'Stalled two-second job failed hard-timeout gate: requests={len(requests)} elapsed={elapsed:.2f}s')

        queue('drain')
        until(lambda: (private / ('portable-drain-started-' + tag)).exists(), 15)
        subprocess.run([*manage, 'stop'], check=True)
        if not (private / ('portable-drain-finished-' + tag)).exists() or status().get('state') != 'stopped':
            raise AssertionError('Stopping the instance interrupted the active short job')

        with socket.socket() as released:
            if not IS_WIN:
                released.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            released.bind(('127.0.0.1', port))

        # Verify backup & restore without --python-bin
        backup_zip = work / 'backup.zip'
        subprocess.run([*manage, 'backup', '--output', str(backup_zip)], check=True)
        with socket.socket() as free2:
            free2.bind(('127.0.0.1', 0))
            port2 = free2.getsockname()[1]
        restore_cmd = [*manage, 'restore', '--from', str(backup_zip), '--instance', str(restored_instance), '--port', str(port2)]
        if args.python_bin:
            restore_cmd.extend(['--python-bin', str(args.python_bin)])
        subprocess.run(restore_cmd, check=True)
        restored_manage = ['cmd.exe', '/c', str(restored_instance / 'yanji.cmd')] if IS_WIN else [str(restored_instance / 'yanji')]
        restored_doc = json.loads(subprocess.check_output([*restored_manage, 'doctor'], text=True))
        if not restored_doc.get('database_key_admin_ready') or not restored_doc.get('permissions'):
            raise AssertionError(f'Restored instance doctor failed: {restored_doc}')
        subprocess.run([*restored_manage, 'stop'], check=True)

        # Verify cached tampering rejection
        release = release_path()
        asset = release / 'app/VERSION'
        if not IS_WIN and (asset.stat().st_mode & 0o222):
            raise AssertionError('Installed program files must be read-only')
        if not IS_WIN:
            asset.chmod(0o600)
        original_bytes = asset.read_bytes()
        asset.write_bytes(original_bytes + b'fixture alteration')
        try:
            changed = subprocess.run(install, capture_output=True)
            if changed.returncode == 0 or status().get('state') != 'stopped':
                raise AssertionError('The installer executed a modified version from its program cache')
        finally:
            asset.write_bytes(original_bytes)
            if not IS_WIN:
                asset.chmod(0o400)

        return dict(
            platform='windows-x86_64' if IS_WIN else os.uname().sysname.lower(),
            ready=True,
            private_static_404=True,
            backup_restore_verified=True,
            cached_tampering_rejected=True,
            completed_jobs=len(rows),
            worker_generations=2,
            blocked_requests=len(requests),
            next_job_seconds=round(elapsed, 3),
            active_job_drained=True,
            port_released=True,
            python_version=doctor.get('python', {}).get('version'),
        )
    finally:
        for inst in (instance, restored_instance):
            launcher = inst / ('yanji.cmd' if IS_WIN else 'yanji')
            if launcher.exists():
                cmd = ['cmd.exe', '/c', str(launcher), 'stop'] if IS_WIN else [str(launcher), 'stop']
                subprocess.run(cmd, check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        server.shutdown()
        server.server_close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package-dir', type=Path, required=True)
    parser.add_argument('--manifest-sha256', required=True)
    parser.add_argument('--python-bin', type=Path, default=None)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='yanji-native-') as directory:
        work = Path(directory)
        try:
            result = scenario(args, work)
        finally:
            if not IS_WIN:
                subprocess.run(['chmod', '-R', 'u+w', str(work)], check=False)
        print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
