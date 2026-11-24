#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Manifest = '',
    [string]$ManifestSha256 = '',
    [string]$Package = '',
    [string]$ProgramHome = '',
    [string]$Instance = '',
    [int]$Port = 0,
    [string]$Email = '',
    [string]$PasswordFile = '',
    [string]$PythonBin = '',
    [switch]$Upgrade,
    [switch]$Diagnosis,
    [switch]$Help
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if ($PSVersionTable.PSEdition -eq 'Desktop' -and $env:SystemRoot) {
    $env:PSModulePath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\Modules'
}
$utf8NoBom = [System.Text.UTF8Encoding]::new($false)
$OutputEncoding = $utf8NoBom
try { [Console]::OutputEncoding = $utf8NoBom } catch {}
try { [Console]::InputEncoding = $utf8NoBom } catch {}

if ($Help) {
    Write-Output 'install-local.ps1 -Manifest SHA256SUMS -ManifestSha256 SHA [-Package ZIP] [-ProgramHome DIR] [-Instance DIR] [-Port PORT] [-Email EMAIL] [-PasswordFile FILE] [-PythonBin PYTHON] [-Upgrade] [-Diagnosis]'
    exit 0
}

$arch = $env:PROCESSOR_ARCHITECTURE
if ($env:PROCESSOR_ARCHITEW6432) { $arch = $env:PROCESSOR_ARCHITEW6432 }
$vcDll = Join-Path $env:SystemRoot 'System32\VCRUNTIME140.dll'
$hasVcRuntime = Test-Path -LiteralPath $vcDll

if ($Diagnosis) {
    Write-Output ('系统 {0}；架构 {1}；PowerShell {2}；VCRUNTIME140={3}' -f [Environment]::OSVersion.VersionString, $arch, $PSVersionTable.PSVersion, $hasVcRuntime)
    if ($arch -ne 'AMD64') {
        Write-Error '当前仅支持 Windows x64（AMD64）。'
        exit 1
    }
    if (-not $hasVcRuntime) {
        Write-Error '缺少 Microsoft Visual C++ 2015-2022 x64 运行库（VCRUNTIME140.dll），请先从 https://aka.ms/vs/17/release/vc_redist.x64.exe 安装。'
        exit 1
    }
    exit 0
}

function Fail([string]$Message) {
    Write-Error $Message
    exit 1
}

if ($arch -ne 'AMD64') {
    Fail '该 Windows 架构暂不受支持，当前仅支持 Windows x64（AMD64）。'
}
if (-not $hasVcRuntime) {
    Fail '缺少 Microsoft Visual C++ 2015-2022 x64 运行库（VCRUNTIME140.dll）。请先安装 https://aka.ms/vs/17/release/vc_redist.x64.exe 后重新运行本安装器。'
}
if (-not $Manifest -or $ManifestSha256 -notmatch '^[a-f0-9]{64}$') {
    Fail '请提供发行清单（-Manifest）及 64 位小写 SHA-256（-ManifestSha256）。'
}

$base = if ($env:LOCALAPPDATA) { Join-Path $env:LOCALAPPDATA 'Yanji' } else { Join-Path $HOME 'AppData\Local\Yanji' }
if (-not $ProgramHome) { $ProgramHome = Join-Path $base 'program' }
if (-not $Instance) { $Instance = Join-Path $base 'instance' }
if ($ProgramHome -notmatch '^[A-Za-z]:[\\/]' -or $Instance -notmatch '^[A-Za-z]:[\\/]') {
    Fail '程序目录与实例目录必须是本地盘符绝对路径。'
}

function Get-Sha256([string]$Path) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $stream = [System.IO.File]::OpenRead($Path)
    try {
        $bytes = $sha.ComputeHash($stream)
        return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant()
    } finally {
        $stream.Dispose()
        $sha.Dispose()
    }
}

function Download-Https([string]$Url, [string]$Dest) {
    $current = $Url
    for ($hops = 0; $hops -lt 10; $hops++) {
        if ($current -notmatch '^https://') {
            Fail '远端安装材料与重定向仅接受 HTTPS。'
        }
        $req = [System.Net.HttpWebRequest]::Create($current)
        $req.AllowAutoRedirect = $false
        $req.UserAgent = 'Yanji-Installer/1.0'
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
                if (-not $loc) { Fail '远端重定向缺少目标地址。' }
                $current = ([System.Uri]::new([System.Uri]::new($current), $loc)).AbsoluteUri
                continue
            }
            if ($code -ne 200) {
                Fail "远端下载返回异常状态码：$code"
            }
            $inStream = $resp.GetResponseStream()
            $outStream = [System.IO.File]::Create($Dest)
            try { $inStream.CopyTo($outStream) } finally { $outStream.Dispose(); $inStream.Dispose() }
            return
        } finally {
            $resp.Dispose()
        }
    }
    Fail '远端下载重定向次数过多。'
}

function Fetch-Material([string]$Source, [string]$Dest) {
    if ($Source -match '^https://') {
        Download-Https $Source $Dest
    } elseif ($Source -match '^[A-Za-z][A-Za-z0-9+.-]*://') {
        Fail '远端安装材料仅接受 HTTPS。'
    } else {
        if (-not (Test-Path -LiteralPath $Source -PathType Leaf)) {
            Fail "缺少本地安装材料：$Source"
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
    $matchesList = @()
    foreach ($line in (Get-Content -LiteralPath $SumsPath -Encoding UTF8)) {
        if ($line -match '^([a-f0-9]{64})\s+(.+)$') {
            if ($Matches[2].Trim() -eq $Name) {
                $matchesList += $Matches[1]
            }
        }
    }
    if ($matchesList.Count -ne 1) {
        Fail "发行清单缺少唯一条目：$Name"
    }
    return $matchesList[0]
}

Add-Type -AssemblyName System.IO.Compression.FileSystem

$scratch = Join-Path ([System.IO.Path]::GetTempPath()) ("yanji-install-" + [Guid]::NewGuid().ToString("N").Substring(0, 12))
New-Item -ItemType Directory -Force -Path $scratch | Out-Null
$stage = $null

try {
    $sumsFile = Join-Path $scratch 'SHA256SUMS'
    Fetch-Material $Manifest $sumsFile
    if ((Get-Sha256 $sumsFile) -ne $ManifestSha256) {
        Fail '发行清单 SHA-256 校验失败。'
    }

    $bootstrapSha = Get-ManifestEntry $sumsFile 'install-local.ps1'
    $selfPath = $PSCommandPath
    if (-not $selfPath -or -not (Test-Path -LiteralPath $selfPath) -or (Get-Sha256 $selfPath) -ne $bootstrapSha) {
        if (-not $Upgrade) {
            Fail '请先将匹配清单的 install-local.ps1 保存到本地后再执行。'
        }
        $nextScript = Join-Path $scratch 'next-install-local.ps1'
        Fetch-Material (Resolve-Sibling $Manifest 'install-local.ps1') $nextScript
        if ((Get-Sha256 $nextScript) -ne $bootstrapSha) {
            Fail '候选升级安装器校验失败。'
        }
        $fwd = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $nextScript,
            '-Upgrade', '-Manifest', $Manifest, '-ManifestSha256', $ManifestSha256,
            '-ProgramHome', $ProgramHome, '-Instance', $Instance)
        if ($Package) { $fwd += @('-Package', $Package) }
        $psHost = if ($PSVersionTable.PSEdition -eq 'Core') { 'pwsh' } else { 'powershell.exe' }
        & $psHost @fwd
        exit $LASTEXITCODE
    }

    $pkgName = 'yanji-windows-x86_64.zip'
    $pkgSha = Get-ManifestEntry $sumsFile $pkgName
    $pkgSrc = if ($Package) { $Package } else { Resolve-Sibling $Manifest $pkgName }
    $pkgZip = Join-Path $scratch $pkgName
    Fetch-Material $pkgSrc $pkgZip
    if ((Get-Sha256 $pkgZip) -ne $pkgSha) {
        Fail '安装包 SHA-256 校验失败。'
    }

    # Inspect every ZIP entry before extraction: reject traversal, absolute paths, and unsafe names.
    $zip = [System.IO.Compression.ZipFile]::OpenRead($pkgZip)
    $preflightEntries = @{
        'runtime/bin/php.exe' = $true
        'runtime/bin/php8ts.dll' = $true
        'app/portable/preflight.php' = $true
        'app/portable/publish.php' = $true
        'app/portable/src/Platform.php' = $true
        'app/portable/src/Instance.php' = $true
    }
    $manifestFilesContent = $null
    try {
        if ($zip.Entries.Count -eq 0) { Fail '安装包为空。' }
        $seen = @{}
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName
            if ($name.StartsWith('/') -or $name -match '^[A-Za-z]:' -or $name -match '\\' -or $name -match '(^|/)\.\.(/|$)' -or $name -match '[\x00-\x1f]') {
                Fail "安装包包含不安全路径：$name"
            }
            if ($name.EndsWith('/')) { continue }
            if ($seen.ContainsKey($name)) { Fail "安装包包含重复条目：$name" }
            $seen[$name] = $true
        }
        $preflightDir = Join-Path $scratch 'preflight'
        foreach ($entry in $zip.Entries) {
            if ($entry.FullName.EndsWith('/')) { continue }
            if ($preflightEntries.ContainsKey($entry.FullName) -or ($entry.FullName -match '^runtime/bin/[^/]+\.dll$')) {
                $dest = Join-Path $preflightDir ($entry.FullName -replace '/', '\')
                New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null
                [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $dest, $true)
            } elseif ($entry.FullName -eq 'PACKAGE-FILES.sha256') {
                $manifestPath = Join-Path $scratch 'PACKAGE-FILES.sha256'
                [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $manifestPath, $true)
                $manifestFilesSha256 = Get-Sha256 $manifestPath
                $manifestFilesContent = [System.IO.File]::ReadAllText($manifestPath, [System.Text.Encoding]::UTF8)
            }
        }
    } finally {
        $zip.Dispose()
    }
    if (-not $manifestFilesContent) { Fail '安装包缺少 PACKAGE-FILES.sha256 文件清单。' }

    # Resolve physical destinations before modifying user directories
    $prePhp = Join-Path $scratch 'preflight\runtime\bin\php.exe'
    $preScript = Join-Path $scratch 'preflight\app\portable\preflight.php'
    $preOut = & $prePhp -n $preScript $ProgramHome $Instance
    if ($LASTEXITCODE -ne 0 -or $preOut.Count -lt 2) {
        Fail '安装路径预检未通过，请检查目录是否重叠或含非托管文件。'
    }
    $ProgramHome = ($preOut[0].Trim()) -replace '/', '\'
    $Instance = ($preOut[1].Trim()) -replace '/', '\'

    New-Item -ItemType Directory -Force -Path $ProgramHome | Out-Null
    $progMarker = Join-Path $ProgramHome '.yanji-program'
    if (-not (Test-Path -LiteralPath $progMarker)) {
        [System.IO.File]::WriteAllText($progMarker, "yanji-program-v1`n", [System.Text.Encoding]::ASCII)
    }
    $releasesDir = Join-Path $ProgramHome 'releases'
    New-Item -ItemType Directory -Force -Path $releasesDir | Out-Null
    $release = Join-Path $releasesDir $pkgSha

    if (-not (Test-Path -LiteralPath $release)) {
        $stage = Join-Path $releasesDir (".incoming." + [Guid]::NewGuid().ToString("N").Substring(0, 12))
        New-Item -ItemType Directory -Force -Path $stage | Out-Null
        [System.IO.Compression.ZipFile]::ExtractToDirectory($pkgZip, $stage)
        foreach ($req in @('runtime\bin\php.exe', 'runtime\bin\frankenphp.exe', 'app\portable\yanji.php')) {
            if (-not (Test-Path -LiteralPath (Join-Path $stage $req) -PathType Leaf)) {
                Fail "安装包布局无效，缺少 $req。"
            }
        }
        [System.IO.File]::WriteAllText((Join-Path $stage '.archive-sha256'), "$pkgSha`n", [System.Text.Encoding]::ASCII)
        & $prePhp -n (Join-Path $scratch 'preflight\app\portable\publish.php') $stage $release
        if ($LASTEXITCODE -ne 0) { Fail '无法发布已核验的程序版本。' }
        if (-not (Test-Path -LiteralPath $stage)) { $stage = $null }
    }

    $relItem = Get-Item -LiteralPath $release -Force
    if ($relItem.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        Fail '程序版本目录不能为符号链接或联接。'
    }
    $cachedDigest = (Get-Content -LiteralPath (Join-Path $release '.archive-sha256') -Raw).Trim()
    if ($cachedDigest -ne $pkgSha) { Fail '程序版本摘要校验失败。' }

    # Verify cached inventory and reject any symlink/reparse point inside the release tree
    $cachedInvPath = Join-Path $release 'PACKAGE-FILES.sha256'
    if (-not (Test-Path -LiteralPath $cachedInvPath -PathType Leaf)) {
        Fail '程序文件清单缺失。'
    }
    if ((Get-Sha256 $cachedInvPath) -ne $manifestFilesSha256) {
        Fail '程序文件清单已更改。'
    }
    $allItems = @(Get-ChildItem -LiteralPath $release -Recurse -Force)
    foreach ($it in $allItems) {
        if ($it.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
            Fail "程序目录包含未认证的链接：$($it.FullName)"
        }
    }

    # Verify every file in the cached release against the authenticated PACKAGE-FILES.sha256
    $expectedLines = @($manifestFilesContent -split "`r?`n" | Where-Object { $_.Trim() -ne '' })
    $expectedMap = @{}
    foreach ($line in $expectedLines) {
        if ($line -notmatch '^([a-f0-9]{64})  (.+)$') { Fail 'PACKAGE-FILES.sha256 格式无效。' }
        $rel = $Matches[2]
        $expectedMap[$rel] = $Matches[1]
        $full = Join-Path $release ($rel -replace '/', '\')
        if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Fail "程序文件缺失：$rel" }
        if ((Get-Sha256 $full) -ne $Matches[1]) { Fail "程序字节与发行清单不同：$rel" }
    }
    $actualFiles = @($allItems | Where-Object { -not $_.PSIsContainer })
    if ($actualFiles.Count -ne ($expectedMap.Count + 2)) {
        Fail '程序目录存在额外文件，请选择新的程序目录。'
    }

    $mode = if ($Upgrade) { '__upgrade' } else { '__install' }
    $relPhp = Join-Path $release 'runtime\bin\php.exe'
    $relCli = Join-Path $release 'app\portable\yanji.php'
    $relForIni = $release
    if ($relForIni -match '[^\x20-\x7e]') {
        try {
            $fso = [Activator]::CreateInstance([Type]::GetTypeFromProgID('Scripting.FileSystemObject'))
            $shortRel = $fso.GetFolder($release).ShortPath
            if ($shortRel) { $relForIni = $shortRel }
        } catch {}
    }
    $env:YANJI_EXT_DIR = Join-Path $relForIni 'runtime\bin\ext'
    $env:YANJI_CA_BUNDLE = Join-Path $relForIni 'runtime\ca\cacert.pem'
    $cmdArgs = @(
        '-n',
        '-d', 'extension_dir=${YANJI_EXT_DIR}',
        '-d', 'curl.cainfo=${YANJI_CA_BUNDLE}',
        '-d', 'openssl.cafile=${YANJI_CA_BUNDLE}',
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=sqlite3',
        '-d', 'extension=pdo_sqlite',
        '-d', 'extension=zip',
        '-d', 'extension=curl',
        '-d', 'extension=fileinfo',
        '-d', 'extension=gd',
        $relCli, $mode, '--instance', $Instance, '--release', $release
    )
    if ($Port -gt 0) { $cmdArgs += @('--port', "$Port") }
    if ($Email) { $cmdArgs += @('--email', $Email) }
    if ($PythonBin) { $cmdArgs += @('--python-bin', $PythonBin) }
    if ($PasswordFile) { $cmdArgs += @('--password-file', $PasswordFile) }
    & $relPhp @cmdArgs
    exit $LASTEXITCODE
} finally {
    if ($stage -and (Test-Path -LiteralPath $stage)) {
        Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path -LiteralPath $scratch) {
        Remove-Item -LiteralPath $scratch -Recurse -Force -ErrorAction SilentlyContinue
    }
}
