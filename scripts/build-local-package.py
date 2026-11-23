#!/usr/bin/env python3
"""Assemble a private candidate from one committed source tree and verified platform bytes."""
import argparse
import gzip
import hashlib
import io
import json
import re
import shutil
from pathlib import Path, PurePosixPath
import subprocess
import tarfile
import tempfile
import os


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
    if name in archive.file_hashes:
        raise ValueError('Duplicate release file: ' + name)
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


def add_npm_notices(archive, directory, lock):
    directory = directory.resolve()
    packages = lock['packages']
    locations = {}
    for location, package in packages.items():
        if location:
            key = (location.split('node_modules/')[-1], package['version'])
            locations.setdefault(key, []).append(location)
    inventory = directory / 'inventory.json'
    rows = json.loads(inventory.read_text())
    used = set()
    members = {'inventory.json'}
    for row in rows:
        notices = row['notices']
        if row['ecosystem'] != 'npm' or not isinstance(notices, list):
            raise ValueError('Invalid npm license inventory')
        for name in notices:
            path = PurePosixPath(name)
            if not path.parts or path.is_absolute() or '..' in path.parts or path.as_posix() != name:
                raise ValueError('Invalid npm notice path')
        candidates = sorted(locations.get((row['name'], row['version']), []), key=lambda item: (len(item), item))
        location = next((place for place in candidates if place not in used
                         and all((directory / place / item).is_file() for item in notices)), None)
        if location is None or row['license'] != packages[location].get('license'):
            raise ValueError('Npm inventory differs from the dependency lock: ' + row['name'])
        used.add(location)
        members.update(location + '/' + name for name in notices)
    if any(loc and not item.get('optional') and loc not in used for loc, item in packages.items()):
        raise ValueError('Missing required npm dependency notices in inventory')
    for name in sorted(members):
        path = directory / name
        if path.resolve() != path:
            raise ValueError('Npm notices may not use symbolic links')
        save_path(archive, path, 'app/licenses/npm/' + name)


def read_go_build_info(binary):
    # Reading build info does not execute a foreign-architecture binary.
    return subprocess.check_output(['go', 'version', '-m', str(binary)], text=True,
                                   env=dict(os.environ, GOTOOLCHAIN='local'), timeout=30)


def verify_web_build_info(info, platform):
    if re.search(r'github\.com/dunglas/(?:mercure|vulcain)(?:[/\s]|$)', info, re.I):
        raise ValueError('Web binary still links Mercure or Vulcain')
    settings = dict(re.findall(r'^\s*build\s+([^=\s]+)=(.*)$', info, re.M))
    tags = set(settings.get('-tags', '').strip('"').split(','))
    if not {'nomercure', 'novulcain'} <= tags or settings.get('CGO_ENABLED') != '1':
        raise ValueError('Web build must retain the explicit exclusion tags and CGO build info')
    expected = {'macos-arm64': ('darwin', 'arm64'), 'linux-aarch64': ('linux', 'arm64'),
                'linux-x86_64': ('linux', 'amd64')}[platform]
    if (settings.get('GOOS'), settings.get('GOARCH')) != expected:
        raise ValueError('Web build target differs from the package platform')
    for module in ('github.com/caddyserver/caddy/v2', 'github.com/dunglas/frankenphp',
                   'github.com/dunglas/caddy-cbrotli'):
        if not re.search(r'^\s*(?:path|mod|dep)\s+' + re.escape(module) + r'(?:[/\s]|$)', info, re.M):
            raise ValueError('Missing required Web module: ' + module)


def inspect_web(args):
    if not getattr(args, 'web_runtime', None) or not getattr(args, 'web_source_kit', None):
        raise ValueError('A verified Web runtime and corresponding-source/relink kit are required')
    for name in ('web_runtime', 'web_source_kit'):
        if digest(getattr(args, name)) != getattr(args, name + '_sha256'):
            raise ValueError('Web material digest mismatch: ' + name)
    kit = args.web_source_kit.name
    if not re.fullmatch(r'[A-Za-z0-9_.-]+\.tar\.(?:gz|zst)', kit) or args.platform not in kit:
        raise ValueError('Expected a platform-specific Web corresponding-source/relink kit')
    with tarfile.open(args.web_runtime) as archive:
        members = [m for m in archive if not m.isdir()]
        names = [m.name for m in members]
        if len(set(names)) != len(names):
            raise ValueError('Duplicate Web archive entry')
        for member in members:
            path = PurePosixPath(member.name)
            if (not member.isfile() or path.is_absolute() or '..' in path.parts
                    or '\\' in member.name or '\n' in member.name or '\r' in member.name
                    or set(path.parts) & {'.env', '.git', '.dev-records'}):
                raise ValueError('Unsafe Web archive entry')
        binaries = [m for m in members if PurePosixPath(m.name).parts[-2:] == ('bin', 'frankenphp')]
        if len(binaries) != 1 or not binaries[0].mode & 0o111:
            raise ValueError('Expected one executable Web binary')
        strip = PurePosixPath(binaries[0].name).parent.parent
        relative = [PurePosixPath(m.name).relative_to(strip) for m in members]
        if PurePosixPath('build-manifest.json') not in relative or not any(
                p.parts[0] == 'notices' and len(p.parts) > 1 for p in relative):
            raise ValueError('Web runtime must include build provenance and license notices')
        with tempfile.TemporaryDirectory(prefix='yanji-web-inspect-') as temporary:
            binary = Path(temporary) / 'frankenphp'
            with binary.open('wb') as output:
                shutil.copyfileobj(archive.extractfile(binaries[0]), output)
            binary_digest = digest(binary)
            verify_web_build_info(read_go_build_info(binary), args.platform)
    return strip.as_posix(), binary_digest


def build(args):
    web_prefix, web_digest = inspect_web(args)
    if digest(args.cli) != args.cli_sha256:
        raise ValueError('Platform input digest mismatch')
    kit_name = args.cli_source_kit.name
    if not re.fullmatch(r'[A-Za-z0-9_.-]+\.tar\.(?:gz|zst)', kit_name) or args.platform not in kit_name:
        raise ValueError('Expected a matching platform-specific corresponding-source/relink kit')
    root = args.source.resolve()
    ref = subprocess.check_output(['git', 'rev-parse', '--verify', 'HEAD^{commit}'], cwd=root, text=True).strip()
    lock = json.loads(subprocess.check_output(['git', 'show', ref + ':composer.lock'], cwd=root))
    npm_lock = json.loads(subprocess.check_output(['git', 'show', ref + ':package-lock.json'], cwd=root))
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
        staged_kit = Path(temporary) / kit_name
        shutil.copyfile(args.cli_source_kit, staged_kit)
        if digest(staged_kit) != args.cli_source_kit_sha256 or kit_name == target.name:
            raise ValueError('CLI corresponding-source/relink kit digest or name mismatch')
        web_kit_name = args.web_source_kit.name
        if web_kit_name in (kit_name, target.name, 'install-local.sh', 'install-local.ps1'):
            raise ValueError('Web material name collides with another release input')
        staged_web_kit = Path(temporary) / web_kit_name
        shutil.copyfile(args.web_source_kit, staged_web_kit)
        if digest(staged_web_kit) != args.web_source_kit_sha256:
            raise ValueError('Web corresponding-source/relink kit changed during staging')
        staged_web_runtime = Path(temporary) / 'web-runtime.tar.gz'
        shutil.copyfile(args.web_runtime, staged_web_runtime)
        if digest(staged_web_runtime) != args.web_runtime_sha256:
            raise ValueError('Web runtime changed during staging')
        output_tar = Path(temporary) / target.name
        with output_tar.open('wb') as output, gzip.GzipFile(filename='', mode='wb', fileobj=output,
                    mtime=0, compresslevel=5) as zipped, tarfile.open(fileobj=zipped, mode='w') as archive:
            archive.file_hashes = {}
            with tarfile.open(source_tar) as code:
                copy_tar(code, archive, 'app')
            add_npm_notices(archive, args.npm_licenses, npm_lock)
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
            with tarfile.open(staged_web_runtime) as web:
                for member in web:
                    if member.isdir():
                        continue
                    relative = PurePosixPath(member.name).relative_to(web_prefix)
                    name = ('runtime/bin/frankenphp' if relative.as_posix() == 'bin/frankenphp'
                            else 'runtime/web/' + relative.as_posix())
                    save_file(archive, name, web.extractfile(member), member.size,
                              0o755 if member.mode & 0o111 else 0o644)
            receipt = json.dumps(dict(commit=ref, platform=args.platform, cli_sha256=args.cli_sha256,
                                      web_sha256=web_digest, web_runtime_sha256=args.web_runtime_sha256,
                                      web_source_kit=web_kit_name, web_source_kit_sha256=args.web_source_kit_sha256,
                                      cli_source_kit=kit_name,
                                      cli_source_kit_sha256=args.cli_source_kit_sha256), indent=2).encode() + b'\n'
            save_file(archive, 'release.json', io.BytesIO(receipt), len(receipt))
            inventory = ''.join(sha + '  ' + name + '\n' for name, sha in sorted(archive.file_hashes.items())).encode()
            save_file(archive, 'PACKAGE-FILES.sha256', io.BytesIO(inventory), len(inventory))
        checks = [(digest(output_tar), target.name), (args.cli_source_kit_sha256, kit_name),
                  (args.web_source_kit_sha256, web_kit_name)]
        for name in ('install-local.sh', 'install-local.ps1'):
            data = subprocess.check_output(['git', 'show', ref + ':scripts/' + name], cwd=root)
            (Path(temporary) / name).write_bytes(data)
            checks.append((hashlib.sha256(data).hexdigest(), name))
        manifest = (''.join(sha + '  ' + name + '\n' for sha, name in checks)).encode()
        for name in ('install-local.sh', 'install-local.ps1', kit_name, web_kit_name, target.name):
            (Path(temporary) / name).replace(args.output / name)
        (args.output / 'SHA256SUMS').write_bytes(manifest)
        print('SHA256SUMS', hashlib.sha256(manifest).hexdigest())


if __name__ == '__main__':
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument('--platform', required=True, choices=['macos-arm64', 'linux-aarch64', 'linux-x86_64'])
    for name in ('source', 'vendor', 'skills', 'assets', 'npm-licenses', 'cli', 'cli-source-kit',
                 'web-runtime', 'web-source-kit', 'output'):
        cli.add_argument('--' + name, type=Path, required=True)
    cli.add_argument('--ca', type=Path)
    cli.add_argument('--ca-notice', type=Path)
    cli.add_argument('--ca-sha256')
    for name in ('cli-sha256', 'cli-source-kit-sha256', 'web-runtime-sha256', 'web-source-kit-sha256'):
        cli.add_argument('--' + name, required=True)
    build(cli.parse_args())
