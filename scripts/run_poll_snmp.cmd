@echo off
setlocal EnableExtensions

REM ColdAisle SNMP poll launcher for Task Scheduler + manual CLI.
REM Must silence Net-SNMP BEFORE php.exe starts (snmp.dll loads at process init).
REM
REM   run_poll_snmp.cmd
REM   run_poll_snmp.cmd --health

REM --- Net-SNMP: do not load default MIB set ---------------------------------
set "MIBS=none"
set "MIBDIRS="
set "SNMP_PERSISTENT_DIR="

REM Site root = parent of scripts\
set "SITE_ROOT=%~dp0.."
for %%I in ("%SITE_ROOT%") do set "SITE_ROOT=%%~fI"

set "POLL_PHP=%SITE_ROOT%\scripts\poll_snmp.php"
if not exist "%POLL_PHP%" (
  echo ERROR: poll_snmp.php not found: %POLL_PHP%
  exit /b 1
)

REM Config + empty mib dir for Net-SNMP / PHP snmp.mib_directory
set "SNMP_HOME=%SITE_ROOT%\storage\snmp"
set "MIB_DIR=%SNMP_HOME%\mibs"
set "SNMP_CONF=%SNMP_HOME%\snmp.conf"
if not exist "%SNMP_HOME%" mkdir "%SNMP_HOME%" >nul 2>&1
if not exist "%MIB_DIR%" mkdir "%MIB_DIR%" >nul 2>&1

REM Ensure snmp.conf exists with "mibs none"
if not exist "%SNMP_CONF%" (
  >"%SNMP_CONF%" echo mibs none
)

REM Net-SNMP reads snmp.conf from SNMPCONFPATH (directory, not file)
set "SNMPCONFPATH=%SNMP_HOME%"
set "MIBDIRS=%MIB_DIR%"

REM Forward-slash path for Net-SNMP / PHP (avoids backslash escape issues)
set "MIB_DIR_UNIX=%MIB_DIR:\=/%"
set "SNMP_HOME_UNIX=%SNMP_HOME:\=/%"

REM Find php.exe
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
  echo ERROR: php.exe not found. Install PHP or edit run_poll_snmp.cmd
  exit /b 1
)

cd /d "%SITE_ROOT%"

set "LOG_DIR=%SITE_ROOT%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1
set "MIB_NOISE=%LOG_DIR%\snmp_mib_noise.log"

REM php.ini override at process start + drop Net-SNMP stderr noise (MIB not found).
REM stdout is preserved so Task Scheduler / health checks still see results.
"%PHP_EXE%" ^
  -d max_execution_time=240 ^
  -d default_socket_timeout=3 ^
  -d snmp.mib_directory="%MIB_DIR_UNIX%" ^
  -- "%POLL_PHP%" %* 2>>"%MIB_NOISE%"

set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%
