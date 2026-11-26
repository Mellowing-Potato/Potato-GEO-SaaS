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


def ps_unicode_literal(text: str) -> str:
    """Encode non-ASCII characters as \\uXXXX so get-yanji.ps1 stays 100% 7-bit ASCII.

    Windows PowerShell 5.1 decodes octet-stream responses without charset (irm | iex)
    and BOM-less .ps1 files (-File) using legacy single-byte or DBCS codepages such as
    GBK (CP936) and Windows-1252, which corrupt raw UTF-8 multibyte bytes into smart
    quotes or swallowed delimiters. Pure 7-bit ASCII parses identically in every locale.
    """
    out = []
    for ch in text:
        code = ord(ch)
        if 0x20 <= code <= 0x7E and ch not in ("'", '\\'):
            out.append(ch)
        else:
            out.append(f'\\u{code:04x}')
    return ''.join(out)


def generate(base_url: str, sums_path: Path, output_dir: Path) -> dict[str, str]:
    base_url = base_url.rstrip('/')
    if not base_url.startswith('https://'):
        raise ValueError('Release base URL must use HTTPS')
    sums_sha = sha256_file(sums_path)
    entries = parse_sums(sums_path)
    unix_packages = {'yanji-macos-arm64.tar.gz', 'yanji-linux-aarch64.tar.gz', 'yanji-linux-x86_64.tar.gz'}
    has_sh = 'install-local.sh' in entries and any(name in entries for name in unix_packages)
    has_ps1 = 'install-local.ps1' in entries and 'yanji-windows-x86_64.zip' in entries
    if not has_sh and not has_ps1:
        raise ValueError('SHA256SUMS missing matching platform archive and installer pair')

    output_dir.mkdir(parents=True, exist_ok=True)
    # Remove only generated entry points whose platforms disappeared from this manifest.
    if not has_sh:
        (output_dir / 'get-yanji.sh').unlink(missing_ok=True)
    if not has_ps1:
        (output_dir / 'get-yanji.ps1').unlink(missing_ok=True)
    result = {'manifest_sha256': sums_sha}

    if has_sh:
        sh_script = f'''#!/bin/sh
set -eu
umask 077
BASE_URL='{base_url}'
MANIFEST_SHA256='{sums_sha}'
INSTALLER_SHA256='{entries["install-local.sh"]}'
fail() {{ printf '%s\\n' "$1" >&2; exit 1; }}
step() {{ printf '%s\\n' "$1" >&2; }}
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
step '正在获取并校验言及安装脚本...'
curl --fail --silent --show-error --location --connect-timeout 30 --proto '=https' --proto-redir '=https' --output "$tmp/install-local.sh" "$BASE_URL/install-local.sh"
[ "$(hash "$tmp/install-local.sh")" = "$INSTALLER_SHA256" ] || fail '安装脚本校验失败。'
if [ ! -t 0 ] && (exec </dev/tty) 2>/dev/null; then
    /bin/sh "$tmp/install-local.sh" --manifest "$BASE_URL/SHA256SUMS" --manifest-sha256 "$MANIFEST_SHA256" "$@" </dev/tty
else
    /bin/sh "$tmp/install-local.sh" --manifest "$BASE_URL/SHA256SUMS" --manifest-sha256 "$MANIFEST_SHA256" "$@"
fi
'''
        sh_out = output_dir / 'get-yanji.sh'
        sh_out.write_text(sh_script, encoding='utf-8', newline='\n')
        sh_out.chmod(0o755)
        result['get-yanji.sh'] = sha256_file(sh_out)

    if has_ps1:
        msg_fetch = ps_unicode_literal('正在获取并校验言及安装脚本...')
        msg_https = ps_unicode_literal('远端安装脚本与重定向仅接受 HTTPS。')
        msg_noloc = ps_unicode_literal('远端重定向缺少目标地址。')
        msg_status = ps_unicode_literal('远端下载返回异常状态码：')
        msg_hops = ps_unicode_literal('远端下载重定向次数过多。')
        msg_sha = ps_unicode_literal('安装脚本 SHA-256 校验失败。')
        msg_exit_prefix = ps_unicode_literal('安装脚本返回失败（退出码 ')
        msg_exit_suffix = ps_unicode_literal('）。')
        ps1_script = f'''#Requires -Version 5.1
& {{
    $ErrorActionPreference = 'Stop'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $BaseUrl = '{base_url}'
    $ManifestSha256 = '{sums_sha}'
    $InstallerSha256 = '{entries["install-local.ps1"]}'
    function Msg([string]$Escaped) {{ return [regex]::Unescape($Escaped) }}
    function Apply-WebProxy($Request) {{
        foreach ($name in @('HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy')) {{
            $val = [Environment]::GetEnvironmentVariable($name)
            if ($val -and $val -match '^https?://') {{
                $Request.Proxy = [System.Net.WebProxy]::new($val)
                return
            }}
        }}
        $sysProxy = [System.Net.WebRequest]::GetSystemWebProxy()
        if ($null -ne $sysProxy) {{
            $sysProxy.Credentials = [System.Net.CredentialCache]::DefaultCredentials
            $Request.Proxy = $sysProxy
        }}
    }}
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("yanji-get-" + [Guid]::NewGuid().ToString("N").Substring(0, 12))
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    try {{
        [Console]::Error.WriteLine((Msg '{msg_fetch}'))
        $installer = Join-Path $tmp 'install-local.ps1'
        $current = "$BaseUrl/install-local.ps1"
        if ($current -notmatch '^https://') {{ throw (Msg '{msg_https}') }}
        $downloaded = $false
        $sysCurl = if ($env:SystemRoot) {{ Join-Path $env:SystemRoot 'System32\\curl.exe' }} else {{ '' }}
        if ($sysCurl -and (Test-Path -LiteralPath $sysCurl -PathType Leaf)) {{
            & $sysCurl --fail --silent --show-error --location --tlsv1.2 --connect-timeout 20 --max-redirs 10 --retry 2 --retry-delay 1 --proto '=https' --proto-redir '=https' --user-agent 'Yanji-Bootstrap/1.0' --output $installer $current
            if ($LASTEXITCODE -eq 0 -and (Test-Path -LiteralPath $installer -PathType Leaf)) {{
                $downloaded = $true
            }} else {{
                Remove-Item -LiteralPath $installer -Force -ErrorAction SilentlyContinue
            }}
        }}
        if (-not $downloaded) {{
            for ($hops = 0; $hops -lt 10; $hops++) {{
                if ($current -notmatch '^https://') {{ throw (Msg '{msg_https}') }}
                $req = [System.Net.HttpWebRequest]::Create($current)
                Apply-WebProxy $req
                $req.AllowAutoRedirect = $false
                $req.Timeout = 30000
                $req.ReadWriteTimeout = 60000
                $req.UserAgent = 'Yanji-Bootstrap/1.0'
                $resp = $null
                try {{
                    $resp = $req.GetResponse()
                }} catch [System.Net.WebException] {{
                    if ($_.Exception.Response -and ([int]$_.Exception.Response.StatusCode -in 301, 302, 303, 307, 308)) {{
                        $resp = $_.Exception.Response
                    }} else {{
                        throw
                    }}
                }}
                try {{
                    $code = [int]$resp.StatusCode
                    if ($code -in 301, 302, 303, 307, 308) {{
                        $loc = $resp.Headers['Location']
                        if (-not $loc) {{ throw (Msg '{msg_noloc}') }}
                        $current = ([System.Uri]::new([System.Uri]::new($current), $loc)).AbsoluteUri
                        continue
                    }}
                    if ($code -ne 200) {{
                        throw ((Msg '{msg_status}') + $code)
                    }}
                    $inStream = $resp.GetResponseStream()
                    $outStream = [System.IO.File]::Create($installer)
                    try {{ $inStream.CopyTo($outStream) }} finally {{ $outStream.Dispose(); $inStream.Dispose() }}
                    $downloaded = $true
                    break
                }} finally {{
                    if ($null -ne $resp) {{ $resp.Dispose() }}
                }}
            }}
        }}
        if (-not $downloaded) {{ throw (Msg '{msg_hops}') }}
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $fs = [System.IO.File]::OpenRead($installer)
        try {{
            $actual = ([System.BitConverter]::ToString($sha.ComputeHash($fs)) -replace '-', '').ToLowerInvariant()
        }} finally {{
            $fs.Dispose()
            $sha.Dispose()
        }}
        if ($actual -ne $InstallerSha256) {{
            throw (Msg '{msg_sha}')
        }}
        $psHost = if ($PSVersionTable.PSEdition -eq 'Core') {{ 'pwsh' }} else {{ 'powershell.exe' }}
        & $psHost -NoProfile -ExecutionPolicy Bypass -File $installer -Manifest "$BaseUrl/SHA256SUMS" -ManifestSha256 $ManifestSha256 @args
        if ($LASTEXITCODE -ne 0) {{
            throw ((Msg '{msg_exit_prefix}') + $LASTEXITCODE + (Msg '{msg_exit_suffix}'))
        }}
    }} finally {{
        if (Test-Path -LiteralPath $tmp) {{
            Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
        }}
    }}
}}
'''
        if any(ord(ch) >= 128 for ch in ps1_script):
            raise ValueError('Generated get-yanji.ps1 must remain pure 7-bit ASCII')
        ps1_out = output_dir / 'get-yanji.ps1'
        ps1_out.write_text(ps1_script, encoding='ascii', newline='\r\n')
        result['get-yanji.ps1'] = sha256_file(ps1_out)

    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--sums', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    info = generate(parser.parse_args().base_url, parser.parse_args().sums, parser.parse_args().output)
    for k, v in info.items():
        print(f'{k}={v}')


if __name__ == '__main__':
    main()
