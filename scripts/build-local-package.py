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
import zipfile


APP_PATHS = (
    'app', 'artisan', 'bootstrap', 'browser-extension', 'composer.json', 'composer.lock', 'config',
    'database', 'licenses', 'portable', 'public', 'resources', 'routes', 'scripts/install-local.sh',
    'scripts/install-local.ps1', 'skill-overlays',
    'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md', 'VERSION',
)


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


class PackageWriter:
    """Unified tar.gz (Unix) and zip (Windows) release writer with per-file SHA-256 recording."""

    def __init__(self, target_path, is_zip=False):
        self.target_path = Path(target_path)
        self.is_zip = is_zip
        self.file_hashes = {}
        self._raw = None
        self._gz = None
        self._tar = None
        self._zip = None

    def __enter__(self):
        if self.is_zip:
            self._zip = zipfile.ZipFile(self.target_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=5)
        else:
            self._raw = self.target_path.open('wb')
            self._gz = gzip.GzipFile(filename='', mode='wb', fileobj=self._raw, mtime=0, compresslevel=5)
            self._tar = tarfile.open(fileobj=self._gz, mode='w')
        return self

    def __exit__(self, exc_type, exc, tb):
        if self._zip is not None:
            self._zip.close()
        if self._tar is not None:
            self._tar.close()
        if self._gz is not None:
            self._gz.close()
        if self._raw is not None:
            self._raw.close()

    def add_stream(self, name, stream, size, mode=0o644):
        if '\n' in name or '\r' in name or '\\' in name:
            raise ValueError('Unsupported release file name')
        if name in self.file_hashes:
            raise ValueError('Duplicate release file: ' + name)
        checksum = hashlib.sha256()
        if self.is_zip:
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = ((0o100000 | mode) & 0xFFFF) << 16
            written = 0
            with self._zip.open(info, 'w') as out:
                while True:
                    chunk = stream.read(1024 * 1024)
                    if not chunk:
                        break
                    checksum.update(chunk)
                    out.write(chunk)
                    written += len(chunk)
            if written != size:
                raise ValueError('Short read while archiving: ' + name)
        else:
            entry = tarfile.TarInfo(name)
            entry.mode = mode
            entry.size = size

            class Reader:
                def read(self, count):
                    data = stream.read(count)
                    checksum.update(data)
                    return data

            self._tar.addfile(entry, Reader())
        self.file_hashes[name] = checksum.hexdigest()


def save_file(archive, name, stream, size, mode=0o644):
    if isinstance(archive, PackageWriter):
        archive.add_stream(name, stream, size, mode)
        return
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
    if not re.search(r'^.*:\s+go1\.27\.4(?:\s|$)', info, re.M):
        raise ValueError('Web binary differs from the pinned Go toolchain')
    settings = dict(re.findall(r'^\s*build\s+([^=\s]+)=(.*)$', info, re.M))
    tags = set(settings.get('-tags', '').strip('"').split(','))
    if not {'nomercure', 'novulcain'} <= tags or settings.get('CGO_ENABLED') != '1':
        raise ValueError('Web build must retain the explicit exclusion tags and CGO build info')
    expected = {'macos-arm64': ('darwin', 'arm64'), 'linux-aarch64': ('linux', 'arm64'),
                'linux-x86_64': ('linux', 'amd64'), 'windows-x86_64': ('windows', 'amd64')}[platform]
    if (settings.get('GOOS'), settings.get('GOARCH')) != expected:
        raise ValueError('Web build target differs from the package platform')
    for module in ('github.com/caddyserver/caddy/v2', 'github.com/dunglas/frankenphp',
                   'github.com/dunglas/caddy-cbrotli'):
        if not re.search(r'^\s*(?:path|mod|dep)\s+' + re.escape(module) + r'(?:[/\s]|$)', info, re.M):
            raise ValueError('Missing required Web module: ' + module)
    for module, version in (('github.com/caddyserver/caddy/v2', 'v2.11.9'),
                            ('github.com/dunglas/caddy-cbrotli', 'v1.0.1')):
        if not re.search(r'^\s*dep\s+' + re.escape(module) + r'\s+' + re.escape(version) + r'(?:\s|$)', info, re.M):
            raise ValueError('Web module differs from the source lock: ' + module)


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
        web_exe_name = 'frankenphp.exe' if args.platform == 'windows-x86_64' else 'frankenphp'
        binaries = [m for m in members if PurePosixPath(m.name).parts[-2:] == ('bin', web_exe_name)]
        if len(binaries) != 1 or (args.platform != 'windows-x86_64' and not (binaries[0].mode & 0o111)):
            raise ValueError('Expected one executable Web binary')
        strip = PurePosixPath(binaries[0].name).parent.parent
        relative = [PurePosixPath(m.name).relative_to(strip) for m in members]
        if PurePosixPath('build-manifest.json') not in relative or not any(
                p.parts[0] == 'notices' and len(p.parts) > 1 for p in relative):
            raise ValueError('Web runtime must include build provenance and license notices')
        with tempfile.TemporaryDirectory(prefix='yanji-web-inspect-') as temporary:
            binary = Path(temporary) / web_exe_name
            with binary.open('wb') as output:
                shutil.copyfileobj(archive.extractfile(binaries[0]), output)
            binary_digest = digest(binary)
            manifest_member = next(m for m, p in zip(members, relative)
                                   if p == PurePosixPath('build-manifest.json'))
            manifest = json.load(archive.extractfile(manifest_member))
            if (not isinstance(manifest, dict) or manifest.get('binary_sha256') != binary_digest
                    or manifest.get('source_kit') != {'name': kit, 'sha256': args.web_source_kit_sha256}):
                raise ValueError('Web binary and corresponding-source/relink kit are not paired')
            verify_web_build_info(read_go_build_info(binary), args.platform)
    return strip.as_posix(), binary_digest


def inspect_python_runtime(args):
    runtime = getattr(args, 'python_runtime', None)
    runtime_sha = getattr(args, 'python_runtime_sha256', None)
    if runtime is None and runtime_sha is None:
        return None
    if runtime is None or not runtime_sha:
        raise ValueError('Both --python-runtime and --python-runtime-sha256 are required together')
    if digest(runtime) != runtime_sha:
        raise ValueError('Python runtime digest mismatch')
    expected_exe = ('python.exe',) if args.platform == 'windows-x86_64' else ('bin', 'python3')
    with tarfile.open(runtime) as archive:
        members = [m for m in archive if not m.isdir()]
        names = [m.name for m in members]
        if len(set(names)) != len(names):
            raise ValueError('Duplicate Python archive entry')
        for member in members:
            path = PurePosixPath(member.name)
            if (not member.isfile() or path.is_absolute() or '..' in path.parts
                    or '\\' in member.name or '\n' in member.name or '\r' in member.name
                    or set(path.parts) & {'.env', '.git', '.dev-records'}):
                raise ValueError('Unsafe Python archive entry')
        exes = [m for m in members if PurePosixPath(m.name).parts[-len(expected_exe):] == expected_exe]
        if len(exes) != 1 or (args.platform != 'windows-x86_64' and not (exes[0].mode & 0o111)):
            raise ValueError('Expected one executable bundled Python interpreter')
        strip = PurePosixPath(exes[0].name)
        for _ in expected_exe:
            strip = strip.parent
        relative = [PurePosixPath(m.name).relative_to(strip) for m in members]
        if PurePosixPath('build-manifest.json') not in relative or not any(
                p.parts[0] == 'notices' and len(p.parts) > 1 for p in relative):
            raise ValueError('Python runtime must include build provenance and license notices')
        manifest_member = next(m for m, p in zip(members, relative) if p == PurePosixPath('build-manifest.json'))
        manifest = json.load(archive.extractfile(manifest_member))
        if not isinstance(manifest, dict) or manifest.get('platform') != args.platform:
            raise ValueError('Python runtime platform differs from package platform')
    return strip.as_posix(), runtime_sha


def inspect_fonts(args):
    fonts = getattr(args, 'fonts', None)
    fonts_sha = getattr(args, 'fonts_sha256', None)
    if fonts is None and fonts_sha is None:
        return None
    if fonts is None or not fonts_sha:
        raise ValueError('Both --fonts and --fonts-sha256 are required together')
    if digest(fonts) != fonts_sha:
        raise ValueError('Report fonts digest mismatch')
    required_fonts = {'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'NotoSerifCJK-Regular.ttc'}
    with tarfile.open(fonts) as archive:
        members = [m for m in archive if not m.isdir()]
        names = [m.name for m in members]
        if len(set(names)) != len(names):
            raise ValueError('Duplicate fonts archive entry')
        for member in members:
            path = PurePosixPath(member.name)
            if (not member.isfile() or path.is_absolute() or '..' in path.parts
                    or '\\' in member.name or '\n' in member.name or '\r' in member.name
                    or set(path.parts) & {'.env', '.git', '.dev-records'}):
                raise ValueError('Unsafe fonts archive entry')
        anchors = [m for m in members if PurePosixPath(m.name).name == 'DejaVuSans.ttf']
        if len(anchors) != 1:
            raise ValueError('Expected DejaVuSans.ttf in report fonts archive')
        strip = PurePosixPath(anchors[0].name).parent
        relative = [PurePosixPath(m.name).relative_to(strip) for m in members]
        top_files = {p.as_posix() for p in relative}
        if not required_fonts <= top_files or not any(p.parts[0] == 'notices' and len(p.parts) > 1 for p in relative):
            raise ValueError('Report fonts archive must include all required fonts and license notices')
    return strip.as_posix(), fonts_sha


def build(args):
    web_prefix, web_digest = inspect_web(args)
    python_info = inspect_python_runtime(args)
    fonts_info = inspect_fonts(args)
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
    is_windows = args.platform == 'windows-x86_64'
    target = args.output / ('yanji-' + args.platform + ('.zip' if is_windows else '.tar.gz'))
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
        staged_python_runtime = None
        if python_info is not None:
            staged_python_runtime = Path(temporary) / 'python-runtime.tar.gz'
            shutil.copyfile(args.python_runtime, staged_python_runtime)
            if digest(staged_python_runtime) != args.python_runtime_sha256:
                raise ValueError('Python runtime changed during staging')
        staged_fonts = None
        if fonts_info is not None:
            staged_fonts = Path(temporary) / 'report-fonts.tar.gz'
            shutil.copyfile(args.fonts, staged_fonts)
            if digest(staged_fonts) != args.fonts_sha256:
                raise ValueError('Report fonts archive changed during staging')
        output_tar = Path(temporary) / target.name
        with PackageWriter(output_tar, is_zip=is_windows) as archive:
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
                cli_exe_name = 'php.exe' if is_windows else 'php'
                binaries = [name for name in names if PurePosixPath(name).parts[-2:] == ('bin', cli_exe_name)]
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
                    rel_posix = relative.as_posix()
                    if rel_posix == ('bin/frankenphp.exe' if is_windows else 'bin/frankenphp'):
                        name = 'runtime/' + rel_posix
                    elif is_windows and relative.parts[0] == 'bin' and rel_posix.lower().endswith('.dll'):
                        name = 'runtime/' + rel_posix
                    else:
                        name = 'runtime/web/' + rel_posix
                    save_file(archive, name, web.extractfile(member), member.size,
                              0o755 if member.mode & 0o111 else 0o644)
            if staged_python_runtime is not None:
                python_prefix, _ = python_info
                with tarfile.open(staged_python_runtime) as py_arc:
                    copy_tar(py_arc, archive, 'runtime/python', python_prefix)
            if staged_fonts is not None:
                fonts_prefix, _ = fonts_info
                with tarfile.open(staged_fonts) as font_arc:
                    copy_tar(font_arc, archive, 'runtime/fonts', fonts_prefix)
            receipt_data = dict(commit=ref, platform=args.platform, cli_sha256=args.cli_sha256,
                                web_sha256=web_digest, web_runtime_sha256=args.web_runtime_sha256,
                                web_source_kit=web_kit_name, web_source_kit_sha256=args.web_source_kit_sha256,
                                cli_source_kit=kit_name,
                                cli_source_kit_sha256=args.cli_source_kit_sha256)
            if python_info is not None:
                receipt_data['python_runtime_sha256'] = args.python_runtime_sha256
            if fonts_info is not None:
                receipt_data['fonts_sha256'] = args.fonts_sha256
            receipt = json.dumps(receipt_data, indent=2).encode() + b'\n'
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
    cli.add_argument('--platform', required=True, choices=['macos-arm64', 'linux-aarch64', 'linux-x86_64', 'windows-x86_64'])
    for name in ('source', 'vendor', 'skills', 'assets', 'npm-licenses', 'cli', 'cli-source-kit',
                 'web-runtime', 'web-source-kit', 'output'):
        cli.add_argument('--' + name, type=Path, required=True)
    cli.add_argument('--ca', type=Path)
    cli.add_argument('--ca-notice', type=Path)
    cli.add_argument('--ca-sha256')
    cli.add_argument('--python-runtime', type=Path)
    cli.add_argument('--python-runtime-sha256')
    cli.add_argument('--fonts', type=Path)
    cli.add_argument('--fonts-sha256')
    for name in ('cli-sha256', 'cli-source-kit-sha256', 'web-runtime-sha256', 'web-source-kit-sha256'):
        cli.add_argument('--' + name, required=True)
    build(cli.parse_args())
