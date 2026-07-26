@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ColdAisle poll / health launcher
REM   run_poll_snmp.cmd --health
REM   run_poll_snmp.cmd
REM
REM BOTH modes use php -n (ignore php.ini) so we control extensions.
REM Full php.ini loads snmp.dll at process start and can hang on Windows MIB init
REM before poll_snmp.php runs (no log lines).

set "SITE_ROOT=%~dp0.."
for %%I in ("%SITE_ROOT%") do set "SITE_ROOT=%%~fI"

set "POLL_PHP=%SITE_ROOT%\scripts\poll_snmp.php"
set "HEALTH_PHP=%SITE_ROOT%\scripts\health_cli.php"
set "MARKER=%SITE_ROOT%\storage\logs\health_last_step.txt"
set "LOG_DIR=%SITE_ROOT%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1

set "PHP_EXE="
if exist "C:\PHP\php.exe" set "PHP_EXE=C:\PHP\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE where php.exe >nul 2>&1 && for /f "delims=" %%P in ('where php.exe') do set "PHP_EXE=%%P" & goto :php_done
:php_done
if not defined PHP_EXE (
  echo ERROR: php.exe not found
  exit /b 1
)

for %%I in ("%PHP_EXE%") do set "EXT_DIR=%%~dpIext"
if not exist "%EXT_DIR%" (
  echo ERROR: missing %EXT_DIR%
  exit /b 1
)

set "MIB_DIR=%SITE_ROOT%\storage\snmp\mibs"
if not exist "%MIB_DIR%" mkdir "%MIB_DIR%" >nul 2>&1
if not exist "C:\usr\share\snmp\mibs" mkdir "C:\usr\share\snmp\mibs" >nul 2>&1
set "MIB_DIR_UNIX=%MIB_DIR:\=/%"
set "SNMP_HOME=%SITE_ROOT%\storage\snmp"
if not exist "%SNMP_HOME%" mkdir "%SNMP_HOME%" >nul 2>&1
if not exist "%SNMP_HOME%\snmp.conf" (
  >"%SNMP_HOME%\snmp.conf" echo mibdirs %MIB_DIR_UNIX%
)

REM Net-SNMP env BEFORE php.exe (full poll loads snmp)
set "MIBS="
set "MIBDIRS=%MIB_DIR%"
set "SNMPCONFPATH=%SNMP_HOME%"

set "MIB_NOISE=%LOG_DIR%\snmp_mib_noise.log"

cd /d "%SITE_ROOT%"

if /I "%~1"=="--health" goto :health
if /I "%~1"=="-h" goto :health
goto :fullpoll

REM =====================================================================
:health
echo.
echo === ColdAisle CLI health ===
echo SITE_ROOT=%SITE_ROOT%
echo PHP_EXE=%PHP_EXE%
echo EXT_DIR=%EXT_DIR%
echo HEALTH_PHP=%HEALTH_PHP%
if not exist "%HEALTH_PHP%" (
  echo FAIL: health_cli.php not found
  exit /b 1
)
for %%A in ("%HEALTH_PHP%") do echo HEALTH_SIZE=%%~zA  DATE=%%~tA
echo.

echo [1/5] Bare PHP...
"%PHP_EXE%" -n -r "fwrite(STDOUT,'bare-ok'.PHP_EOL);"
if errorlevel 1 exit /b 1
echo.

set "PDO_EXT="
if exist "%EXT_DIR%\php_pdo_odbc.dll" set "PDO_EXT=pdo_odbc"
if not defined PDO_EXT if exist "%EXT_DIR%\php_pdo_sqlsrv.dll" set "PDO_EXT=pdo_sqlsrv"
if not defined PDO_EXT (
  echo FAIL: need pdo_odbc or pdo_sqlsrv in %EXT_DIR%
  exit /b 1
)
echo [2/5] PDO extension=%PDO_EXT%
"%PHP_EXE%" -n -d extension_dir="%EXT_DIR%" -d extension=%PDO_EXT% -r "fwrite(STDOUT,'pdo-ok '.implode(',',PDO::getAvailableDrivers()).PHP_EOL);"
if errorlevel 1 exit /b 1
echo.

echo [3/5] Clear marker
del "%MARKER%" >nul 2>&1
echo marker=%MARKER%
echo.

echo [4/5] health_cli.php
"%PHP_EXE%" -n -d extension_dir="%EXT_DIR%" -d extension=%PDO_EXT% -d output_buffering=Off -d implicit_flush=1 -d max_execution_time=25 -d default_socket_timeout=5 -f "%HEALTH_PHP%"
set "EC=!ERRORLEVEL!"

echo.
echo [5/5] exit=!EC!
if exist "%MARKER%" (
  echo --- health_last_step.txt ---
  type "%MARKER%"
  echo --- end ---
)
if not "!EC!"=="0" exit /b !EC!
echo health OK
endlocal & exit /b 0

REM =====================================================================
:fullpoll
echo === ColdAisle full SNMP poll (php -n + explicit extensions) ===
echo SITE_ROOT=%SITE_ROOT%
echo PHP_EXE=%PHP_EXE%
echo POLL_PHP=%POLL_PHP%
if not exist "%POLL_PHP%" (
  echo FAIL: poll_snmp.php missing
  exit /b 1
)

REM Build extension list (only what exists). Order: deps first.
set "EXTS="
if exist "%EXT_DIR%\php_openssl.dll" set "EXTS=!EXTS! -d extension=openssl"
if exist "%EXT_DIR%\php_mbstring.dll" set "EXTS=!EXTS! -d extension=mbstring"
if exist "%EXT_DIR%\php_curl.dll" set "EXTS=!EXTS! -d extension=curl"
if exist "%EXT_DIR%\php_fileinfo.dll" set "EXTS=!EXTS! -d extension=fileinfo"
if exist "%EXT_DIR%\php_pdo_odbc.dll" (
  set "EXTS=!EXTS! -d extension=pdo_odbc"
) else if exist "%EXT_DIR%\php_pdo_sqlsrv.dll" (
  set "EXTS=!EXTS! -d extension=pdo_sqlsrv"
  if exist "%EXT_DIR%\php_sqlsrv.dll" set "EXTS=!EXTS! -d extension=sqlsrv"
)
if exist "%EXT_DIR%\php_snmp.dll" (
  set "EXTS=!EXTS! -d extension=snmp"
) else if exist "%EXT_DIR%\php_snmp.dll" (
  set "EXTS=!EXTS! -d extension=snmp"
) else (
  REM some builds name it snmp.dll without php_ prefix in -d form
  if exist "%EXT_DIR%\php_snmp.dll" set "EXTS=!EXTS! -d extension=snmp"
)
REM detect snmp dll name
set "HAS_SNMP=0"
if exist "%EXT_DIR%\php_snmp.dll" set "HAS_SNMP=1"
if "!HAS_SNMP!"=="0" (
  echo ERROR: php_snmp.dll not found in %EXT_DIR%
  dir /b "%EXT_DIR%\*snmp*" 2>nul
  exit /b 1
)
REM Ensure snmp is in EXTS (in case block above missed)
echo !EXTS! | findstr /I "extension=snmp" >nul
if errorlevel 1 set "EXTS=!EXTS! -d extension=snmp"

echo Extensions:!EXTS!
echo.

set "COLDAISLE_CLI_LIGHT=1"
>>"%MIB_NOISE%" echo ----- %DATE% %TIME% full poll -----

REM php -n: do NOT load default php.ini snmp at process start with broken MIB path.
REM Load snmp explicitly with snmp.mib_directory set in the same argv.
echo Starting poll_snmp.php ...
"%PHP_EXE%" -n ^
  -d extension_dir="%EXT_DIR%" ^
  !EXTS! ^
  -d snmp.mib_directory="%MIB_DIR_UNIX%" ^
  -d max_execution_time=240 ^
  -d default_socket_timeout=3 ^
  -d output_buffering=Off ^
  -f "%POLL_PHP%" 2>>"%MIB_NOISE%"

set "EC=%ERRORLEVEL%"
echo poll exit=%EC%
if exist "%LOG_DIR%\snmp_poll_cli.log" (
  echo --- last log lines ---
  powershell -NoProfile -Command "Get-Content -LiteralPath '%LOG_DIR%\snmp_poll_cli.log' -Tail 15 -ErrorAction SilentlyContinue"
)
endlocal & exit /b %EC%
