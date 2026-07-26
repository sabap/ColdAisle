#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Register (or update) the Windows Scheduled Task that runs ColdAisle SNMP polling.

.DESCRIPTION
    ColdAisle does NOT change Task Scheduler from the web UI (security).
    Download this script from Settings → SNMP schedule and run elevated on the app server.

    Creates a short fixed tick (default every 1 minute). Poll intervals and enable/disable
    are controlled in ColdAisle Settings; the worker skips work that is not due.

    The optional smoke test uses "php poll_snmp.php --health" (fast DB/boot check only).
    Full SNMP polls are NOT run during registration so unreachable PDUs cannot hang setup.

.PARAMETER SiteRoot
    ColdAisle web root (folder that contains scripts\poll_snmp.php).

.PARAMETER PhpExe
    Full path to php.exe (CLI).

.PARAMETER TaskName
    Scheduled task name.

.PARAMETER TickMinutes
    How often Windows invokes the worker (1–15). Keep short (1 recommended).

.PARAMETER HealthTimeoutSec
    Max seconds to wait for the --health smoke test (default 30).

.PARAMETER SkipHealthCheck
    Skip the PHP smoke test and only register the task.

.PARAMETER Unregister
    Remove the task instead of creating it.

.EXAMPLE
    .\Register-ColdAisle-SnmpPollTask.ps1

.EXAMPLE
    .\Register-ColdAisle-SnmpPollTask.ps1 -SiteRoot 'C:\inetpub\wwwroot\ColdAisle' -PhpExe 'C:\PHP\php.exe'
#>
[CmdletBinding()]
param(
    [string]$SiteRoot = '__COLDAISLE_SITE_ROOT__',
    [string]$PhpExe = '__COLDAISLE_PHP_EXE__',
    [string]$TaskName = 'ColdAisle SNMP Poll',
    [ValidateRange(1, 15)]
    [int]$TickMinutes = 1,
    [ValidateRange(5, 300)]
    [int]$HealthTimeoutSec = 30,
    [switch]$SkipHealthCheck,
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

function Resolve-SiteRoot([string]$path) {
    if ([string]::IsNullOrWhiteSpace($path)) { return $null }
    try {
        $resolved = (Resolve-Path -LiteralPath $path -ErrorAction Stop).Path
        # Collapse accidental ...\src\.. from App::ROOT
        return [System.IO.Path]::GetFullPath($resolved)
    } catch {
        return $path
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

$SiteRoot = Resolve-SiteRoot $SiteRoot
if (-not (Test-Path -LiteralPath $SiteRoot)) {
    throw "SiteRoot not found: $SiteRoot"
}

$pollScript = Join-Path $SiteRoot 'scripts\poll_snmp.php'
if (-not (Test-Path -LiteralPath $pollScript)) {
    # tolerate SiteRoot pointing at src\
    $alt = Join-Path $SiteRoot '..\scripts\poll_snmp.php'
    if (Test-Path -LiteralPath $alt) {
        $SiteRoot = [System.IO.Path]::GetFullPath((Join-Path $SiteRoot '..'))
        $pollScript = Join-Path $SiteRoot 'scripts\poll_snmp.php'
    }
}
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
Write-Host "SiteRoot:  $SiteRoot" -ForegroundColor Cyan
Write-Host "Script:    $pollScript" -ForegroundColor Cyan
Write-Host "Task:      $TaskName" -ForegroundColor Cyan
Write-Host "Tick:      every $TickMinutes minute(s)" -ForegroundColor Cyan
Write-Host "App interval hint (Settings): __COLDAISLE_INTERVAL_HINT__ seconds" -ForegroundColor DarkGray

# --- Fast smoke test (never full SNMP walk) ---
if (-not $SkipHealthCheck) {
    Write-Host "`n==> Health check (php poll_snmp.php --health, timeout ${HealthTimeoutSec}s)..." -ForegroundColor Cyan
    $outFile = [System.IO.Path]::GetTempFileName()
    $errFile = [System.IO.Path]::GetTempFileName()
    try {
        $p = Start-Process -FilePath $PhpExe `
            -ArgumentList @('--', $pollScript, '--health') `
            -WorkingDirectory $SiteRoot `
            -NoNewWindow -PassThru `
            -RedirectStandardOutput $outFile `
            -RedirectStandardError $errFile
        $finished = $p.WaitForExit($HealthTimeoutSec * 1000)
        if (-not $finished) {
            Write-Warning "Health check timed out after ${HealthTimeoutSec}s — killing hung php.exe and continuing to register the task."
            try { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue } catch {}
            # orphan children
            Get-Process php -ErrorAction SilentlyContinue | Where-Object {
                try { $_.Path -eq $PhpExe } catch { $false }
            } | ForEach-Object {
                try { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue } catch {}
            }
        } else {
            $stdout = (Get-Content -LiteralPath $outFile -Raw -ErrorAction SilentlyContinue)
            $stderr = (Get-Content -LiteralPath $errFile -Raw -ErrorAction SilentlyContinue)
            if ($stdout) { $stdout -split "`r?`n" | ForEach-Object { if ($_) { Write-Host "    $_" } } }
            if ($stderr) { $stderr -split "`r?`n" | ForEach-Object { if ($_) { Write-Host "    [err] $_" -ForegroundColor Yellow } } }
            $code = $p.ExitCode
            if ($code -eq 0) {
                Write-Host "    [OK] Health check passed." -ForegroundColor Green
            } else {
                Write-Warning "Health check exit code $code. Task will still be registered; fix config/SQL if needed."
            }
        }
    } finally {
        Remove-Item -LiteralPath $outFile, $errFile -Force -ErrorAction SilentlyContinue
    }
} else {
    Write-Host "`n==> Skipping health check (-SkipHealthCheck)." -ForegroundColor DarkGray
}

# --- Register task (always, even if health check failed/timed out) ---
Write-Host "`n==> Registering scheduled task..." -ForegroundColor Cyan

# Quote path for spaces; -- so Windows args do not confuse php
$arg = '-- "' + $pollScript.Replace('"', '\"') + '"'
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

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    throw "Register-ScheduledTask reported success but task '$TaskName' was not found."
}

Write-Host @"

================================================================
  Registered: $TaskName  (State: $($task.State))

  If the earlier run appeared stuck: that was a FULL poll (old script).
  This version only runs a 30s --health check, then always creates the task.

  Next:
    1. ColdAisle → Settings → SNMP schedule → Enable + Save
    2. Each PDU: Scheduled poll ON (site template + IP)
    3. Wait 1–2 minutes → Settings should show Active

  Manual test (full poll — may take a while if devices are slow):
    & '$PhpExe' -- '$pollScript'

  Remove later:
    .\Register-ColdAisle-SnmpPollTask.ps1 -Unregister
================================================================
"@ -ForegroundColor Green
