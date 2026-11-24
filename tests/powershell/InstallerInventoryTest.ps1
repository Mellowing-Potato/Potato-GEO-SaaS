# Exercise the actual installer inventory check with byte-distinct, text-equivalent files.
param([string]$Installer = (Join-Path $PSScriptRoot '..\..\scripts\install-local.ps1'))
$ErrorActionPreference = 'Stop'
$source = [System.IO.File]::ReadAllText((Resolve-Path -LiteralPath $Installer))
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseInput($source, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw 'Installer did not parse.' }
$hashFunction = $ast.Find({
    param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Get-Sha256'
}, $false)
. ([scriptblock]::Create($hashFunction.Extent.Text))
function Fail([string]$Message) { throw $Message }

# Execute the production statements between inventory-path selection and tree enumeration.
$start = $source.IndexOf('    $cachedInvPath =')
$end = $source.IndexOf('    $allItems =', $start)
if ($start -lt 0 -or $end -lt 0) { throw 'Installer inventory check was not found.' }
$check = [scriptblock]::Create($source.Substring($start, $end - $start))
$release = Join-Path ([System.IO.Path]::GetTempPath()) ('yanji-inventory-test-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $release | Out-Null
try {
    $manifestFilesContent = ('0' * 64) + '  app/VERSION' + [char]10
    $inventoryPath = Join-Path $release 'PACKAGE-FILES.sha256'
    $utf8 = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($inventoryPath, $manifestFilesContent, $utf8)
    $manifestFilesSha256 = Get-Sha256 $inventoryPath
    & $check
    foreach ($encoding in @([System.Text.UTF8Encoding]::new($true), [System.Text.UnicodeEncoding]::new($false, $true))) {
        [System.IO.File]::WriteAllText($inventoryPath, $manifestFilesContent, $encoding)
        $rejected = $false
        try { & $check } catch {
            if ($_.Exception.Message -notmatch '程序文件清单已更改') { throw }
            $rejected = $true
        }
        if (-not $rejected) { throw 'Text-equivalent inventory bytes were accepted after an encoding change.' }
    }
    Write-Output 'Inventory regression passed: exact bytes accepted, UTF-8 BOM and UTF-16 rejected.'
} finally {
    Remove-Item -LiteralPath $release -Recurse -Force
}
