@echo off
chcp 65001 >nul 2>&1
setlocal
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
if not exist "%ROOT%\current-release.txt" (
  echo 实例缺少已验证的程序版本，请重新运行安装器。 1>&2
  exit /b 1
)
set /p RELEASE=<"%ROOT%\current-release.txt"
set "RELEASE=%RELEASE:/=\%"
set "PHPRC="
set "PHP_INI_SCAN_DIR="
set "YANJI_CA_BUNDLE=%RELEASE%\runtime\ca\cacert.pem"
"%RELEASE%\runtime\bin\php.exe" -n -d "extension_dir=%RELEASE%\runtime\bin\ext" -d "curl.cainfo=%YANJI_CA_BUNDLE%" -d "openssl.cafile=%YANJI_CA_BUNDLE%" -d extension=curl -d extension=fileinfo -d extension=gd -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 -d extension=zip "%RELEASE%\app\portable\yanji.php" %* --instance-default "%ROOT%"
exit /b %errorlevel%
