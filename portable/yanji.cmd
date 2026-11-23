@echo off
setlocal
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
if not exist "%ROOT%\current-release.txt" (
  echo Missing verified Yanji release pointer. Please re-run the installer. 1>&2
  exit /b 1
)
set /p RELEASE=<"%ROOT%\current-release.txt"
set "RELEASE=%RELEASE:/=\%"
set "RELEASE_INI=%RELEASE%"
for %%I in ("%RELEASE%") do if exist "%%~sI\runtime\bin\ext" set "RELEASE_INI=%%~sI"
set "PHPRC="
set "PHP_INI_SCAN_DIR="
set "YANJI_CA_BUNDLE=%RELEASE_INI%\runtime\ca\cacert.pem"
"%RELEASE%\runtime\bin\php.exe" -n -d "extension_dir=%RELEASE_INI%\runtime\bin\ext" -d "curl.cainfo=%YANJI_CA_BUNDLE%" -d "openssl.cafile=%YANJI_CA_BUNDLE%" -d extension=curl -d extension=fileinfo -d extension=gd -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 -d extension=zip "%RELEASE%\app\portable\yanji.php" %* --instance-default "%ROOT%"
exit /b %errorlevel%
