#!/usr/bin/env python3
"""Verify the shipped Caddy rules with a real storage symlink in disposable local Docker."""
import argparse
import http.client
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import time
import uuid

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('linux_tests', ROOT / 'scripts/test-linux.py')
linux_tests = importlib.util.module_from_spec(spec)
spec.loader.exec_module(linux_tests)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', default='geo-saas:test')
    args = parser.parse_args()
    endpoint = subprocess.check_output(['docker', 'context', 'inspect', '--format', '{{.Endpoints.docker.Host}}'], text=True).strip()
    if not linux_tests.is_local_docker_endpoint(endpoint, os.environ):
        raise RuntimeError('Only a local Docker socket is permitted.')
    name = 'geo-report-http-' + uuid.uuid4().hex[:12]
    # No repository/production data is mounted; files exist only in this container.
    command = '''mkdir -p /tmp/report-http/public /tmp/report-http/storage/executions/1 /tmp/report-http/storage/analysis /tmp/report-http/storage/sitemaps &&
printf private-fixture > /tmp/report-http/storage/executions/1/report.md &&
printf private-fixture > /tmp/report-http/storage/analysis/report.md &&
printf public-fixture > /tmp/report-http/storage/sitemaps/control.xml &&
ln -s /tmp/report-http/storage /tmp/report-http/public/storage &&
exec frankenphp run --config /app/docker/Caddyfile'''
    started = False
    try:
        subprocess.run(['docker', 'run', '-d', '--name', name, '--cap-drop', 'ALL', '--cap-add', 'NET_BIND_SERVICE', '--security-opt', 'no-new-privileges:true',
                        '-p', '127.0.0.1::8000', '-e', 'SERVER_NAME=:8000', '-e', 'SERVER_ROOT=/tmp/report-http/public',
                        '--entrypoint', 'sh', args.image, '-c', command], check=True, capture_output=True)
        started = True
        address = subprocess.check_output(['docker', 'port', name, '8000/tcp'], text=True).strip()
        port = int(address.rsplit(':', 1)[1])

        def request(path, method='GET'):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
            try:
                connection.request(method, path)
                response = connection.getresponse()
                return response.status, response.read()
            finally:
                connection.close()

        for _ in range(40):
            try:
                if request('/storage/sitemaps/control.xml') == (200, b'public-fixture'):
                    break
            except (OSError, http.client.HTTPException):
                pass
            time.sleep(0.25)
        else:
            raise RuntimeError('Disposable HTTP server failed its public-file control.')
        paths = ['/storage/executions/1/report.md', '/storage/analysis/report.md', '/storage/executions',
                 '/storage/executions%2f1%2freport.md', '/storage/%65xecutions/1/report.md',
                 '/storage//executions/1/report.md', '/storage/sitemaps/../executions/1/report.md']
        for method in ['GET', 'HEAD']:
            for path in paths:
                status, body = request(path, method)
                if status != 404 or b'private-fixture' in body:
                    raise AssertionError(f'Private report static boundary failed: {method} {path}: {status}')
        print(json.dumps({'passed': True, 'private_requests_denied': len(paths) * 2, 'public_control': 200}))
    except Exception:
        if started:
            subprocess.run(['docker', 'logs', name], check=False)
        raise
    finally:
        if started:
            subprocess.run(['docker', 'rm', '-f', '-v', name], check=True, capture_output=True)


if __name__ == '__main__':
    main()
