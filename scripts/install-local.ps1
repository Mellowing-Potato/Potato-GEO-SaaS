[CmdletBinding()]
param([switch]$Diagnosis)
$ErrorActionPreference = 'Stop'
if ($Diagnosis) {
    Write-Output ('系统 {0}；进程架构 {1}' -f [Environment]::OSVersion.VersionString, $env:PROCESSOR_ARCHITECTURE)
}
Write-Error 'Windows x64 原生便携包正在完成最终托管层与真机验收（Phase 4/5）。现阶段请使用仓库 INSTALL.md 的 Docker 安装入口。'
exit 1
