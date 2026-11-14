#!/usr/bin/env python3
"""Fetch only the locked public skill snapshot; fail closed on changed assets."""
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import sys
import tarfile
import urllib.request

SOURCE = 'https://codeload.github.com/yaojingang/yao-geo-skills/tar.gz/'


def install(lock_path, destination):
    lock = json.loads(Path(lock_path).read_text())
    commit = lock['upstream_commit']
    if len(commit) != 40 or any(c not in '0123456789abcdef' for c in commit):
        raise ValueError('Invalid locked commit')
    with urllib.request.urlopen(SOURCE + commit, timeout=120) as response:
        archive = response.read(64 * 1024 * 1024 + 1)
    if len(archive) > 64 * 1024 * 1024:
        raise ValueError('Archive too large')
    output = Path(destination)
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as tar:
        prefix = 'yao-geo-skills-' + commit + '/'
        expected = {'skills/' + p: digest for p, digest in lock['files'].items()}
        expected['registry/skills.json'] = lock['registry_sha256']
        expected['LICENSE'] = lock['license_sha256']
        actual = {m.name[len(prefix):] for m in tar.getmembers() if m.isfile()
                  and any(m.name.startswith(prefix + 'skills/' + skill + '/') for skill in lock['approved_skill_ids'])}
        if actual != {'skills/' + p for p in lock['files']}:
            raise ValueError('Locked skill file list differs from upstream')
        # Never extract the archive wholesale: reject links and traversal, then
        # write only approved files whose content matches the release lock.
        for relative, digest in expected.items():
            path = PurePosixPath(relative)
            if path.is_absolute() or '..' in path.parts or '\\' in relative:
                raise ValueError('Invalid locked asset path')
            member = tar.getmember(prefix + relative)
            if not member.isfile():
                raise ValueError('Expected regular asset file')
            data = tar.extractfile(member).read()
            if hashlib.sha256(data).hexdigest() != digest:
                raise ValueError('Asset hash mismatch: ' + relative)
            target = output / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        (output / '.upstream-commit').write_text(commit + '\n')
    print('Verified skill files:', len(lock['files']))


if __name__ == '__main__':
    install(sys.argv[1], sys.argv[2])
