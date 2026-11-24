#Requires -Version 5.1
<#
.SYNOPSIS
Build minimal AGPL-free FrankenPHP (nomercure, novulcain, nowatcher) and PHP 8.5.13 ZTS runtime bundles on Windows x64.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$OutputDir,
    [string]$WorkDir = (Join-Path ([System.IO.Path]::GetTempPath()) ("yanji-win-web-" + [Guid]::NewGuid().ToString("N").Substring(0, 12)))
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Spec = Get-Content (Join-Path $RepoRoot 'licenses\portable-web.json') -Raw | ConvertFrom-Json

function Get-FileSha256([string]$Path) {
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Fetch-Verified([string[]]$Urls, [string]$ExpectedSha256, [string]$Destination) {
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Destination) | Out-Null
    if ((Test-Path $Destination) -and (Get-FileSha256 $Destination) -eq $ExpectedSha256) {
        return
    }
    $lastErr = $null
    foreach ($u in $Urls) {
        try {
            Invoke-WebRequest -Uri $u -OutFile $Destination -UseBasicParsing
            $actual = Get-FileSha256 $Destination
            if ($actual -ne $ExpectedSha256) {
                throw "SHA-256 mismatch for ${u}: expected $ExpectedSha256, got $actual"
            }
            return
        } catch {
            $lastErr = $_
            Remove-Item -Force -ErrorAction SilentlyContinue $Destination
        }
    }
    throw "Failed to fetch verified input: $lastErr"
}

$goVersion = (& go env GOVERSION).Trim()
if ($goVersion -ne ("go" + $Spec.go.version)) {
    throw "Expected Go $($Spec.go.version), got $goVersion"
}

New-Item -ItemType Directory -Force -Path $WorkDir, $OutputDir | Out-Null
$InputsDir = Join-Path $WorkDir 'inputs'
New-Item -ItemType Directory -Force -Path $InputsDir | Out-Null

# 1. Fetch and verify pinned upstream sources and Windows PHP 8.5.13 ZTS binaries/SDK
$frankenTar = Join-Path $InputsDir 'frankenphp.tar.gz'
Fetch-Verified @($Spec.frankenphp.archive_url) $Spec.frankenphp.archive_sha256 $frankenTar

$brotliTar = Join-Path $InputsDir 'brotli-1.2.1.tar.gz'
Fetch-Verified @($Spec.brotli.patched_archive_url) $Spec.brotli.patched_archive_sha256 $brotliTar

$phpZip = Join-Path $InputsDir 'php-8.5.13-Win32-vs17-x64.zip'
Fetch-Verified @($Spec.windows_php.runtime_url, $Spec.windows_php.runtime_archive_url) $Spec.windows_php.runtime_sha256 $phpZip

$phpDevelZip = Join-Path $InputsDir 'php-devel-pack-8.5.13-Win32-vs17-x64.zip'
Fetch-Verified @($Spec.windows_php.devel_url, $Spec.windows_php.devel_archive_url) $Spec.windows_php.devel_sha256 $phpDevelZip

$caPem = Join-Path $InputsDir 'cacert.pem'
Fetch-Verified @($Spec.ca_bundle.url) $Spec.ca_bundle.sha256 $caPem

# 2. Extract and verify all four pinned FrankenPHP Go dependency lockfiles
$frankenParent = Join-Path $WorkDir 'frankenphp-extract'
New-Item -ItemType Directory -Force -Path $frankenParent | Out-Null
& tar -xzf $frankenTar -C $frankenParent
if ($LASTEXITCODE -ne 0) { throw "Failed to extract FrankenPHP source archive" }
$frankenSrc = (Get-ChildItem $frankenParent -Directory | Select-Object -First 1).FullName

foreach ($prop in $Spec.dependency_locks.PSObject.Properties) {
    $lockPath = Join-Path $frankenSrc ($prop.Name -replace '/', '\')
    $actual = Get-FileSha256 $lockPath
    if ($actual -ne $prop.Value) {
        throw "FrankenPHP upstream dependency lock changed: $($prop.Name)"
    }
}

# 3. Extract PHP 8.5.13 ZTS runtime and development SDK
$phpRuntimeDir = Join-Path $WorkDir 'php-runtime'
$phpDevelParent = Join-Path $WorkDir 'php-devel'
Expand-Archive -Path $phpZip -DestinationPath $phpRuntimeDir -Force
Expand-Archive -Path $phpDevelZip -DestinationPath $phpDevelParent -Force
$phpDevelDir = (Get-ChildItem $phpDevelParent -Directory | Select-Object -First 1).FullName

# 4. Build patched Brotli v1.2.1 (fixes CVE-2026-111497) and install pthreads via vcpkg
$brotliParent = Join-Path $WorkDir 'brotli-extract'
$brotliBuild = Join-Path $WorkDir 'brotli-build'
$brotliPrefix = Join-Path $WorkDir 'brotli-prefix'
New-Item -ItemType Directory -Force -Path $brotliParent | Out-Null
& tar -xzf $brotliTar -C $brotliParent
if ($LASTEXITCODE -ne 0) { throw "Failed to extract Brotli v1.2.1" }
$brotliSrc = (Get-ChildItem $brotliParent -Directory | Select-Object -First 1).FullName
& cmake -S $brotliSrc -B $brotliBuild -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=ON -DBROTLI_DISABLE_TESTS=ON -DCMAKE_INSTALL_PREFIX="$brotliPrefix"
if ($LASTEXITCODE -ne 0) { throw "CMake configure failed for Brotli v1.2.1" }
& cmake --build $brotliBuild --config Release --target install
if ($LASTEXITCODE -ne 0) { throw "CMake build failed for Brotli v1.2.1" }

$vcpkgRoot = $env:VCPKG_INSTALLATION_ROOT
if (-not $vcpkgRoot -or -not (Test-Path $vcpkgRoot)) {
    throw "VCPKG_INSTALLATION_ROOT is required on Windows build runner for pthreads"
}
$pthreadsManifest = Join-Path $WorkDir 'pthreads-vcpkg'
New-Item -ItemType Directory -Force -Path $pthreadsManifest | Out-Null
'{"name":"yanji-win-pthreads","version":"1.0.0","dependencies":["pthreads"]}' | Set-Content (Join-Path $pthreadsManifest 'vcpkg.json') -Encoding UTF8
Push-Location $pthreadsManifest
try {
    & (Join-Path $vcpkgRoot 'vcpkg.exe') install --triplet x64-windows
    if ($LASTEXITCODE -ne 0) { throw "vcpkg install pthreads failed" }
} finally {
    Pop-Location
}
$vcpkgInstalled = Join-Path $pthreadsManifest 'vcpkg_installed\x64-windows'

# 5. Configure Clang + LLD + CGO and compile minimal FrankenPHP without Mercure/Vulcain/Watcher
$llvmPaths = @(
    'C:\Program Files\Microsoft Visual Studio\2022\Enterprise\VC\Tools\Llvm\x64\bin',
    'C:\Program Files\Microsoft Visual Studio\2022\Enterprise\VC\Tools\Llvm\bin',
    'C:\Program Files\LLVM\bin'
)
foreach ($p in $llvmPaths) {
    if (Test-Path $p) { $env:PATH = "$p;$env:PATH" }
}
$env:PATH = "$brotliPrefix\bin;$vcpkgInstalled\bin;$phpRuntimeDir;$env:PATH"
$env:CC = 'clang'
$env:CXX = 'clang++'
$env:CGO_ENABLED = '1'
$env:GOWORK = 'off'
$env:GOTOOLCHAIN = 'local'
$env:PKG_CONFIG_PATH = "$brotliPrefix\lib\pkgconfig;$vcpkgInstalled\lib\pkgconfig"
$tags = ($Spec.build_tags -join ',')
$brotliSlash = $brotliPrefix -replace '\\', '/'
$vcpkgSlash = $vcpkgInstalled -replace '\\', '/'
$phpRunSlash = $phpRuntimeDir -replace '\\', '/'
$phpDevSlash = $phpDevelDir -replace '\\', '/'
$env:CGO_CFLAGS = "-DFRANKENPHP_VERSION=$($Spec.frankenphp.version) -I$brotliSlash/include -I$vcpkgSlash/include -I$phpDevSlash/include -I$phpDevSlash/include/main -I$phpDevSlash/include/TSRM -I$phpDevSlash/include/Zend -I$phpDevSlash/include/ext"
$env:CGO_LDFLAGS = "-L$brotliSlash/lib -lbrotlienc -lbrotlidec -lbrotlicommon -L$vcpkgSlash/lib -L$phpRunSlash -L$phpDevSlash/lib -lphp8ts -lphp8embed"

$caddyDir = Join-Path $frankenSrc 'caddy'
$outExe = Join-Path $WorkDir 'frankenphp.exe'
$activeGraphJson = Join-Path $WorkDir 'active-go-graph.json'

Push-Location $caddyDir
try {
    $modules = & go list -mod=readonly -deps -tags="$tags" -f '{{with .Module}}{{.Path}} {{.Version}}{{end}}' ./frankenphp
    if ($LASTEXITCODE -ne 0) { throw "go list -deps failed" }
    if ($modules | Select-String -Pattern '^github\.com/dunglas/(mercure|vulcain)([/ ]|$)') {
        throw "Forbidden AGPL module still present in active module graph"
    }
    if (-not ($modules -contains 'github.com/dunglas/caddy-cbrotli v1.0.1')) {
        throw "Expected caddy-cbrotli v1.0.1 missing from active module graph"
    }
    if (-not ($modules -contains 'github.com/caddyserver/caddy/v2 v2.11.9')) {
        throw "Expected caddy/v2 v2.11.9 missing from active module graph"
    }
    $graphLines = & go list -mod=readonly -tags="$tags" -deps -json ./frankenphp
    if ($LASTEXITCODE -ne 0) { throw "go list -deps -json failed" }
    [System.IO.File]::WriteAllText($activeGraphJson, (($graphLines -join "`n") + "`n"), [System.Text.UTF8Encoding]::new($false))

    $customVersion = "FrankenPHP v$($Spec.frankenphp.version) PHP $($Spec.php.version) Caddy"
    $ldflags = "-extldflags=-fuse-ld=lld -X 'github.com/caddyserver/caddy/v2.CustomVersion=$customVersion' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'"
    & go build -mod=readonly -trimpath -buildvcs=false -tags="$tags" -ldflags="$ldflags" -o $outExe ./frankenphp
    if ($LASTEXITCODE -ne 0) { throw "go build frankenphp.exe failed" }
} finally {
    Pop-Location
}

# 6. Verify actual built binary excludes Mercure/Vulcain and lists expected Caddy modules
$buildInfo = (& go version -m $outExe) -join "`n"
if ($buildInfo -match 'github\.com/dunglas/(mercure|vulcain)') {
    throw "Built frankenphp.exe unexpectedly contains Mercure or Vulcain"
}
$modulesOut = (& $outExe list-modules) -join "`n"
if ($modulesOut -match '(?i)mercure|vulcain') {
    throw "Runtime list-modules unexpectedly contains Mercure or Vulcain"
}
if ($modulesOut -notmatch 'http\.encoders\.br' -or $modulesOut -notmatch 'frankenphp') {
    throw "Runtime list-modules missing required frankenphp or http.encoders.br module"
}

# 7. Collect compiled Go source notices & assemble Web source-relink kit + Web runtime + PHP CLI runtime
$noticesStage = Join-Path $WorkDir 'web-notices'
New-Item -ItemType Directory -Force -Path $noticesStage | Out-Null
$pyCmd = if (Get-Command python -ErrorAction SilentlyContinue) { 'python' } else { 'python3' }
& $pyCmd (Join-Path $RepoRoot 'scripts\runtime\collect-web-go-notices.py') $activeGraphJson (Join-Path $noticesStage 'go-source-notices.txt') (Join-Path $noticesStage 'go-source-index.json')
if ($LASTEXITCODE -ne 0) { throw "collect-web-go-notices.py failed" }
Copy-Item (Join-Path $frankenSrc 'LICENSE') (Join-Path $noticesStage 'frankenphp-LICENSE.txt')
Copy-Item (Join-Path $brotliSrc 'LICENSE') (Join-Path $noticesStage 'brotli-LICENSE.txt')
$pthreadsNotice = Get-ChildItem (Join-Path $vcpkgInstalled 'share') -Recurse -File -Filter 'copyright' -ErrorAction SilentlyContinue | Where-Object { $_.Directory.Name -match 'pthread' } | Select-Object -First 1
if (-not $pthreadsNotice) {
    throw "Missing vcpkg pthreads license/copyright file in $vcpkgInstalled\share"
}
Copy-Item $pthreadsNotice.FullName (Join-Path $noticesStage 'pthreads-LICENSE.txt')
if (Test-Path (Join-Path $phpRuntimeDir 'license.txt')) {
    Copy-Item (Join-Path $phpRuntimeDir 'license.txt') (Join-Path $noticesStage 'php-LICENSE.txt')
}

# 7a. Seal Web source-relink kit first (avoids self-reference)
$webKitStage = Join-Path $WorkDir 'web-kit-stage'
New-Item -ItemType Directory -Force -Path $webKitStage | Out-Null
Copy-Item $frankenTar (Join-Path $webKitStage 'frankenphp-1.13.6-src.tar.gz')
Copy-Item $brotliTar (Join-Path $webKitStage 'brotli-1.2.1-src.tar.gz')
Copy-Item $phpDevelZip (Join-Path $webKitStage 'php-devel-pack-8.5.13-Win32-vs17-x64.zip')
Copy-Item (Join-Path $RepoRoot 'licenses\portable-web.json') (Join-Path $webKitStage 'portable-web.json')
Copy-Item (Join-Path $RepoRoot 'scripts\runtime\build-web-windows.ps1') (Join-Path $webKitStage 'build-web-windows.ps1')
Copy-Item $noticesStage (Join-Path $webKitStage 'notices') -Recurse
$webKitName = "yanji-web-1.13.6-windows-x86_64-source-relink.tar.gz"
$webKitPath = Join-Path $OutputDir $webKitName
& tar -czf $webKitPath -C $webKitStage .
if ($LASTEXITCODE -ne 0) { throw "Failed to pack Web source-relink kit" }
$webKitSha = Get-FileSha256 $webKitPath

# 7b. Seal Web runtime bundle (frankenphp.exe + Brotli 1.2.1 DLLs + pthreads DLL + manifest + notices)
$webRuntimeStage = Join-Path $WorkDir 'web-runtime-stage'
New-Item -ItemType Directory -Force -Path (Join-Path $webRuntimeStage 'bin'), (Join-Path $webRuntimeStage 'notices') | Out-Null
Copy-Item $outExe (Join-Path $webRuntimeStage 'bin\frankenphp.exe')
$webDllNames = @('brotlienc.dll', 'brotlidec.dll', 'brotlicommon.dll')
foreach ($dll in $webDllNames) {
    $srcDll = Join-Path $brotliPrefix "bin\$dll"
    if (Test-Path $srcDll) { Copy-Item $srcDll (Join-Path $webRuntimeStage "bin\$dll") }
}
foreach ($dll in (Get-ChildItem (Join-Path $vcpkgInstalled 'bin') -Filter 'pthread*.dll' -ErrorAction SilentlyContinue)) {
    Copy-Item $dll.FullName (Join-Path $webRuntimeStage "bin\$($dll.Name)")
    $webDllNames += $dll.Name
}
Copy-Item "$noticesStage\*" (Join-Path $webRuntimeStage 'notices') -Recurse
$webExeSha = Get-FileSha256 (Join-Path $webRuntimeStage 'bin\frankenphp.exe')
@{
    schema_version = 1
    platform = 'windows-x86_64'
    frankenphp_version = $Spec.frankenphp.version
    php_version = $Spec.php.version
    brotli_version = $Spec.brotli.patched_version
    binary_sha256 = $webExeSha
    source_kit = @{ name = $webKitName; sha256 = $webKitSha }
} | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $webRuntimeStage 'build-manifest.json') -Encoding UTF8

$webRuntimeName = "yanji-web-1.13.6-windows-x86_64-runtime.tar.gz"
$webRuntimePath = Join-Path $OutputDir $webRuntimeName
& tar -czf $webRuntimePath -C $webRuntimeStage .
if ($LASTEXITCODE -ne 0) { throw "Failed to pack Web runtime archive" }
$webRuntimeSha = Get-FileSha256 $webRuntimePath

# 7c. Seal PHP 8.5.13 Windows CLI/ZTS runtime bundle (php.exe + php8ts.dll + required DLLs + CA)
$cliRuntimeStage = Join-Path $WorkDir 'cli-runtime-stage'
New-Item -ItemType Directory -Force -Path (Join-Path $cliRuntimeStage 'bin\ext'), (Join-Path $cliRuntimeStage 'ca'), (Join-Path $cliRuntimeStage 'notices\php') | Out-Null
Copy-Item (Join-Path $phpRuntimeDir 'php.exe') (Join-Path $cliRuntimeStage 'bin\php.exe')
foreach ($dll in (Get-ChildItem $phpRuntimeDir -Filter '*.dll')) {
    # Skip Apache/phpdbg SAPI DLLs and DLLs provided by web_runtime (e.g. patched Brotli 1.2.1 / pthreads)
    if ($dll.Name -in (@('php8apache2_4.dll', 'php8phpdbg.dll') + $webDllNames)) { continue }
    Copy-Item $dll.FullName (Join-Path $cliRuntimeStage "bin\$($dll.Name)")
}
foreach ($ext in @('php_curl.dll', 'php_fileinfo.dll', 'php_gd.dll', 'php_mbstring.dll', 'php_openssl.dll', 'php_pdo_sqlite.dll', 'php_sqlite3.dll', 'php_zip.dll')) {
    Copy-Item (Join-Path $phpRuntimeDir "ext\$ext") (Join-Path $cliRuntimeStage "bin\ext\$ext")
}
Copy-Item $caPem (Join-Path $cliRuntimeStage 'ca\cacert.pem')
$caNoticeText = "Mozilla CA certificate bundle extracted by curl (https://curl.se/docs/caextract.html).`nSource URL: $($Spec.ca_bundle.url)`nSHA-256: $($Spec.ca_bundle.sha256)`nLicense: MPL-2.0 (https://www.mozilla.org/en-US/MPL/2.0/)`n"
[System.IO.File]::WriteAllText((Join-Path $cliRuntimeStage 'ca\NOTICE.txt'), $caNoticeText, [System.Text.UTF8Encoding]::new($false))
if (Test-Path (Join-Path $phpRuntimeDir 'license.txt')) {
    Copy-Item (Join-Path $phpRuntimeDir 'license.txt') (Join-Path $cliRuntimeStage 'notices\php\LICENSE.txt')
}
$cliRuntimeName = "yanji-php-8.5.13-windows-x86_64-runtime.tar.gz"
$cliRuntimePath = Join-Path $OutputDir $cliRuntimeName
& tar -czf $cliRuntimePath -C $cliRuntimeStage .
if ($LASTEXITCODE -ne 0) { throw "Failed to pack PHP Windows runtime archive" }
$cliRuntimeSha = Get-FileSha256 $cliRuntimePath

$cliKitStage = Join-Path $WorkDir 'cli-kit-stage'
New-Item -ItemType Directory -Force -Path $cliKitStage | Out-Null
Copy-Item $phpZip (Join-Path $cliKitStage 'php-8.5.13-Win32-vs17-x64.zip')
Copy-Item (Join-Path $RepoRoot 'licenses\portable-web.json') (Join-Path $cliKitStage 'portable-web.json')
$cliKitName = "yanji-php-8.5.13-windows-x86_64-source-relink.tar.gz"
$cliKitPath = Join-Path $OutputDir $cliKitName
& tar -czf $cliKitPath -C $cliKitStage .
if ($LASTEXITCODE -ne 0) { throw "Failed to pack PHP Windows source kit" }
$cliKitSha = Get-FileSha256 $cliKitPath

@{
    platform = 'windows-x86_64'
    web_runtime = @{ name = $webRuntimeName; sha256 = $webRuntimeSha; binary_sha256 = $webExeSha }
    web_source_kit = @{ name = $webKitName; sha256 = $webKitSha }
    cli_runtime = @{ name = $cliRuntimeName; sha256 = $cliRuntimeSha }
    cli_source_kit = @{ name = $cliKitName; sha256 = $cliKitSha }
} | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $OutputDir 'windows-web-outputs.json') -Encoding UTF8
Get-Content (Join-Path $OutputDir 'windows-web-outputs.json')
