#!/usr/bin/env python3
"""Build verified, relocatable Python runtime and report-font bundles for portable releases."""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
SPEC_PATH = ROOT / 'licenses/portable-python.json'
FONT_LICENSES_PATH = ROOT / 'licenses/font-sources.json'

PRUNE_UNIX = (
    'include',
    'share/man',
    'share/terminfo',
    'lib/pkgconfig',
    'lib/python3.12/test',
    'lib/python3.12/idlelib',
    'lib/python3.12/tkinter',
    'lib/python3.12/turtledemo',
    'lib/python3.12/ensurepip',
    'lib/python3.12/site-packages/pip',
)
PRUNE_WINDOWS = (
    'include',
    'libs',
    'tcl',
    'Lib/test',
    'Lib/idlelib',
    'Lib/tkinter',
    'Lib/turtledemo',
    'Lib/ensurepip',
    'Lib/site-packages/pip',
)


def sha256_file(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def fetch_verified(url: str, expected_sha256: str, destination: Path, cache_dir: Path | None = None) -> Path:
    if not url.startswith('https://'):
        raise ValueError('Only HTTPS build inputs are allowed: ' + url)
    target = (cache_dir / f'{expected_sha256}-{Path(url.split("?")[0]).name}') if cache_dir else destination
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.is_file() and sha256_file(target) == expected_sha256:
        if target != destination:
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(target, destination)
        return destination
    tmp = target.with_suffix(target.suffix + '.tmp')
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'YanjiPortableBuilder/1.0'})
        with urllib.request.urlopen(req, timeout=180) as response, tmp.open('wb') as out:
            shutil.copyfileobj(response, out)
        actual = sha256_file(tmp)
        if actual != expected_sha256:
            raise ValueError(f'Digest mismatch for {url}: expected {expected_sha256}, got {actual}')
        tmp.replace(target)
    finally:
        if tmp.exists():
            tmp.unlink()
    if target != destination:
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(target, destination)
    return destination


def pack_deterministic_tar_gz(source_dir: Path, prefix: str, output_tar: Path) -> str:
    output_tar.parent.mkdir(parents=True, exist_ok=True)
    files = sorted(p for p in source_dir.rglob('*') if not p.is_dir())
    with output_tar.open('wb') as raw, gzip.GzipFile(
        filename='', mode='wb', fileobj=raw, mtime=0, compresslevel=6
    ) as gz, tarfile.open(fileobj=gz, mode='w') as archive:
        for path in files:
            if path.is_symlink() or not path.is_file():
                raise ValueError('Refusing non-regular file or symlink in bundle: ' + str(path))
            rel = path.relative_to(source_dir).as_posix()
            entry = tarfile.TarInfo(str(PurePosixPath(prefix) / rel))
            st = path.stat()
            entry.size = st.st_size
            entry.mtime = 0
            entry.mode = 0o755 if (st.st_mode & 0o111) else 0o644
            with path.open('rb') as stream:
                archive.addfile(entry, stream)
    return sha256_file(output_tar)


def collect_python_notices(python_exe: Path, python_root: Path, pbs_license: Path, notices_dir: Path) -> list[dict]:
    notices_dir.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(pbs_license, notices_dir / 'python-build-standalone-LICENSE.txt')
    for candidate in (
        python_root / 'lib/python3.12/LICENSE.txt',
        python_root / 'LICENSE.txt',
    ):
        if candidate.is_file():
            shutil.copyfile(candidate, notices_dir / 'CPython-LICENSE.txt')
            break
    else:
        raise RuntimeError('CPython LICENSE.txt not found in standalone distribution')

    collector = r'''
import importlib.metadata, json, re, shutil
from pathlib import Path

notices_dir = Path(__import__("sys").argv[1])
pat = re.compile(r'^(licen[sc]e|copying|copyright|notice|ofl|authors)([._-]|$)', re.I)
rows = []
for dist in sorted(importlib.metadata.distributions(), key=lambda d: d.metadata["Name"].lower()):
    name = dist.metadata["Name"]
    if name.lower() in {"pip", "setuptools", "wheel"}:
        continue
    root = Path(dist.locate_file("")).resolve()
    copied = []
    for rel in sorted(dist.files or []):
        if ".." in rel.parts:
            continue
        if any(pat.match(part) for part in rel.parts):
            src = Path(dist.locate_file(rel))
            if src.is_file() and not src.is_symlink():
                dst = notices_dir / "pypi" / name / Path(*rel.parts)
                dst.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(src, dst)
                copied.append(Path(*rel.parts).as_posix())
    if not copied:
        meta_text = dist.read_text("METADATA") or ""
        dst = notices_dir / "pypi" / name / "METADATA.txt"
        dst.parent.mkdir(parents=True, exist_ok=True)
        dst.write_text(meta_text, encoding="utf-8")
        copied.append("METADATA.txt")
    rows.append({
        "ecosystem": "pypi",
        "name": name,
        "version": dist.version,
        "license": dist.metadata.get("License-Expression") or dist.metadata.get("License"),
        "classifiers": dist.metadata.get_all("Classifier", []),
        "notices": copied,
    })
print(json.dumps(rows, ensure_ascii=False))
'''
    output = subprocess.check_output([str(python_exe), '-I', '-c', collector, str(notices_dir)], text=True)
    rows = json.loads(output)
    (notices_dir / 'inventory.json').write_text(json.dumps(rows, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    return rows


def prune_python_tree(python_root: Path, is_windows: bool) -> None:
    targets = PRUNE_WINDOWS if is_windows else PRUNE_UNIX
    for rel in targets:
        path = python_root / rel
        if path.is_symlink() or path.is_file():
            path.unlink()
        elif path.is_dir():
            shutil.rmtree(path)
    # Remove pip dist-info, tcl/tk shared libs, and bytecode caches.
    site_packages = python_root / ('Lib/site-packages' if is_windows else 'lib/python3.12/site-packages')
    for item in list(site_packages.glob('pip-*.dist-info')):
        shutil.rmtree(item)
    if not is_windows:
        bin_dir = python_root / 'bin'
        real_bin = bin_dir / 'python3.12'
        py3 = bin_dir / 'python3'
        if py3.is_symlink():
            py3.unlink()
        if real_bin.is_file() and not real_bin.is_symlink():
            real_bin.replace(py3)
        for item in list(bin_dir.iterdir()):
            if item.name != 'python3':
                if item.is_dir() and not item.is_symlink():
                    shutil.rmtree(item)
                else:
                    item.unlink()
        for pattern in ('libtcl*', 'libtk*', 'tcl*', 'tk*', 'itcl*', 'tdbc*', 'thread*', 'libpython*.so'):
            for item in list((python_root / 'lib').glob(pattern)):
                if item.name.endswith('.so') and not item.is_symlink():
                    continue
                if item.is_dir() and not item.is_symlink():
                    shutil.rmtree(item)
                else:
                    item.unlink()
        for item in list((python_root / 'lib/python3.12/lib-dynload').glob('_tkinter*')):
            item.unlink()
    else:
        for item in list(python_root.glob('Scripts/*')):
            if item.is_dir():
                shutil.rmtree(item)
            else:
                item.unlink()
        scripts_dir = python_root / 'Scripts'
        if scripts_dir.is_dir():
            shutil.rmtree(scripts_dir)
        for pattern in ('DLLs/_tkinter*', 'DLLs/tcl*', 'DLLs/tk*', 'pythonw.exe'):
            for item in list(python_root.glob(pattern)):
                if item.is_file():
                    item.unlink()
    for cache_dir in list(python_root.rglob('__pycache__')):
        if cache_dir.is_dir():
            shutil.rmtree(cache_dir)
    for pyc in list(python_root.rglob('*.pyc')):
        pyc.unlink()
    # Reject any surviving symlink so portable package verification stays strict.
    symlinks = [p.relative_to(python_root).as_posix() for p in python_root.rglob('*') if p.is_symlink()]
    if symlinks:
        raise RuntimeError('Unexpected symlinks remain in portable Python tree: ' + ', '.join(symlinks[:10]))


def self_verify_python(python_exe: Path) -> dict:
    probe = r'''
import json, sys
import bs4, markdown, reportlab, pypdf, brotli
import fontTools.subset
from docx import Document
doc = Document()
doc.add_paragraph("yanji")
print(json.dumps({
    "version": sys.version.split()[0],
    "packages": ["bs4", "markdown", "docx", "reportlab", "pypdf", "fontTools.subset", "brotli"],
}))
'''
    out = subprocess.check_output([str(python_exe), '-I', '-c', probe], text=True)
    return json.loads(out)


def build_python(platform: str, output_dir: Path, cache_dir: Path | None, pip_index_url: str | None) -> None:
    spec = json.loads(SPEC_PATH.read_text(encoding='utf-8'))
    pbs = spec['python_build_standalone']
    plat = spec['platforms'][platform]
    is_windows = platform == 'windows-x86_64'

    with tempfile.TemporaryDirectory(prefix=f'yanji-py-{platform}-') as tmp_name:
        tmp = Path(tmp_name)
        archive = fetch_verified(plat['url'], plat['sha256'], tmp / plat['asset'], cache_dir)
        pbs_license = fetch_verified(pbs['license_url'], pbs['license_sha256'], tmp / 'PBS-LICENSE.txt', cache_dir)

        extract_root = tmp / 'extract'
        extract_root.mkdir()
        with tarfile.open(archive) as tf:
            tf.extractall(extract_root, filter='tar')
        python_root = extract_root / 'python'
        if not python_root.is_dir():
            raise RuntimeError('Standalone archive did not contain top-level python/ directory')

        installer_exe = python_root / ('python.exe' if is_windows else 'bin/python3.12')
        if not installer_exe.is_file():
            installer_exe = python_root / plat['executable']

        pip_cmd = [
            str(installer_exe), '-m', 'pip', 'install',
            '--no-cache-dir', '--disable-pip-version-check',
            '--only-binary=:all:', '--no-deps',
        ]
        if pip_index_url:
            pip_cmd.extend(['--index-url', pip_index_url])
        pip_cmd.extend(spec['packages'])
        subprocess.run(pip_cmd, check=True)

        # Verify exact installed package versions match the pinned list.
        expected_versions = {}
        for req in spec['packages']:
            name, ver = req.split('==', 1)
            expected_versions[re.sub(r'[-_.]+', '-', name).lower()] = ver

        notices_dir = python_root / 'notices'
        rows = collect_python_notices(installer_exe, python_root, pbs_license, notices_dir)
        actual_versions = {re.sub(r'[-_.]+', '-', row['name']).lower(): row['version'] for row in rows}
        if actual_versions != expected_versions:
            raise RuntimeError(f'Installed Python packages differ from lock: {actual_versions} != {expected_versions}')

        prune_python_tree(python_root, is_windows)
        final_exe = python_root / plat['executable']
        probe = self_verify_python(final_exe)

        manifest = {
            'schema_version': 1,
            'platform': platform,
            'python_version': probe['version'],
            'upstream_release': pbs['release_tag'],
            'upstream_asset': plat['asset'],
            'upstream_sha256': plat['sha256'],
            'executable': plat['executable'],
            'executable_sha256': sha256_file(final_exe),
            'packages': spec['packages'],
        }
        (python_root / 'build-manifest.json').write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8'
        )

        out_tar = output_dir / f'yanji-python-{platform}.tar.gz'
        digest = pack_deterministic_tar_gz(python_root, 'python', out_tar)
        print(json.dumps({
            'platform': platform,
            'archive': str(out_tar),
            'sha256': digest,
            'bytes': out_tar.stat().st_size,
            'python_version': probe['version'],
            'packages': len(rows),
        }, indent=2))


def build_fonts(output_dir: Path, cache_dir: Path | None) -> None:
    spec = json.loads(SPEC_PATH.read_text(encoding='utf-8'))['report_fonts']
    font_licenses = json.loads(FONT_LICENSES_PATH.read_text(encoding='utf-8'))

    with tempfile.TemporaryDirectory(prefix='yanji-fonts-') as tmp_name:
        tmp = Path(tmp_name)
        stage = tmp / 'fonts'
        stage.mkdir()

        dejavu = spec['dejavu_archive']
        dejavu_tar = fetch_verified(dejavu['url'], dejavu['sha256'], tmp / 'dejavu.tar.bz2', cache_dir)
        with tarfile.open(dejavu_tar, 'r:bz2') as tf:
            for out_name, meta in dejavu['files'].items():
                member = tf.getmember(meta['member'])
                dest = stage / out_name
                with tf.extractfile(member) as src, dest.open('wb') as dst:
                    shutil.copyfileobj(src, dst)
                if sha256_file(dest) != meta['sha256']:
                    raise ValueError('Extracted font digest mismatch: ' + out_name)

        noto = spec['noto_serif_cjk']
        fetch_verified(noto['url'], noto['sha256'], stage / noto['filename'], cache_dir)

        notices = stage / 'notices'
        for item in font_licenses:
            fname = item.get('filename', 'LICENSE')
            fetch_verified(item['url'], item['sha256'], notices / item['name'] / fname, cache_dir)

        manifest = {
            'schema_version': 1,
            'files': {
                name: sha256_file(stage / name)
                for name in ('DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'NotoSerifCJK-Regular.ttc')
            },
        }
        (stage / 'build-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')

        out_tar = output_dir / 'yanji-report-fonts.tar.gz'
        digest = pack_deterministic_tar_gz(stage, 'fonts', out_tar)
        print(json.dumps({
            'archive': str(out_tar),
            'sha256': digest,
            'bytes': out_tar.stat().st_size,
        }, indent=2))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', required=True, choices=['python', 'fonts'])
    parser.add_argument('--platform', choices=['macos-arm64', 'linux-aarch64', 'linux-x86_64', 'windows-x86_64'])
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--cache-dir', type=Path)
    parser.add_argument('--pip-index-url')
    args = parser.parse_args()
    if args.mode == 'python':
        if not args.platform:
            parser.error('--platform is required for --mode python')
        build_python(args.platform, args.output, args.cache_dir, args.pip_index_url)
    else:
        build_fonts(args.output, args.cache_dir)


if __name__ == '__main__':
    main()
