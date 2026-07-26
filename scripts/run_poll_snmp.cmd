@echo off
setlocal EnableExtensions

REM ColdAisle SNMP poll / health launcher for Windows.
REM
REM   run_poll_snmp.cmd              full poll (loads snmp via php.ini)
REM   run_poll_snmp.cmd --health     SQL-only health (php -n, NO snmp.dll)

set "SITE_ROOT=%~dp0.."
for %%I in ("%SITE_ROOT%") do set "SITE_ROOT=%%~fI"

set "POLL_PHP=%SITE_ROOT%\scripts\poll_snmp.php"
set "HEALTH_PHP=%SITE_ROOT%\scripts\health_cli.php"
if not exist "%POLL_PHP%" (
  echo ERROR: poll_snmp.php not found: %POLL_PHP%
  exit /b 1
)

set "SNMP_HOME=%SITE_ROOT%\storage\snmp"
set "MIB_DIR=%SNMP_HOME%\mibs"
if not exist "%SNMP_HOME%" mkdir "%SNMP_HOME%" >nul 2>&1
if not exist "%MIB_DIR%" mkdir "%MIB_DIR%" >nul 2>&1
if not exist "C:\usr\share\snmp\mibs" mkdir "C:\usr\share\snmp\mibs" >nul 2>&1
if not exist "%SNMP_HOME%\snmp.conf" (
  >"%SNMP_HOME%\snmp.conf" echo mibdirs %MIB_DIR:\=/%
)

set "MIBS="
set "MIBDIRS=%MIB_DIR%"
set "SNMPCONFPATH=%SNMP_HOME%"
set "MIB_DIR_UNIX=%MIB_DIR:\=/%"

set "PHP_EXE="
if exist "C:\PHP\php.exe" set "PHP_EXE=C:\PHP\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE (
  where php.exe >nul 2>&1
  if not errorlevel 1 (
    for /f "delims=" %%P in ('where php.exe 2^>nul') do (
      set "PHP_EXE=%%P"
      goto :have_php
    )
  )
)
:have_php
if not defined PHP_EXE (
  echo ERROR: php.exe not found.
  exit /b 1
)

REM Extension dir for php -n health path
set "EXT_DIR="
for %%I in ("%PHP_EXE%") do set "PHP_DIR=%%~dpI"
if exist "%PHP_DIR%ext\php_pdo_sqlsrv.dll" set "EXT_DIR=%PHP_DIR%ext"
if not defined EXT_DIR if exist "%PHP_DIR%ext\php_pdo_odbc.dll" set "EXT_DIR=%PHP_DIR%ext"
if not defined EXT_DIR if exist "%PHP_DIR%ext" set "EXT_DIR=%PHP_DIR%ext"

set "LOG_DIR=%SITE_ROOT%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1
set "MIB_NOISE=%LOG_DIR%\snmp_mib_noise.log"

cd /d "%SITE_ROOT%"

REM ---------- HEALTH: no snmp.dll, short SQL timeout ----------
if /I "%~1"=="--health" goto :health
if /I "%~1"=="-h" goto :health
goto :fullpoll

:health
echo health_cli: launching (no snmp extension)...
if not exist "%HEALTH_PHP%" (
  echo ERROR: health_cli.php missing - deploy ColdAisle 0.2.58+
  exit /b 1
)
if not defined EXT_DIR (
  echo ERROR: PHP ext directory not found next to php.exe
  exit /b 1
)

REM php -n = ignore php.ini (so snmp.dll is NOT loaded — that was hanging CLI)
set "HARGS=-n -d extension_dir=%EXT_DIR% -d max_execution_time=20 -d default_socket_timeout=5"
REM Load only what health needs (try sqlsrv then odbc)
if exist "%EXT_DIR%\php_pdo_sqlsrv.dll" (
  set "HARGS=%HARGS% -d extension=pdo_sqlsrv"
) else if exist "%EXT_DIR%\php_pdo_odbc.dll" (
  set "HARGS=%HARGS% -d extension=pdo_odbc"
) else (
  echo ERROR: Need php_pdo_sqlsrv.dll or php_pdo_odbc.dll in %EXT_DIR%
  exit /b 1
)
REM Some builds need pdo base (usually built-in); openssl not required for health

"%PHP_EXE%" %HARGS% -- "%HEALTH_PHP%"
set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%

:fullpoll
>>"%MIB_NOISE%" echo ----- %DATE% %TIME% full poll -----
"%PHP_EXE%" -d max_execution_time=240 -d default_socket_timeout=3 -d snmp.mib_directory="%MIB_DIR_UNIX%" -- "%POLL_PHP%" %* 2>>"%MIB_NOISE%"
set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%
