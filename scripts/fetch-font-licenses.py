#!/usr/bin/env python3
"""Build-only download of pinned report-font license texts with hash verification."""
import hashlib
import json
from pathlib import Path
import sys
import urllib.request

manifest, destination = Path(sys.argv[1]), Path(sys.argv[2])
for item in json.loads(manifest.read_text()):
    # Public, immutable source artifacts; this is not a user-controlled URL.
    if not item['url'].startswith('https://raw.githubusercontent.com/'):
        raise ValueError('Expected an upstream HTTPS source')
    with urllib.request.urlopen(item['url'], timeout=60) as response:
        data = response.read(128 * 1024)
    if hashlib.sha256(data).hexdigest() != item['sha256']:
        raise ValueError('Font license checksum mismatch: ' + item['name'])
    path = destination / item['name'] / item.get('filename', 'LICENSE')
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
print('Verified report font licenses')
