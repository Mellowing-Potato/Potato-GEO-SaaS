@echo off
setlocal
chcp 65001 >nul
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
set "EXIT_CODE=%errorlevel%"
if not "%EXIT_CODE%"=="0" (
  echo.
  echo Yanji installation or startup did not complete ^(exit code %EXIT_CODE%^). 1>&2
  echo %CMDCMDLINE% | find /i "%~0" >nul && pause
)
exit /b %EXIT_CODE%
