@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ColdAisle SNMP poll / health launcher
REM   run_poll_snmp.cmd           full poll
REM   run_poll_snmp.cmd --health  step-by-step CLI health (no snmp.dll)

set "SITE_ROOT=%~dp0.."
for %%I in ("%SITE_ROOT%") do set "SITE_ROOT=%%~fI"

set "POLL_PHP=%SITE_ROOT%\scripts\poll_snmp.php"
set "HEALTH_PHP=%SITE_ROOT%\scripts\health_cli.php"

if not exist "%POLL_PHP%" (
  echo ERROR: poll_snmp.php not found: %POLL_PHP%
  exit /b 1
)

set "PHP_EXE="
if exist "C:\PHP\php.exe" set "PHP_EXE=C:\PHP\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE (
  where php.exe >nul 2>&1 && for /f "delims=" %%P in ('where php.exe 2^>nul') do (
    set "PHP_EXE=%%P"
    goto :php_ok
  )
)
:php_ok
if not defined PHP_EXE (
  echo ERROR: php.exe not found
  exit /b 1
)

for %%I in ("%PHP_EXE%") do set "PHP_DIR=%%~dpI"
set "EXT_DIR=%PHP_DIR%ext"
if not exist "%EXT_DIR%" (
  echo ERROR: extension dir missing: %EXT_DIR%
  exit /b 1
)

set "LOG_DIR=%SITE_ROOT%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1
set "MIB_NOISE=%LOG_DIR%\snmp_mib_noise.log"
set "MIB_DIR=%SITE_ROOT%\storage\snmp\mibs"
if not exist "%MIB_DIR%" mkdir "%MIB_DIR%" >nul 2>&1
if not exist "C:\usr\share\snmp\mibs" mkdir "C:\usr\share\snmp\mibs" >nul 2>&1
set "MIB_DIR_UNIX=%MIB_DIR:\=/%"
set "SNMP_HOME=%SITE_ROOT%\storage\snmp"
if not exist "%SNMP_HOME%" mkdir "%SNMP_HOME%" >nul 2>&1
set "MIBS="
set "MIBDIRS=%MIB_DIR%"
set "SNMPCONFPATH=%SNMP_HOME%"

cd /d "%SITE_ROOT%"

if /I "%~1"=="--health" goto :health
if /I "%~1"=="-h" goto :health
goto :fullpoll

REM =========================================================================
:health
echo.
echo === ColdAisle CLI health (no snmp.dll) ===
echo SITE_ROOT=%SITE_ROOT%
echo PHP_EXE=%PHP_EXE%
echo EXT_DIR=%EXT_DIR%
echo.

echo [1/4] Bare PHP (-n, no extensions)...
"%PHP_EXE%" -n -r "echo 'bare-ok';"
if errorlevel 1 (
  echo FAIL: bare php -n failed
  exit /b 1
)
echo.

echo [2/4] List PDO drivers available when loading sqlsrv/odbc...
REM Prefer ODBC first - sqlsrv native often hangs/misconfigured on servers
set "PDO_EXT="
if exist "%EXT_DIR%\php_pdo_odbc.dll" set "PDO_EXT=pdo_odbc"
if not defined PDO_EXT if exist "%EXT_DIR%\php_pdo_sqlsrv.dll" set "PDO_EXT=pdo_sqlsrv"
if not defined PDO_EXT (
  echo FAIL: neither php_pdo_odbc.dll nor php_pdo_sqlsrv.dll in %EXT_DIR%
  dir /b "%EXT_DIR%\*pdo*"
  exit /b 1
)
echo Using extension=%PDO_EXT%

echo [3/4] Load PDO extension only...
"%PHP_EXE%" -n -d extension_dir="%EXT_DIR%" -d extension=%PDO_EXT% -r "echo 'pdo-ok drivers=' . implode(',', PDO::getAvailableDrivers());"
if errorlevel 1 (
  echo FAIL: could not load %PDO_EXT%
  echo Try the other driver or install Microsoft ODBC Driver 17/18 + PHP pdo_* DLL.
  exit /b 1
)
echo.

echo [4/4] SQL health_cli.php (unbuffered, TCP probe + 5s SQL timeout)...
if not exist "%HEALTH_PHP%" (
  echo FAIL: health_cli.php missing - deploy ColdAisle 0.2.60+
  exit /b 1
)
echo Running: "%PHP_EXE%" -n ... health_cli.php
echo If nothing appears below, PHP is stuck before first log line - check file deploy.
"%PHP_EXE%" -n -d extension_dir="%EXT_DIR%" -d extension=%PDO_EXT% -d output_buffering=0 -d implicit_flush=1 -d max_execution_time=25 -d default_socket_timeout=5 -- "%HEALTH_PHP%"
set "EC=!ERRORLEVEL!"
if not "!EC!"=="0" (
  echo.
  echo health_cli failed exit=!EC!
  echo Check config\config.php SQL host/user; CLI identity must reach SQL.
  echo Log: %LOG_DIR%\snmp_poll_cli.log
  exit /b !EC!
)
echo.
echo === health finished OK ===
endlocal & exit /b 0

REM =========================================================================
:fullpoll
echo Starting full SNMP poll via poll_snmp.php ...
set "COLDAISLE_CLI_LIGHT=1"
>>"%MIB_NOISE%" echo ----- %DATE% %TIME% full poll -----
"%PHP_EXE%" -d max_execution_time=240 -d default_socket_timeout=3 -d snmp.mib_directory="%MIB_DIR_UNIX%" -- "%POLL_PHP%" %* 2>>"%MIB_NOISE%"
set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%
