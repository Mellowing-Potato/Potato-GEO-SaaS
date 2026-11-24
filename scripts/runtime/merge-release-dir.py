#!/usr/bin/env python3
"""Merge verified per-platform portable release directories into one unified SHA256SUMS release directory."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import shutil

RESERVED_FILES = frozenset({'SHA256SUMS', 'get-yanji.sh', 'get-yanji.ps1'})
SAFE_NAME = re.compile(r'^[A-Za-z0-9._-]+$')


def sha256_file(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def parse_sums(path: Path) -> dict[str, str]:
    entries: dict[str, str] = {}
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line:
            continue
        match = re.fullmatch(r'([a-f0-9]{64})  (\S+)', line)
        if not match:
            raise ValueError(f'Invalid SHA256SUMS line in {path}: {raw}')
        digest, name = match.group(1), match.group(2)
        if not SAFE_NAME.fullmatch(name) or name in RESERVED_FILES:
            raise ValueError(f'Disallowed filename in {path}: {name}')
        if name in entries:
            raise ValueError(f'Duplicate SHA256SUMS entry {name} in {path}')
        entries[name] = digest
    if not entries:
        raise ValueError(f'Empty SHA256SUMS in {path}')
    return entries


def _load_bootstrap_module():
    mod_path = Path(__file__).resolve().with_name('make-release-bootstrap.py')
    spec = importlib.util.spec_from_file_location('make_release_bootstrap', mod_path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f'Cannot load {mod_path}')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def merge_release_dirs(inputs: list[Path], output: Path, base_url: str | None = None) -> dict:
    if not inputs:
        raise ValueError('At least one --input directory is required')
    if output.exists():
        raise ValueError(f'Output directory must not already exist: {output}')

    merged: dict[str, tuple[str, Path]] = {}
    for directory in inputs:
        sums_path = directory / 'SHA256SUMS'
        if not sums_path.is_file() or sums_path.is_symlink():
            raise ValueError(f'Missing regular SHA256SUMS in {directory}')
        entries = parse_sums(sums_path)
        for name, expected in entries.items():
            src = directory / name
            if not src.is_file() or src.is_symlink():
                raise ValueError(f'Missing regular file {name} listed in {sums_path}')
            actual = sha256_file(src)
            if actual != expected:
                raise ValueError(f'SHA-256 mismatch for {src}: expected {expected}, got {actual}')
            if name in merged:
                prev_digest, prev_src = merged[name]
                if prev_digest != actual:
                    raise ValueError(
                        f'Conflicting content for {name}: {prev_src} ({prev_digest}) vs {src} ({actual})'
                    )
            else:
                merged[name] = (actual, src)

    output.mkdir(parents=True, exist_ok=False)
    lines = []
    for name in sorted(merged):
        digest, src = merged[name]
        dst = output / name
        shutil.copy2(src, dst)
        if sha256_file(dst) != digest:
            raise RuntimeError(f'Copied file digest mismatch for {dst}')
        lines.append(f'{digest}  {name}\n')

    sums_out = output / 'SHA256SUMS'
    sums_out.write_text(''.join(lines), encoding='utf-8', newline='\n')
    result: dict = {
        'files': sorted(merged),
        'manifest_sha256': sha256_file(sums_out),
    }

    if base_url:
        bootstrap = _load_bootstrap_module()
        boot_info = bootstrap.generate(base_url, sums_out, output)
        result['bootstrappers'] = {k: v for k, v in boot_info.items() if k != 'manifest_sha256'}

    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', dest='inputs', action='append', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--base-url', default=None)
    args = parser.parse_args()
    summary = merge_release_dirs(args.inputs, args.output, args.base_url)
    print(json.dumps(summary, indent=2, ensure_ascii=False))


if __name__ == '__main__':
    main()
