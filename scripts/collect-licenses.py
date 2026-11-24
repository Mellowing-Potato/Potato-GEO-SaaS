#!/usr/bin/env python3
"""Collect installed dependency notices without changing upstream license text.

Build-only: writes an inventory and copies license files inside the image.
Use `npm ROOT OUTPUT` in the frontend stage; `runtime ROOT OUTPUT` after COPY.
No network requests, credentials or application data are accessed.
"""
import importlib.metadata
import json
from pathlib import Path
import re
import shutil
import sys


def license_file(path):
    return any(re.match(r'^(licen[sc]e|copying|copyright|notice|ofl)([._-]|$)', part, re.I)
               for part in path.parts)


def copy_notices(root, output, files=None):
    copied = []
    for path in files if files is not None else sorted(root.rglob('*')):
        if not path.is_file() or path.is_symlink():
            continue
        relative = path.relative_to(root)
        if not license_file(relative):
            continue
        target = output / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
        copied.append(relative.as_posix())
    return copied


def collect(mode, root, output):
    output.mkdir(parents=True, exist_ok=True)
    rows = []
    if mode == 'npm':
        packages = json.loads((root / 'package-lock.json').read_text(encoding='utf-8'))['packages']
        for location, item in sorted(packages.items()):
            if not location or not (root / location).is_dir():
                continue  # optional packages for other architectures are not installed
            name = location.split('node_modules/')[-1]
            rows.append({'ecosystem': 'npm', 'name': name, 'version': item['version'],
                         'license': item.get('license'), 'source': item.get('resolved'),
                         'notices': copy_notices(root / location, output / location)})
    elif mode == 'runtime':
        lock = json.loads((root / 'composer.lock').read_text(encoding='utf-8'))
        for item in lock['packages']:
            package_root = root / 'vendor' / item['name']
            if not package_root.is_dir():
                raise RuntimeError('Missing installed Composer package: ' + item['name'])
            notices = copy_notices(package_root, output / 'composer' / item['name'])
            # If upstream declares a license only in package metadata, preserve
            # that metadata without manufacturing a copyright statement.
            if not notices:
                destination = output / 'composer' / item['name']
                destination.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(package_root / 'composer.json', destination / 'composer.json')
            rows.append({'ecosystem': 'composer', 'name': item['name'], 'version': item['version'],
                         'license': item.get('license'), 'source': item.get('source'),
                         'notices': notices, 'license_text_missing_upstream': not bool(notices)})
        for item in sorted(importlib.metadata.distributions(), key=lambda d: d.metadata['Name'].lower()):
            name = item.metadata['Name']
            package_root = Path(item.locate_file('')).resolve()
            files = [Path(item.locate_file(p)) for p in item.files or []
                     if '..' not in p.parts and license_file(p)]
            rows.append({'ecosystem': 'pypi', 'name': name, 'version': item.version,
                         'license': item.metadata.get('License-Expression') or item.metadata.get('License'),
                         'classifiers': item.metadata.get_all('Classifier', []),
                         'source': item.metadata.get_all('Project-URL', []),
                         'notices': copy_notices(package_root, output / 'python' / name, files)})
        # Alpine's installed database carries exact package versions, source origin
        # names, license expressions and upstream URLs, including base-image packages.
        for block in Path('/lib/apk/db/installed').read_text().split('\n\n'):
            fields = dict(line.split(':', 1) for line in block.splitlines()
                          if len(line) > 1 and line[1] == ':')
            if 'P' in fields:
                rows.append({'ecosystem': 'apk', 'name': fields['P'], 'version': fields['V'],
                             'license': fields.get('L'), 'source': fields.get('U'),
                             'origin': fields.get('o'), 'aports_commit': fields.get('c')})
        for source, destination in [('public/fonts', 'web-fonts'), ('skill-overlays', 'skill-overlays'),
                                    ('yao-geo-skills', 'yao-geo-skills')]:
            copy_notices(root / source, output / destination)
        shared = Path('/usr/share/licenses')
        if shared.exists():
            shutil.copytree(shared, output / 'system-licenses', dirs_exist_ok=True)
    else:
        raise ValueError('Expected npm or runtime')
    (output / 'inventory.json').write_text(json.dumps(rows, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('Collected', len(rows), mode, 'dependency records')


if __name__ == '__main__':
    collect(sys.argv[1], Path(sys.argv[2]).resolve(), Path(sys.argv[3]).resolve())
