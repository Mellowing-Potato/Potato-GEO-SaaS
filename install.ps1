#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Manifest = '',
    [string]$ManifestSha256 = '',
    [string]$Package = '',
    [string]$ProgramHome = '',
    [string]$Instance = '',
    [int]$Port = 0,
    [switch]$Upgrade,
    [switch]$NoBrowser,
    [switch]$Help
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Msg([string]$Escaped) { return [regex]::Unescape($Escaped) }
function Step([string]$Escaped) { [Console]::Error.WriteLine((Msg $Escaped)) }
function Fail([string]$Escaped) { Write-Error (Msg $Escaped); exit 1 }

if ($Help) {
    Write-Output 'install.ps1 [-ProgramHome DIR] [-Instance DIR] [-Port PORT] [-Manifest URL_OR_FILE] [-ManifestSha256 SHA] [-Package ZIP] [-Upgrade] [-NoBrowser]'
    exit 0
}

function Get-Sha256([string]$Path) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $stream = [System.IO.File]::OpenRead($Path)
    try {
        return ([System.BitConverter]::ToString($sha.ComputeHash($stream)) -replace '-', '').ToLowerInvariant()
    } finally {
        $stream.Dispose()
        $sha.Dispose()
    }
}

function Apply-WebProxy($Request) {
    foreach ($name in @('HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy')) {
        $val = [Environment]::GetEnvironmentVariable($name)
        if ($val -and $val -match '^https?://') {
            $Request.Proxy = [System.Net.WebProxy]::new($val)
            return
        }
    }
    $sysProxy = [System.Net.WebRequest]::GetSystemWebProxy()
    if ($null -ne $sysProxy) {
        $sysProxy.Credentials = [System.Net.CredentialCache]::DefaultCredentials
        $Request.Proxy = $sysProxy
    }
}

function Download-Https([string]$Url, [string]$Dest, [switch]$ShowProgress) {
    if ($Url -notmatch '^https://') {
        Fail '\u8fdc\u7aef\u5b89\u88c5\u6750\u6599\u4e0e\u91cd\u5b9a\u5411\u4ec5\u63a5\u53d7 HTTPS\u3002'
    }
    $sysCurl = if ($env:SystemRoot) { Join-Path $env:SystemRoot 'System32\curl.exe' } else { '' }
    if ($sysCurl -and (Test-Path -LiteralPath $sysCurl -PathType Leaf)) {
        $interactiveErr = $false
        if ($ShowProgress) {
            try { $interactiveErr = -not [Console]::IsErrorRedirected } catch { $interactiveErr = $false }
        }
        $curlArgs = @('--fail', '--location', '--tlsv1.2', '--connect-timeout', '20', '--max-redirs', '10',
            '--retry', '2', '--retry-delay', '1', '--proto', '=https', '--proto-redir', '=https',
            '--user-agent', 'Yanji-RootInstaller/1.0')
        if ($interactiveErr) { $curlArgs += '--progress-bar' } else { $curlArgs += @('--silent', '--show-error') }
        $curlArgs += @('--output', $Dest, $Url)
        & $sysCurl @curlArgs
        if ($LASTEXITCODE -eq 0 -and (Test-Path -LiteralPath $Dest -PathType Leaf)) {
            return
        }
        Remove-Item -LiteralPath $Dest -Force -ErrorAction SilentlyContinue
    }
    $current = $Url
    for ($hops = 0; $hops -lt 10; $hops++) {
        if ($current -notmatch '^https://') {
            Fail '\u8fdc\u7aef\u5b89\u88c5\u6750\u6599\u4e0e\u91cd\u5b9a\u5411\u4ec5\u63a5\u53d7 HTTPS\u3002'
        }
        $req = [System.Net.HttpWebRequest]::Create($current)
        Apply-WebProxy $req
        $req.AllowAutoRedirect = $false
        $req.Timeout = 30000
        $req.ReadWriteTimeout = 60000
        $req.UserAgent = 'Yanji-RootInstaller/1.0'
        $resp = $null
        try {
            $resp = $req.GetResponse()
        } catch [System.Net.WebException] {
            if ($_.Exception.Response -and ([int]$_.Exception.Response.StatusCode -in 301, 302, 303, 307, 308)) {
                $resp = $_.Exception.Response
            } else {
                throw
            }
        }
        try {
            $code = [int]$resp.StatusCode
            if ($code -in 301, 302, 303, 307, 308) {
                $loc = $resp.Headers['Location']
                if (-not $loc) { Fail '\u8fdc\u7aef\u91cd\u5b9a\u5411\u7f3a\u5c11\u76ee\u6807\u5730\u5740\u3002' }
                $current = ([System.Uri]::new([System.Uri]::new($current), $loc)).AbsoluteUri
                continue
            }
            if ($code -ne 200) {
                Write-Error ((Msg '\u8fdc\u7aef\u4e0b\u8f7d\u8fd4\u56de\u5f02\u5e38\u72b6\u6001\u7801\uff1a') + $code)
                exit 1
            }
            $inStream = $resp.GetResponseStream()
            $outStream = [System.IO.File]::Create($Dest)
            try { $inStream.CopyTo($outStream) } finally { $outStream.Dispose(); $inStream.Dispose() }
            return
        } finally {
            if ($null -ne $resp) { $resp.Dispose() }
        }
    }
    Fail '\u8fdc\u7aef\u4e0b\u8f7d\u91cd\u5b9a\u5411\u6b21\u6570\u8fc7\u591a\u3002'
}

function Fetch-File([string]$Source, [string]$Dest, [switch]$ShowProgress) {
    if ($Source -match '^https://') {
        Download-Https $Source $Dest -ShowProgress:$ShowProgress
    } elseif ($Source -match '^[A-Za-z][A-Za-z0-9+.-]*://') {
        Fail '\u8fdc\u7aef\u5b89\u88c5\u6750\u6599\u4ec5\u63a5\u53d7 HTTPS\u3002'
    } else {
        if (-not (Test-Path -LiteralPath $Source -PathType Leaf)) {
            Write-Error ((Msg '\u7f3a\u5c11\u672c\u5730\u5b89\u88c5\u6750\u6599\uff1a') + $Source)
            exit 1
        }
        Copy-Item -LiteralPath $Source -Destination $Dest -Force
    }
}

function Resolve-Sibling([string]$BaseRef, [string]$ChildName) {
    if ($BaseRef -match '^https://') {
        $idx = $BaseRef.LastIndexOf('/')
        if ($idx -ge 0) { return $BaseRef.Substring(0, $idx + 1) + $ChildName }
        return $ChildName
    }
    $dir = Split-Path -Parent $BaseRef
    if ($dir) { return Join-Path $dir $ChildName }
    return $ChildName
}

function Get-ManifestEntry([string]$SumsPath, [string]$Name) {
    $found = @()
    foreach ($line in [System.IO.File]::ReadAllLines($SumsPath, [System.Text.Encoding]::UTF8)) {
        if ($line -match '^([a-f0-9]{64})\s+(.+)$' -and $Matches[2].Trim() -eq $Name) {
            $found += $Matches[1]
        }
    }
    if ($found.Count -ne 1) {
        Write-Error ((Msg '\u53d1\u884c\u6e05\u5355\u7f3a\u5c11\u552f\u4e00\u6761\u76ee\uff1a') + $Name)
        exit 1
    }
    return $found[0]
}

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $Manifest -and -not $ManifestSha256) {
    $pinFile = Join-Path $repoRoot 'portable\release-pin.json'
    if (-not (Test-Path -LiteralPath $pinFile -PathType Leaf)) {
        Fail '\u7f3a\u5c11 portable\release-pin.json \u53d1\u884c\u6458\u8981\u914d\u7f6e\u3002'
    }
    $pin = [System.IO.File]::ReadAllText($pinFile, [System.Text.Encoding]::UTF8) | ConvertFrom-Json
    $baseUrl = ([string]$pin.base_url).TrimEnd('/')
    if ($baseUrl -notmatch '^https://') {
        Fail '\u53d1\u884c\u5730\u5740\u5fc5\u987b\u4f7f\u7528 HTTPS\u3002'
    }
    $Manifest = "$baseUrl/SHA256SUMS"
    $ManifestSha256 = ([string]$pin.manifest_sha256).Trim()
}

if (-not $Manifest -or $ManifestSha256 -notmatch '^[a-f0-9]{64}$') {
    Fail '\u8bf7\u540c\u65f6\u63d0\u4f9b -Manifest \u4e0e 64 \u4f4d\u5c0f\u5199 -ManifestSha256\u3002'
}

$defaultBase = if ($env:LOCALAPPDATA) { Join-Path $env:LOCALAPPDATA 'Yanji' } else { Join-Path $HOME 'AppData\Local\Yanji' }
$targetProgram = if ($ProgramHome) { $ProgramHome } else { Join-Path $defaultBase 'program' }
$targetInstance = if ($Instance) { $Instance } else { Join-Path $defaultBase 'instance' }

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ('yanji-root-install-' + [Guid]::NewGuid().ToString('N').Substring(0, 12))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
try {
    Step '\u6b63\u5728\u83b7\u53d6\u5e76\u6821\u9a8c\u8a00\u53ca\u53d1\u884c\u6e05\u5355...'
    $sumsFile = Join-Path $tmp 'SHA256SUMS'
    Fetch-File $Manifest $sumsFile
    if ((Get-Sha256 $sumsFile) -ne $ManifestSha256) {
        Fail '\u53d1\u884c\u6e05\u5355 SHA-256 \u6821\u9a8c\u5931\u8d25\u3002'
    }

    $pkgName = 'yanji-windows-x86_64.zip'
    $pkgSha = Get-ManifestEntry $sumsFile $pkgName
    $installerSha = Get-ManifestEntry $sumsFile 'install-local.ps1'

    # Fast path: if this exact verified release is already initialized at the target instance, start and open directly;
    # if an older verified release is initialized, automatically switch to safe backup-and-upgrade mode.
    $managedCmd = Join-Path $targetInstance 'yanji.cmd'
    $initMarker = Join-Path $targetInstance '.portable-initialized'
    $ptrFile = Join-Path $targetInstance 'current-release.txt'
    if (-not $Upgrade -and -not $ProgramHome -and -not $Instance -and $Port -le 0 -and (Test-Path -LiteralPath $managedCmd -PathType Leaf) -and (Test-Path -LiteralPath $initMarker -PathType Leaf) -and (Test-Path -LiteralPath $ptrFile -PathType Leaf)) {
        $currentRel = [System.IO.File]::ReadAllText($ptrFile, [System.Text.Encoding]::UTF8).Trim()
        $expectedRel = Join-Path (Join-Path $targetProgram 'releases') $pkgSha
        if ($currentRel -and (Test-Path -LiteralPath $expectedRel -PathType Container) -and [System.IO.Path]::GetFullPath($currentRel).TrimEnd('\') -ieq [System.IO.Path]::GetFullPath($expectedRel).TrimEnd('\')) {
            Step '\u68c0\u6d4b\u5230\u5df2\u5b89\u88c5\u5f53\u524d\u7248\u672c\uff0c\u6b63\u5728\u76f4\u63a5\u542f\u52a8\u8a00\u53ca\u5de5\u4f5c\u53f0...'
            $openArgs = if ($NoBrowser) { @('start', '--no-browser') } else { @('open') }
            & cmd.exe /d /s /c "`"$managedCmd`" $($openArgs -join ' ')"
            exit $LASTEXITCODE
        } elseif ($currentRel -and (Test-Path -LiteralPath $currentRel -PathType Container)) {
            Step '\u68c0\u6d4b\u5230\u5df2\u6709\u65e7\u7248\u8a00\u53ca\u5b9e\u4f8b\uff0c\u6b63\u5728\u81ea\u52a8\u5907\u4efd\u5e76\u5e73\u6ed1\u5347\u7ea7\u81f3\u65b0\u7248\u672c...'
            $Upgrade = [switch]::Present
        }
    }

    $localScript = Join-Path $repoRoot 'scripts\install-local.ps1'
    $stagedScript = Join-Path $tmp 'install-local.ps1'
    if ((Test-Path -LiteralPath $localScript -PathType Leaf) -and (Get-Sha256 $localScript) -eq $installerSha) {
        Copy-Item -LiteralPath $localScript -Destination $stagedScript -Force
    } else {
        Step '\u6b63\u5728\u83b7\u53d6\u5e76\u6821\u9a8c\u5b89\u88c5\u811a\u672c...'
        Fetch-File (Resolve-Sibling $Manifest 'install-local.ps1') $stagedScript
        if ((Get-Sha256 $stagedScript) -ne $installerSha) {
            Fail '\u5b89\u88c5\u811a\u672c SHA-256 \u6821\u9a8c\u5931\u8d25\u3002'
        }
    }

    $pkgSource = if ($Package) { $Package } else { Resolve-Sibling $Manifest $pkgName }
    $stagedPkg = $pkgSource
    if ($pkgSource -match '^https://') {
        $stagedPkg = Join-Path $tmp $pkgName
        [Console]::Error.WriteLine((Msg '\u6b63\u5728\u4e0b\u8f7d\u4fbf\u643a\u5b89\u88c5\u5305 ') + $pkgName + (Msg '\uff08\u9996\u6b21\u7ea6 156 MB\uff0c\u8bf7\u7a0d\u5019\uff09...'))
        Fetch-File $pkgSource $stagedPkg -ShowProgress
        if ((Get-Sha256 $stagedPkg) -ne $pkgSha) {
            Fail '\u5b89\u88c5\u5305 SHA-256 \u6821\u9a8c\u5931\u8d25\u3002'
        }
    }

    $psHost = if ($PSVersionTable.PSEdition -eq 'Core') { 'pwsh' } else { 'powershell.exe' }
    $fwd = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $stagedScript,
        '-Manifest', $sumsFile, '-ManifestSha256', $ManifestSha256, '-Package', $stagedPkg)
    if ($ProgramHome) { $fwd += @('-ProgramHome', $ProgramHome) }
    if ($Instance) { $fwd += @('-Instance', $Instance) }
    if ($Port -gt 0) { $fwd += @('-Port', "$Port") }
    if ($Upgrade) { $fwd += '-Upgrade' }
    if ($NoBrowser) { $fwd += '-NoBrowser' }
    & $psHost @fwd
    exit $LASTEXITCODE
} finally {
    if (Test-Path -LiteralPath $tmp) {
        Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
    }
}
