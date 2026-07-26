@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ColdAisle poll / health launcher
REM   run_poll_snmp.cmd --health
REM   run_poll_snmp.cmd

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
set "MIBS="
set "MIBDIRS=%MIB_DIR%"
set "SNMPCONFPATH=%SNMP_HOME%"
set "MIB_NOISE=%LOG_DIR%\snmp_mib_noise.log"

cd /d "%SITE_ROOT%"

if /I "%~1"=="--health" goto :health
if /I "%~1"=="-h" goto :health
goto :fullpoll

:health
echo.
echo === ColdAisle CLI health ===
echo SITE_ROOT=%SITE_ROOT%
echo PHP_EXE=%PHP_EXE%
echo EXT_DIR=%EXT_DIR%
echo HEALTH_PHP=%HEALTH_PHP%
if not exist "%HEALTH_PHP%" (
  echo FAIL: health_cli.php not found - deploy latest ColdAisle
  exit /b 1
)
for %%A in ("%HEALTH_PHP%") do echo HEALTH_SIZE=%%~zA bytes  DATE=%%~tA
echo.

echo [1/5] Bare PHP...
"%PHP_EXE%" -n -r "fwrite(STDOUT,'bare-ok'.PHP_EOL);"
if errorlevel 1 exit /b 1
echo.

set "PDO_EXT="
if exist "%EXT_DIR%\php_pdo_odbc.dll" set "PDO_EXT=pdo_odbc"
if not defined PDO_EXT if exist "%EXT_DIR%\php_pdo_sqlsrv.dll" set "PDO_EXT=pdo_sqlsrv"
if not defined PDO_EXT (
  echo FAIL: no pdo_odbc or pdo_sqlsrv DLL
  exit /b 1
)
echo [2/5] PDO extension=%PDO_EXT%
"%PHP_EXE%" -n -d extension_dir="%EXT_DIR%" -d extension=%PDO_EXT% -r "fwrite(STDOUT,'pdo-ok '.implode(',',PDO::getAvailableDrivers()).PHP_EOL);"
if errorlevel 1 (
  echo FAIL: loading %PDO_EXT%
  exit /b 1
)
echo.

echo [3/5] Clear marker + invoke health_cli.php with -f
del "%MARKER%" >nul 2>&1
echo marker will be: %MARKER%
echo.

echo [4/5] Starting PHP (max 25s). Watch marker file in another window:
echo   type "%MARKER%"
echo.

REM -f script  (avoid "-- script" quirks). No snmp. Short timeouts.
"%PHP_EXE%" -n -d extension_dir="%EXT_DIR%" -d extension=%PDO_EXT% -d output_buffering=Off -d implicit_flush=1 -d max_execution_time=25 -d default_socket_timeout=5 -f "%HEALTH_PHP%"
set "EC=!ERRORLEVEL!"

echo.
echo [5/5] PHP exit code=!EC!
if exist "%MARKER%" (
  echo --- health_last_step.txt ---
  type "%MARKER%"
  echo --- end marker ---
) else (
  echo WARNING: marker file was never created - PHP may not have executed health_cli.php
)

if not "!EC!"=="0" (
  echo health FAILED
  exit /b !EC!
)
echo health OK
endlocal & exit /b 0

:fullpoll
echo Full SNMP poll...
set "COLDAISLE_CLI_LIGHT=1"
>>"%MIB_NOISE%" echo ----- %DATE% %TIME% -----
"%PHP_EXE%" -d max_execution_time=240 -d default_socket_timeout=3 -d snmp.mib_directory="%MIB_DIR_UNIX%" -f "%POLL_PHP%" 2>>"%MIB_NOISE%"
set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%
