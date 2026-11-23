#!/usr/bin/env python3
"""Assemble a private candidate from one committed source tree and verified platform bytes."""
import argparse
import gzip
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import subprocess
import tarfile
import tempfile


APP_PATHS = (
    'app', 'artisan', 'bootstrap', 'browser-extension', 'composer.json', 'composer.lock', 'config',
    'database', 'licenses', 'portable', 'public', 'resources', 'routes', 'scripts/install-local.sh', 'skill-overlays',
    'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md', 'VERSION',
)


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def save_file(archive, name, stream, size, mode=0o644):
    if '\n' in name or '\r' in name or '\\' in name:
        raise ValueError('Unsupported release file name')
    entry = tarfile.TarInfo(name)
    entry.mode = mode
    entry.size = size
    checksum = hashlib.sha256()

    class Reader:
        def read(self, count):
            data = stream.read(count)
            checksum.update(data)
            return data

    archive.addfile(entry, Reader())
    if name in archive.file_hashes:
        raise ValueError('Duplicate release path: ' + name)
    archive.file_hashes[name] = checksum.hexdigest()


def save_path(archive, path, name):
    if path.is_symlink() or not path.is_file():
        raise ValueError(f'Expected a regular build input: {name}')
    with path.open('rb') as stream:
        save_file(archive, name, stream, path.stat().st_size, 0o755 if path.stat().st_mode & 0o111 else 0o644)


def copy_tar(source, target, prefix, strip=''):
    for member in source:
        if member.isdir():
            continue
        path = PurePosixPath(member.name)
        if path.is_absolute() or '..' in path.parts or not member.isfile() or '\\' in member.name:
            raise ValueError('Archive contains an unsafe entry')
        if strip:
            path = path.relative_to(strip)
        if path.parts and path.parts[0] == 'pax_global_header':
            continue
        if set(path.parts) & {'.env', '.git', '.dev-records'}:
            raise ValueError('Private files in the build inputs')
        save_file(target, str(PurePosixPath(prefix) / path), source.extractfile(member), member.size,
                  0o755 if member.mode & 0o111 else 0o644)


def build(args):
    for path, checksum in ((args.cli, args.cli_sha256), (args.web, args.web_sha256)):
        if digest(path) != checksum:
            raise ValueError('Platform input digest mismatch')
    root = args.source.resolve()
    ref = subprocess.check_output(['git', 'rev-parse', '--verify', 'HEAD^{commit}'], cwd=root, text=True).strip()
    lock = json.loads(subprocess.check_output(['git', 'show', ref + ':composer.lock'], cwd=root))
    installed = json.loads((args.vendor / 'composer/installed.json').read_text())
    required = {item['name']: item['version'] for item in lock['packages']}
    actual = {item['name']: item['version'] for item in installed['packages']}
    if installed.get('dev', True) or required != actual:
        raise ValueError('Build a separate production vendor directory with composer install --no-dev')
    skills = json.loads(subprocess.check_output(['git', 'show', ref + ':resources/skills/upstream-lock.json'], cwd=root))
    expected = {'skills/' + name: sha for name, sha in skills['files'].items()}
    expected.update({'registry/skills.json': skills['registry_sha256'], 'LICENSE': skills['license_sha256']})
    args.output.mkdir(mode=0o700, parents=True, exist_ok=True)
    target = args.output / ('yanji-' + args.platform + '.tar.gz')
    if target.exists():
        raise ValueError('Choose a new output directory')
    if not (args.assets / 'manifest.json').is_file():
        raise ValueError('Missing compiled frontend manifest')
    with tempfile.TemporaryDirectory(dir=args.output) as temporary:
        source_tar = Path(temporary) / 'app.tar'
        subprocess.run(['git', 'archive', '--format=tar', '--output', str(source_tar), ref, '--', *APP_PATHS],
                       cwd=root, check=True)
        output_tar = Path(temporary) / target.name
        with output_tar.open('wb') as output, gzip.GzipFile(filename='', mode='wb', fileobj=output,
                    mtime=0, compresslevel=5) as zipped, tarfile.open(fileobj=zipped, mode='w') as archive:
            archive.file_hashes = {}
            with tarfile.open(source_tar) as code:
                copy_tar(code, archive, 'app')
            for name, sha in expected.items():
                path = args.skills / name
                if digest(path) != sha:
                    raise ValueError('Skill lock mismatch: ' + name)
                save_path(archive, path, 'app/yao-geo-skills/' + name)
            commit = (skills['upstream_commit'] + '\n').encode()
            save_file(archive, 'app/yao-geo-skills/.upstream-commit', io.BytesIO(commit), len(commit))
            for directory, name in ((args.vendor, 'app/vendor'), (args.assets, 'app/public/build')):
                for path in sorted(directory.rglob('*')):
                    if set(path.relative_to(directory).parts) & {'.git', '.dev-records', '.env'}:
                        raise ValueError('Private files in build input')
                    if path.is_file():
                        save_path(archive, path, name + '/' + path.relative_to(directory).as_posix())
            with tarfile.open(args.cli) as cli:
                names = cli.getnames()
                binaries = [name for name in names if PurePosixPath(name).parts[-2:] == ('bin', 'php')]
                if len(binaries) != 1:
                    raise ValueError('Expected one standalone CLI')
                strip = str(PurePosixPath(binaries[0]).parent.parent)
                ca = [name for name in names if PurePosixPath(name).name == 'cacert.pem']
                if not ca and args.ca and args.ca_sha256 and digest(args.ca) == args.ca_sha256:
                    save_path(archive, args.ca, 'runtime/ca/cacert.pem')
                    save_path(archive, args.ca_notice, 'runtime/ca/NOTICE.txt')
                elif len(ca) != 1:
                    raise ValueError('Expected a single verified CA bundle')
                elif str(PurePosixPath(ca[0]).relative_to(strip)) != 'ca/cacert.pem':
                    entry = cli.getmember(ca[0])
                    save_file(archive, 'runtime/ca/cacert.pem', cli.extractfile(entry), entry.size)
                copy_tar(cli, archive, 'runtime', strip)
            save_path(archive, args.web, 'runtime/bin/frankenphp')
            receipt = json.dumps(dict(commit=ref, platform=args.platform, cli_sha256=args.cli_sha256,
                                      web_sha256=args.web_sha256), indent=2).encode() + b'\n'
            save_file(archive, 'release.json', io.BytesIO(receipt), len(receipt))
            inventory = ''.join(sha + '  ' + name + '\n' for name, sha in sorted(archive.file_hashes.items())).encode()
            save_file(archive, 'PACKAGE-FILES.sha256', io.BytesIO(inventory), len(inventory))
        checks = [(digest(output_tar), target.name)]
        for name in ('install-local.sh', 'install-local.ps1'):
            data = subprocess.check_output(['git', 'show', ref + ':scripts/' + name], cwd=root)
            (Path(temporary) / name).write_bytes(data)
            checks.append((hashlib.sha256(data).hexdigest(), name))
        manifest = (''.join(sha + '  ' + name + '\n' for sha, name in checks)).encode()
        for name in ('install-local.sh', 'install-local.ps1', target.name):
            (Path(temporary) / name).replace(args.output / name)
        (args.output / 'SHA256SUMS').write_bytes(manifest)
        print('SHA256SUMS', hashlib.sha256(manifest).hexdigest())


if __name__ == '__main__':
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument('--platform', required=True, choices=['macos-arm64', 'linux-aarch64', 'linux-x86_64'])
    for name in ('source', 'vendor', 'skills', 'assets', 'cli', 'web', 'output'):
        cli.add_argument('--' + name, type=Path, required=True)
    cli.add_argument('--ca', type=Path)
    cli.add_argument('--ca-notice', type=Path)
    cli.add_argument('--ca-sha256')
    for name in ('cli-sha256', 'web-sha256'):
        cli.add_argument('--' + name, required=True)
    build(cli.parse_args())
