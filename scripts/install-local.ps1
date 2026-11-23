[CmdletBinding()]
param([switch]$Diagnosis)
$ErrorActionPreference = 'Stop'
if ($Diagnosis) {
    Write-Output ('系统 {0}；进程架构 {1}' -f [Environment]::OSVersion.VersionString, $env:PROCESSOR_ARCHITECTURE)
}
Write-Error 'Windows x64 原生便携包尚未通过验收。当前生产锁包含 Horizon 所需的 pcntl/posix；还需验证 VC Runtime、NTFS 权限和每项作业超时。现阶段请使用仓库 INSTALL.md 的 Docker 安装入口。'
exit 1
