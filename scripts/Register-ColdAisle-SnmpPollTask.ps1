#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Register (or update) the Windows Scheduled Task that runs ColdAisle SNMP polling.

.DESCRIPTION
    ColdAisle does NOT change Task Scheduler from the web UI (security).
    This script is meant to be downloaded from Settings → SNMP schedule and run
    once (elevated) on the application server.

    It creates a short fixed tick (default every 1 minute). Poll intervals and
    enable/disable are controlled in ColdAisle Settings; the worker skips work
    that is not due.

.PARAMETER SiteRoot
    ColdAisle web root (folder that contains scripts\poll_snmp.php).

.PARAMETER PhpExe
    Full path to php.exe (CLI).

.PARAMETER TaskName
    Scheduled task name.

.PARAMETER TickMinutes
    How often Windows invokes the worker (1–15). Keep this short (1 recommended);
    application interval lives in ColdAisle Settings.

.PARAMETER Unregister
    Remove the task instead of creating it.

.EXAMPLE
    # Elevated PowerShell
    .\Register-ColdAisle-SnmpPollTask.ps1

.EXAMPLE
    .\Register-ColdAisle-SnmpPollTask.ps1 -SiteRoot 'C:\inetpub\wwwroot\WinDCIM' -PhpExe 'C:\PHP\php.exe'
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = '__COLDAISLE_SITE_ROOT__',
    [string]$PhpExe = '__COLDAISLE_PHP_EXE__',
    [string]$TaskName = 'ColdAisle SNMP Poll',
    [ValidateRange(1, 15)]
    [int]$TickMinutes = 1,
    [switch]$Unregister
)

$ErrorActionPreference = 'Stop'

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = [Security.Principal.WindowsPrincipal]::new($id)
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this script in an elevated PowerShell window (Run as administrator).'
    }
}

Assert-Admin

if ($Unregister) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Removed scheduled task '$TaskName' (if it existed)." -ForegroundColor Green
    exit 0
}

if ($SiteRoot -match '^__COLDAISLE_' -or [string]::IsNullOrWhiteSpace($SiteRoot)) {
    throw 'SiteRoot was not substituted. Pass -SiteRoot "C:\inetpub\wwwroot\YourSite".'
}
if (-not (Test-Path -LiteralPath $SiteRoot)) {
    throw "SiteRoot not found: $SiteRoot"
}

$pollScript = Join-Path $SiteRoot 'scripts\poll_snmp.php'
if (-not (Test-Path -LiteralPath $pollScript)) {
    throw "poll_snmp.php not found at: $pollScript"
}

if ($PhpExe -match '^__COLDAISLE_' -or -not (Test-Path -LiteralPath $PhpExe)) {
    $candidates = @(
        'C:\PHP\php.exe',
        'C:\php\php.exe',
        (Join-Path $env:SystemDrive 'PHP\php.exe')
    )
    $found = $candidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if (-not $found) {
        throw "php.exe not found. Pass -PhpExe with the full path to the CLI binary."
    }
    $PhpExe = $found
}

Write-Host "PHP:       $PhpExe" -ForegroundColor Cyan
Write-Host "Script:    $pollScript" -ForegroundColor Cyan
Write-Host "Task:      $TaskName" -ForegroundColor Cyan
Write-Host "Tick:      every $TickMinutes minute(s)" -ForegroundColor Cyan
Write-Host "App interval hint (Settings): __COLDAISLE_INTERVAL_HINT__ seconds" -ForegroundColor DarkGray

# Smoke test CLI worker once
Write-Host "`n==> Test run (once)..." -ForegroundColor Cyan
$prev = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$testOut = & $PhpExe -- "$pollScript" 2>&1
$ErrorActionPreference = $prev
$testOut | ForEach-Object { Write-Host "    $_" }
if ($LASTEXITCODE -ne 0 -and $LASTEXITCODE -ne 2) {
    Write-Warning "Test run exited with code $LASTEXITCODE. Task will still be registered; fix SNMP/SQL if needed."
} else {
    Write-Host "    [OK] Worker invoked (exit $LASTEXITCODE)." -ForegroundColor Green
}

# Argument must be a single string for php.exe
$arg = '"' + $pollScript + '"'
$action = New-ScheduledTaskAction -Execute $PhpExe -Argument $arg -WorkingDirectory $SiteRoot
$start = (Get-Date).AddMinutes(1)
$trigger = New-ScheduledTaskTrigger -Once -At $start `
    -RepetitionInterval (New-TimeSpan -Minutes $TickMinutes) `
    -RepetitionDuration ([TimeSpan]::MaxValue)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'ColdAisle SNMP poll worker (scripts/poll_snmp.php). Intervals and enable/disable are controlled in ColdAisle Settings.' `
    -Force | Out-Null

Write-Host @"

================================================================
  Registered: $TaskName

  Next:
    1. In ColdAisle → Settings → SNMP schedule, ensure "Enable" is on
       and set the poll interval (app-level, not this tick).
    2. On each PDU, turn "Scheduled poll" on (needs site OID template + IP).
    3. After a minute or two, Settings should show status Active
       (last worker run updates automatically).

  Remove later:
    .\Register-ColdAisle-SnmpPollTask.ps1 -Unregister
================================================================
"@ -ForegroundColor Green
