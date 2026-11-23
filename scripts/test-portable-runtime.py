#!/usr/bin/env python3
"""Exercise a verified native package in an isolated local instance without paid services."""
import argparse
import http.server
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import threading
import time


ROOT = Path(__file__).resolve().parents[1]


def until(predicate, seconds=15):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        value = predicate()
        if value:
            return value
        time.sleep(.1)
    raise AssertionError('Timed out waiting for the native runtime acceptance condition')


def scenario(args, work):
    program = work / 'program'
    instance = work / '中文 instance'
    password = work / 'password'
    password.touch(mode=0o600)
    password.write_text(secrets.token_urlsafe(32))
    with socket.socket() as free:
        free.bind(('127.0.0.1', 0))
        port = free.getsockname()[1]
    install = ['sh', str(args.package_dir / 'install-local.sh'), '--manifest', str(args.package_dir / 'SHA256SUMS'),
               '--manifest-sha256', args.manifest_sha256, '--instance', str(instance), '--program-home', str(program),
               '--port', str(port), '--email', 'portable@example.com', '--password-file', str(password), '--python-bin', str(args.python_bin)]
    manage = str(instance / 'yanji')
    private = instance / 'storage/app/private'
    tag = secrets.token_hex(6)
    env = dict(os.environ, YANJI_TEST_RUN=tag)

    def queue(action, *extra):
        subprocess.run([instance / 'current/runtime/bin/php', ROOT / 'tests/fixtures/portable-queue.php',
                        instance, action, *extra], check=True, env=env, stdout=subprocess.DEVNULL)

    def status():
        return json.loads(subprocess.check_output([manage, 'status'], text=True))

    requests = []

    class BlockedResponse(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            requests.append(time.monotonic())
            # The call cannot finish until the worker's actual deadline has passed.
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
    try:
        subprocess.run(install, check=True, stdout=subprocess.DEVNULL)
        (instance / '.portable-acceptance').touch(mode=0o600)
        original = status()
        if not original['ready'] or set(original['services']) != {'web', 'worker', 'scheduler'}:
            raise AssertionError('Web, worker and scheduler must report real readiness')
        subprocess.run([manage, 'start'], check=True, stdout=subprocess.DEVNULL)
        if original['manager_pid'] != status()['manager_pid']:
            raise AssertionError('Repeated start created another supervisor')
        queue('rotation')
        counts = private / ('portable-rotation-' + tag)

        def finished_rotation():
            if counts.exists():
                rows = [line.split() for line in counts.read_text().splitlines()]
                return rows if len(rows) == 501 else None

        rows = until(finished_rotation, 45)
        if [int(row[0]) for row in rows] != list(range(501)) or len({row[1] for row in rows}) != 2:
            raise AssertionError('The 501 jobs did not each finish once across two workers')
        queue('timeout', str(server.server_port))
        queue('after-timeout')
        until(lambda: (private / ('portable-after-timeout-finished-' + tag)).exists())
        elapsed = time.monotonic() - requests[0] if requests else float('inf')
        if len(requests) != 1 or elapsed >= 5 or (private / ('portable-timeout-finished-' + tag)).exists():
            raise AssertionError('A stalled two-second job exceeded its deadline, was re-sent, or continued after timeout')
        queue('drain')
        until(lambda: (private / ('portable-drain-started-' + tag)).exists())
        subprocess.run([manage, 'stop'], check=True)
        if not (private / ('portable-drain-finished-' + tag)).exists() or status().get('state') != 'stopped':
            raise AssertionError('Stopping the instance interrupted the active short job')
        with socket.socket() as released:
            released.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            released.bind(('127.0.0.1', port))
        release = (instance / 'current').resolve()
        asset = release / 'app/VERSION'
        if asset.stat().st_mode & 0o222:
            raise AssertionError('Installed program files must be read-only')
        asset.chmod(0o600)
        original_bytes = asset.read_bytes()
        asset.write_bytes(original_bytes + b'fixture alteration')
        try:
            changed = subprocess.run(install, capture_output=True)
            if changed.returncode == 0 or status().get('state') != 'stopped':
                raise AssertionError('The installer executed a modified version from its program cache')
        finally:
            asset.write_bytes(original_bytes)
            asset.chmod(0o400)
        return dict(ready=True, cached_tampering_rejected=True, completed_jobs=len(rows), worker_generations=2, blocked_requests=len(requests),
                    next_job_seconds=round(elapsed, 3), active_job_drained=True, port_released=True)
    finally:
        server.shutdown()
        server.server_close()
        if (instance / 'yanji').is_file():
            subprocess.run([manage, 'stop'], check=True, stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package-dir', type=Path, required=True)
    parser.add_argument('--manifest-sha256', required=True)
    parser.add_argument('--python-bin', type=Path, required=True)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='yanji-acceptance-') as temporary:
        print(json.dumps(scenario(args, Path(temporary)), indent=2))
