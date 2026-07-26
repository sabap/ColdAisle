@echo off
setlocal EnableExtensions

REM ColdAisle SNMP poll launcher (Task Scheduler + manual CLI).
REM Net-SNMP on Windows PHP prints "Cannot find module (IP-MIB)" when the
REM extension loads. Those messages are harmless for numeric-OID polling but
REM flood the console and can confuse Task Scheduler / PowerShell.
REM
REM This launcher:
REM   1) Points Net-SNMP at a local empty mib dir + snmp.conf
REM   2) Runs php with snmp.mib_directory set
REM   3) Sends Net-SNMP stderr noise to storage\logs\snmp_mib_noise.log
REM      (stdout stays visible: health=ok, Success/Failed counts, etc.)
REM
REM   run_poll_snmp.cmd
REM   run_poll_snmp.cmd --health

REM Clear default MIB load list (empty = do not request IP-MIB etc. by name)
set "MIBS="
set "MIBDIRS="

set "SITE_ROOT=%~dp0.."
for %%I in ("%SITE_ROOT%") do set "SITE_ROOT=%%~fI"

set "POLL_PHP=%SITE_ROOT%\scripts\poll_snmp.php"
if not exist "%POLL_PHP%" (
  echo ERROR: poll_snmp.php not found: %POLL_PHP%
  exit /b 1
)

set "SNMP_HOME=%SITE_ROOT%\storage\snmp"
set "MIB_DIR=%SNMP_HOME%\mibs"
set "SNMP_CONF=%SNMP_HOME%\snmp.conf"
if not exist "%SNMP_HOME%" mkdir "%SNMP_HOME%" >nul 2>&1
if not exist "%MIB_DIR%" mkdir "%MIB_DIR%" >nul 2>&1
if not exist "C:\usr\share\snmp\mibs" mkdir "C:\usr\share\snmp\mibs" >nul 2>&1

if not exist "%SNMP_CONF%" (
  >"%SNMP_CONF%" echo mibdirs %MIB_DIR:\=/%
)

set "SNMPCONFPATH=%SNMP_HOME%"
set "MIBDIRS=%MIB_DIR%"

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

cd /d "%SITE_ROOT%"

set "LOG_DIR=%SITE_ROOT%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1
set "MIB_NOISE=%LOG_DIR%\snmp_mib_noise.log"

REM Timestamp separator in noise log (optional, ignore errors)
>>"%MIB_NOISE%" echo ----- %DATE% %TIME% -----

REM IMPORTANT: stderr (2) goes to the noise log so PowerShell/console stay clean.
REM stdout (1) is not redirected so you still see health=ok / Success: N
"%PHP_EXE%" -d max_execution_time=240 -d default_socket_timeout=3 -d snmp.mib_directory="%MIB_DIR_UNIX%" -- "%POLL_PHP%" %* 2>>"%MIB_NOISE%"

set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%
