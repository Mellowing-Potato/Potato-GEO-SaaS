#!/usr/bin/env python3
"""Publish one complete, same-source portable release; retain verified rollback bytes first."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import time
import zipfile


PACKAGES = {
    'macos-arm64': 'yanji-macos-arm64.tar.gz',
    'linux-aarch64': 'yanji-linux-aarch64.tar.gz',
    'linux-x86_64': 'yanji-linux-x86_64.tar.gz',
    'windows-x86_64': 'yanji-windows-x86_64.zip',
}
INSTALLERS = {'install-local.sh', 'install-local.ps1'}
BOOTSTRAPS = {'get-yanji.sh', 'get-yanji.ps1'}
SMALL_FILES = INSTALLERS | BOOTSTRAPS | {'SHA256SUMS'}
NAME = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]*\Z')
SHA = re.compile(r'[a-f0-9]{40}\Z')
DIGEST = re.compile(r'sha256:([a-f0-9]{64})\Z')


class ReleaseError(RuntimeError):
    pass


def require(condition, code):
    if not condition:
        raise ReleaseError(code)


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'duplicate_json_key')
        result[key] = value
    return result


def json_bytes(data):
    return json.loads(data, object_pairs_hook=unique_object)


def manifest(data):
    entries = {}
    for line in data.decode('utf-8').splitlines():
        match = re.fullmatch(r'([a-f0-9]{64})  (\S+)', line)
        require(match is not None and NAME.fullmatch(match[2]), 'invalid_manifest_entry')
        sha, name = match.groups()
        require(name not in entries, 'duplicate_manifest_entry')
        entries[name] = sha
    require(len(entries) == 14, 'complete_four_platform_manifest_required')
    return entries


def archive_metadata(path):
    wanted = {'release.json', 'app/VERSION'}
    found = {}
    if path.suffix == '.zip':
        with zipfile.ZipFile(path) as archive:
            for item in archive.infolist():
                if item.filename in wanted:
                    require(item.filename not in found and item.file_size <= 1024 * 1024,
                            'invalid_archive_metadata')
                    require((item.external_attr >> 16) & 0o170000 != 0o120000,
                            'invalid_archive_metadata')
                    found[item.filename] = archive.read(item)
    else:
        with tarfile.open(path, 'r|gz') as archive:
            for item in archive:
                if item.name in wanted:
                    require(item.name not in found and item.isfile() and item.size <= 1024 * 1024,
                            'invalid_archive_metadata')
                    found[item.name] = archive.extractfile(item).read()
    require(set(found) == wanted, 'missing_archive_metadata')
    return json_bytes(found['release.json']), found['app/VERSION'].decode('ascii').strip()


def bootstrap_constants(data, powershell=False):
    if powershell:
        pattern = r'^\s*\$(BaseUrl|ManifestSha256|InstallerSha256)[ \t]*=(.*)$'
        names = {'baseurl': 'base', 'manifestsha256': 'manifest', 'installersha256': 'installer'}
        flags = re.MULTILINE | re.IGNORECASE
    else:
        pattern = r'^[ \t]*(BASE_URL|MANIFEST_SHA256|INSTALLER_SHA256)[ \t]*=(.*)$'
        names = {'base_url': 'base', 'manifest_sha256': 'manifest', 'installer_sha256': 'installer'}
        flags = re.MULTILINE
    result = {}
    for match in re.finditer(pattern, data.decode('utf-8-sig'), flags):
        value = re.fullmatch(r"[ \t]*'([^'\r\n]*)'[ \t]*\r?", match[2])
        key = names[match[1].lower()]
        require(value is not None and key not in result, 'invalid_bootstrap_constants')
        result[key] = value[1]
    require(set(result) == {'base', 'manifest', 'installer'}, 'missing_bootstrap_constants')
    return result


def git_bytes(source, *arguments):
    result = subprocess.run(['git', '-C', str(source), *arguments], capture_output=True, timeout=30)
    require(result.returncode == 0, 'source_git_check_failed')
    return result.stdout


def verify_directory(directory, source, commit, tag, repo):
    require(SHA.fullmatch(commit) is not None, 'invalid_source_commit')
    require(re.fullmatch(r'v\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?', tag), 'invalid_release_tag')
    require(re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repo), 'invalid_repository')
    require(git_bytes(source, 'rev-parse', 'HEAD').decode().strip() == commit,
            'source_head_differs_from_event_commit')
    require(git_bytes(source, 'show', commit + ':VERSION').decode('ascii').strip() == tag[1:],
            'source_version_differs_from_tag')
    require(directory.is_dir() and not directory.is_symlink(), 'regular_release_directory_required')
    files = {path.name: path for path in directory.iterdir()}
    require(len(files) == 17 and all(path.is_file() and not path.is_symlink() for path in files.values()),
            'exactly_seventeen_regular_release_files_required')
    require(SMALL_FILES | set(PACKAGES.values()) <= files.keys(), 'missing_required_release_asset')
    entries = manifest(files['SHA256SUMS'].read_bytes())
    require(set(entries) == set(files) - BOOTSTRAPS - {'SHA256SUMS'}, 'manifest_asset_set_differs')
    for name, expected in entries.items():
        require(digest(files[name]) == expected, 'local_asset_digest_mismatch:' + name)
    kits = set()
    for platform, name in PACKAGES.items():
        receipt, version = archive_metadata(files[name])
        require(receipt.get('commit') == commit, 'package_source_commit_differs:' + platform)
        require(receipt.get('platform') == platform, 'package_platform_differs:' + platform)
        require(version == tag[1:], 'package_version_differs:' + platform)
        for key in ('cli_source_kit', 'web_source_kit'):
            kit = receipt.get(key)
            require(isinstance(kit, str) and NAME.fullmatch(kit) and kit in entries,
                    'missing_corresponding_source_kit:' + platform)
            require(kit not in kits and kit not in set(PACKAGES.values()) | SMALL_FILES,
                    'eight_distinct_corresponding_source_kits_required')
            require(receipt.get(key + '_sha256') == entries[kit], 'source_kit_digest_differs:' + platform)
            kits.add(kit)
    require(len(kits) == 8 and set(entries) == set(PACKAGES.values()) | INSTALLERS | kits,
            'complete_four_platform_asset_set_required')
    for name in INSTALLERS:
        require(files[name].read_bytes() == git_bytes(source, 'show', commit + ':scripts/' + name),
                'installer_differs_from_source:' + name)
    pin = digest(files['SHA256SUMS'])
    base = 'https://github.com/' + repo + '/releases/download/' + tag
    for name, installer in (('get-yanji.sh', 'install-local.sh'), ('get-yanji.ps1', 'install-local.ps1')):
        require(bootstrap_constants(files[name].read_bytes(), name.endswith('.ps1')) == {
            'base': base, 'manifest': pin, 'installer': entries[installer]}, 'bootstrap_pin_differs:' + name)
    return {name: digest(path) for name, path in files.items()}


class GitHub:
    def __init__(self, repo, tag):
        self.repo, self.tag = repo, tag

    def run(self, arguments, *, allow_missing=False):
        result = subprocess.run(['gh', *arguments], capture_output=True, timeout=900)
        if allow_missing and result.returncode != 0 and b'(HTTP 404)' in result.stderr:
            return None
        require(result.returncode == 0, 'github_command_failed:' + arguments[0])
        return result.stdout

    def api(self, path, *, allow_missing=False):
        result = self.run(['api', 'repos/' + self.repo + '/' + path], allow_missing=allow_missing)
        return None if result is None else json_bytes(result)

    def check_tag(self, commit):
        ref = self.api('git/ref/tags/' + self.tag)
        obj = ref.get('object', {})
        for _ in range(5):
            require(isinstance(obj, dict) and isinstance(obj.get('sha'), str)
                    and SHA.fullmatch(obj['sha']), 'invalid_remote_tag_object')
            if obj.get('type') == 'commit':
                require(obj['sha'] == commit, 'remote_tag_differs_from_event_commit')
                return
            require(obj.get('type') == 'tag', 'invalid_remote_tag_type')
            obj = self.api('git/tags/' + obj['sha']).get('object', {})
        raise ReleaseError('remote_tag_indirection_limit')

    def release(self, *, allow_missing=False):
        return self.api('releases/tags/' + self.tag, allow_missing=allow_missing)

    def download(self, directory, names=None):
        arguments = ['release', 'download', self.tag, '--repo', self.repo, '--dir', str(directory)]
        for name in sorted(names or []):
            arguments += ['--pattern', name]
        self.run(arguments)


def remote_assets(metadata, repo, tag, expected, *, complete):
    require(isinstance(metadata, dict) and metadata.get('tag_name') == tag
            and metadata.get('draft') is False and metadata.get('prerelease') is False,
            'published_stable_release_metadata_required')
    assets = {}
    for item in metadata.get('assets', []):
        name = item.get('name')
        require(isinstance(name, str) and NAME.fullmatch(name) and name in expected and name not in assets,
                'unexpected_or_duplicate_remote_asset')
        require(item.get('state') == 'uploaded' and type(item.get('size')) is int and item['size'] > 0,
                'incomplete_remote_asset:' + name)
        evidence = item.get('digest')
        match = DIGEST.fullmatch(evidence) if isinstance(evidence, str) else None
        require(match is not None, 'remote_asset_sha256_unverified:' + name)
        require(item.get('browser_download_url') == 'https://github.com/' + repo + '/releases/download/' + tag + '/' + name,
                'unexpected_remote_asset_url:' + name)
        assets[name] = (match[1], item['size'])
    require(not complete or set(assets) == set(expected), 'remote_release_asset_set_differs')
    return assets


def preserve_previous(github, backup, expected):
    require(not backup.exists(), 'backup_directory_must_not_exist')
    backup.mkdir(parents=True)
    metadata = github.release(allow_missing=True)
    (backup / 'release-metadata.json').write_text(json.dumps(metadata, indent=2) + '\n', encoding='utf-8')
    if metadata is None:
        return False
    assets = remote_assets(metadata, github.repo, github.tag, expected, complete=False)
    materials = backup / 'assets'
    materials.mkdir()
    if assets:
        github.download(materials)
    require({path.name for path in materials.iterdir()} == set(assets), 'backup_asset_set_differs')
    lines = []
    for name, (sha, size) in sorted(assets.items()):
        path = materials / name
        require(path.is_file() and not path.is_symlink() and path.stat().st_size == size
                and digest(path) == sha, 'previous_release_backup_digest_mismatch:' + name)
        lines.append(sha + '  ' + name + '\n')
    (backup / 'SHA256SUMS.previous').write_text(''.join(lines), encoding='ascii')
    (backup / 'BACKUP-VERIFIED').write_text('Previous remote asset bytes match their GitHub SHA-256 digests.\n', encoding='ascii')
    return True


def publish(directory, source, commit, tag, repo, backup, *, sleeper=time.sleep):
    expected = verify_directory(directory, source, commit, tag, repo)
    github = GitHub(repo, tag)
    github.check_tag(commit)
    existed = preserve_previous(github, backup, expected)
    github.check_tag(commit)
    if not existed:
        github.run(['release', 'create', tag, '--repo', repo, '--title', tag, '--generate-notes', '--verify-tag'])
    # Publish trust roots last. Fixed release URLs cannot provide atomic multi-asset replacement.
    order = sorted(set(expected) - SMALL_FILES) + sorted(INSTALLERS) + ['SHA256SUMS'] + sorted(BOOTSTRAPS)
    for name in order:
        for attempt in range(1, 5):
            try:
                github.run(['release', 'upload', tag, str(directory / name), '--repo', repo, '--clobber'])
                break
            except ReleaseError:
                if attempt == 4:
                    raise ReleaseError('asset_upload_retries_exhausted:' + name) from None
                sleeper(attempt * 5)
    github.check_tag(commit)
    assets = remote_assets(github.release(), repo, tag, expected, complete=True)
    for name, (sha, size) in assets.items():
        require(sha == expected[name] and size == (directory / name).stat().st_size,
                'uploaded_remote_asset_digest_mismatch:' + name)
    with tempfile.TemporaryDirectory(prefix='yanji-release-byte-check-') as temporary:
        downloaded = Path(temporary)
        github.download(downloaded, SMALL_FILES)
        require({path.name for path in downloaded.iterdir()} == SMALL_FILES, 'remote_small_asset_set_differs')
        for name in SMALL_FILES:
            require((downloaded / name).read_bytes() == (directory / name).read_bytes(),
                    'remote_small_asset_bytes_differ:' + name)
    return {'tag': tag, 'source_commit': commit, 'asset_count': 17, 'manifest_entry_count': 14,
            'manifest_sha256': expected['SHA256SUMS'], 'previous_release_backed_up': existed,
            'remote_asset_sha256_verified': True, 'remote_small_asset_bytes_verified': True,
            'gui_verified': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release-dir', required=True, type=Path)
    parser.add_argument('--source-dir', required=True, type=Path)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--tag', required=True)
    parser.add_argument('--repo', required=True)
    parser.add_argument('--backup-dir', required=True, type=Path)
    args = parser.parse_args()
    try:
        report = publish(args.release_dir, args.source_dir, args.source_commit, args.tag, args.repo, args.backup_dir)
    except (ReleaseError, OSError, ValueError, subprocess.TimeoutExpired, tarfile.TarError, zipfile.BadZipFile) as error:
        code = str(error) if isinstance(error, ReleaseError) else 'release_verification_or_command_failed'
        print(json.dumps({'release_published': False, 'code': code}))
        return 1
    print(json.dumps(report, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
