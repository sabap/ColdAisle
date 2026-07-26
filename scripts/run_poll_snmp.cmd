@echo off
REM ColdAisle SNMP poll worker launcher for Windows Task Scheduler / CLI.
REM Silences Net-SNMP "Cannot find module" spam and avoids long MIB scans on Windows.
REM
REM Usage:
REM   run_poll_snmp.cmd
REM   run_poll_snmp.cmd --health

setlocal

REM Do not auto-load the full standard MIB set (missing on most Windows PHP builds)
set "MIBS="
set "MIBDIRS="
set "SNMPCONFPATH="

REM Locate site root (this .cmd lives in scripts\)
set "SITE_ROOT=%~dp0.."
for %%I in ("%SITE_ROOT%") do set "SITE_ROOT=%%~fI"

set "POLL_PHP=%SITE_ROOT%\scripts\poll_snmp.php"
if not exist "%POLL_PHP%" (
  echo poll_snmp.php not found: %POLL_PHP%
  exit /b 1
)

REM Prefer empty/local mib dir so Net-SNMP does not walk c:/usr/share/snmp/mibs
set "MIB_DIR=%SITE_ROOT%\storage\snmp\mibs"
if not exist "%MIB_DIR%" mkdir "%MIB_DIR%" >nul 2>&1

REM Find php.exe
set "PHP_EXE=C:\PHP\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=C:\php\php.exe"
if not exist "%PHP_EXE%" (
  where php.exe >nul 2>&1
  if errorlevel 1 (
    echo php.exe not found. Edit run_poll_snmp.cmd or put PHP on PATH.
    exit /b 1
  )
  for /f "delims=" %%P in ('where php.exe') do (
    set "PHP_EXE=%%P"
    goto :php_found
  )
)
:php_found

cd /d "%SITE_ROOT%"

REM -d snmp.mib_directory must be set at process start (extension init)
"%PHP_EXE%" -d max_execution_time=240 -d default_socket_timeout=3 -d snmp.mib_directory="%MIB_DIR%" -- "%POLL_PHP%" %*
set "EC=%ERRORLEVEL%"
endlocal & exit /b %EC%
