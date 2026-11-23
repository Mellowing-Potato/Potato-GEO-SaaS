#!/usr/bin/env python3
"""Generate pinned one-line get-yanji.sh and get-yanji.ps1 bootstrappers for a verified release directory."""
import argparse
import hashlib
from pathlib import Path
import re


def sha256_file(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def parse_sums(path: Path) -> dict[str, str]:
    entries = {}
    for line in path.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if not line:
            continue
        m = re.fullmatch(r'([a-f0-9]{64})  (\S+)', line)
        if not m:
            raise ValueError('Invalid SHA256SUMS line: ' + line)
        entries[m.group(2)] = m.group(1)
    return entries


def generate(base_url: str, sums_path: Path, output_dir: Path) -> dict[str, str]:
    base_url = base_url.rstrip('/')
    if not base_url.startswith('https://'):
        raise ValueError('Release base URL must use HTTPS')
    sums_sha = sha256_file(sums_path)
    entries = parse_sums(sums_path)
    for required in ('install-local.sh', 'install-local.ps1'):
        if required not in entries:
            raise ValueError('SHA256SUMS missing required installer entry: ' + required)

    sh_script = f'''#!/bin/sh
set -eu
umask 077
BASE_URL='{base_url}'
MANIFEST_SHA256='{sums_sha}'
INSTALLER_SHA256='{entries["install-local.sh"]}'
fail() {{ printf '%s\\n' "$1" >&2; exit 1; }}
if command -v sha256sum >/dev/null 2>&1; then
    hash() {{ sha256sum < "$1" | awk '{{print $1}}'; }}
elif command -v shasum >/dev/null 2>&1; then
    hash() {{ shasum -a 256 < "$1" | awk '{{print $1}}'; }}
else
    fail '缺少系统 SHA-256 验证工具。'
fi
tmp=$(mktemp -d)
cleanup() {{ rm -rf "$tmp"; }}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --output "$tmp/install-local.sh" "$BASE_URL/install-local.sh"
[ "$(hash "$tmp/install-local.sh")" = "$INSTALLER_SHA256" ] || fail '安装脚本校验失败。'
if [ ! -t 0 ] && [ -r /dev/tty ]; then
    exec /bin/sh "$tmp/install-local.sh" --manifest "$BASE_URL/SHA256SUMS" --manifest-sha256 "$MANIFEST_SHA256" "$@" </dev/tty
else
    exec /bin/sh "$tmp/install-local.sh" --manifest "$BASE_URL/SHA256SUMS" --manifest-sha256 "$MANIFEST_SHA256" "$@"
fi
'''

    ps1_script = f'''#Requires -Version 5.1
& {{
    $ErrorActionPreference = 'Stop'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $BaseUrl = '{base_url}'
    $ManifestSha256 = '{sums_sha}'
    $InstallerSha256 = '{entries["install-local.ps1"]}'
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("yanji-get-" + [Guid]::NewGuid().ToString("N").Substring(0, 12))
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    try {{
        $installer = Join-Path $tmp 'install-local.ps1'
        $current = "$BaseUrl/install-local.ps1"
        for ($hops = 0; $hops -lt 10; $hops++) {{
            if ($current -notmatch '^https://') {{ throw '远端安装脚本与重定向仅接受 HTTPS。' }}
            $req = [System.Net.HttpWebRequest]::Create($current)
            $req.AllowAutoRedirect = $false
            $req.UserAgent = 'Yanji-Bootstrap/1.0'
            $resp = $req.GetResponse()
            try {{
                $code = [int]$resp.StatusCode
                if ($code -in 301, 302, 303, 307, 308) {{
                    $loc = $resp.Headers['Location']
                    if (-not $loc) {{ throw '远端重定向缺少目标地址。' }}
                    $current = ([System.Uri]::new([System.Uri]::new($current), $loc)).AbsoluteUri
                    continue
                }}
                $inStream = $resp.GetResponseStream()
                $outStream = [System.IO.File]::Create($installer)
                try {{ $inStream.CopyTo($outStream) }} finally {{ $outStream.Dispose(); $inStream.Dispose() }}
                break
            }} finally {{
                $resp.Dispose()
            }}
        }}
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $fs = [System.IO.File]::OpenRead($installer)
        try {{
            $actual = ([System.BitConverter]::ToString($sha.ComputeHash($fs)) -replace '-', '').ToLowerInvariant()
        }} finally {{
            $fs.Dispose()
            $sha.Dispose()
        }}
        if ($actual -ne $InstallerSha256) {{
            throw '安装脚本 SHA-256 校验失败。'
        }}
        $psHost = if ($PSVersionTable.PSEdition -eq 'Core') {{ 'pwsh' }} else {{ 'powershell.exe' }}
        & $psHost -NoProfile -ExecutionPolicy Bypass -File $installer -Manifest "$BaseUrl/SHA256SUMS" -ManifestSha256 $ManifestSha256 @args
        if ($LASTEXITCODE -ne 0) {{
            throw "安装脚本返回失败（退出码 $LASTEXITCODE）。"
        }}
    }} finally {{
        if (Test-Path -LiteralPath $tmp) {{
            Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
        }}
    }}
}}
'''

    output_dir.mkdir(parents=True, exist_ok=True)
    sh_out = output_dir / 'get-yanji.sh'
    ps1_out = output_dir / 'get-yanji.ps1'
    sh_out.write_text(sh_script, encoding='utf-8', newline='\n')
    ps1_out.write_text(ps1_script, encoding='utf-8', newline='\r\n')
    sh_out.chmod(0o755)
    return {
        'manifest_sha256': sums_sha,
        'get-yanji.sh': sha256_file(sh_out),
        'get-yanji.ps1': sha256_file(ps1_out),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--sums', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = generate(args.base_url, args.sums, args.output)
    for k, v in result.items():
        print(f'{k}: {v}')


if __name__ == '__main__':
    main()
