#!/usr/bin/env python3
"""Fetch and SHA-256-verify pre-built Unix CLI/Web runtime kits listed in a pinned HTTPS manifest."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import urllib.request

SHA_RE = re.compile(r'^[a-f0-9]{64}$')
SAFE_NAME = re.compile(r'^[A-Za-z0-9._-]+$')


def sha256_file(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


class _HttpsOnlyRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not newurl.startswith('https://'):
            raise ValueError(f'Refusing non-HTTPS redirect: {newurl}')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download_https(url: str, dest: Path, expected_sha256: str) -> Path:
    if not url.startswith('https://'):
        raise ValueError(f'Only HTTPS URLs are accepted: {url}')
    if not SHA_RE.fullmatch(expected_sha256):
        raise ValueError(f'Invalid expected SHA-256: {expected_sha256}')
    opener = urllib.request.build_opener(_HttpsOnlyRedirect())
    req = urllib.request.Request(url, headers={'User-Agent': 'Yanji-Release-Fetcher/1.0'})
    with opener.open(req, timeout=180) as resp, dest.open('wb') as out:
        while chunk := resp.read(1024 * 1024):
            out.write(chunk)
    actual = sha256_file(dest)
    if actual != expected_sha256:
        dest.unlink(missing_ok=True)
        raise ValueError(f'SHA-256 mismatch for {dest.name}: expected {expected_sha256}, got {actual}')
    return dest


def fetch_platform(manifest_url: str, manifest_sha256: str, platform: str, output_dir: Path) -> dict:
    output_dir.mkdir(parents=True, exist_ok=True)
    manifest_file = output_dir / 'unix-runtime-manifest.json'
    download_https(manifest_url, manifest_file, manifest_sha256)
    data = json.loads(manifest_file.read_text(encoding='utf-8'))
    entry = (data.get('platforms') or {}).get(platform)
    if not isinstance(entry, dict):
        raise ValueError(f'Platform {platform} missing from manifest {manifest_url}')

    fetched: dict[str, dict[str, str]] = {}
    for key in ('cli', 'cli_source_kit', 'web_runtime', 'web_source_kit', 'ca', 'ca_notice'):
        item = entry.get(key)
        if item is None:
            if key in ('ca', 'ca_notice'):
                continue
            raise ValueError(f'Platform {platform} missing required entry {key}')
        name = item.get('name', '')
        url = item.get('url', '')
        sha = item.get('sha256', '')
        if not SAFE_NAME.fullmatch(name):
            raise ValueError(f'Unsafe filename for {key}: {name}')
        target = output_dir / name
        download_https(url, target, sha)
        fetched[key] = {'name': name, 'path': str(target), 'sha256': sha}

    receipt = output_dir / f'{platform}-inputs.json'
    receipt.write_text(json.dumps(fetched, indent=2) + '\n', encoding='utf-8')
    return fetched


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest-url', required=True)
    parser.add_argument('--manifest-sha256', required=True)
    parser.add_argument('--platform', required=True, choices=('macos-arm64', 'linux-aarch64', 'linux-x86_64'))
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = fetch_platform(args.manifest_url, args.manifest_sha256, args.platform, args.output)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
