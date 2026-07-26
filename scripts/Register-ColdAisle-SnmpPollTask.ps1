#Requires -RunAsAdministrator
# Register (or update) the Windows Scheduled Task for ColdAisle SNMP polling.
# Download from Settings -> SNMP schedule and run elevated on the app server.
#
# Prefer a 1-minute OS tick. Enable/disable and poll interval live in ColdAisle Settings.
# Smoke test uses: php poll_snmp.php --health  (no full SNMP poll)
#
# Examples:
#   .\Register-ColdAisle-SnmpPollTask.ps1
#   .\Register-ColdAisle-SnmpPollTask.ps1 -SiteRoot 'C:\inetpub\wwwroot\ColdAisle' -PhpExe 'C:\PHP\php.exe'
#   .\Register-ColdAisle-SnmpPollTask.ps1 -SkipHealthCheck
#   .\Register-ColdAisle-SnmpPollTask.ps1 -Unregister

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
    $altRoot = [System.IO.Path]::GetFullPath((Join-Path $SiteRoot '..'))
    $altScript = Join-Path $altRoot 'scripts\poll_snmp.php'
    if (Test-Path -LiteralPath $altScript) {
        $SiteRoot = $altRoot
        $pollScript = $altScript
    }
}
if (-not (Test-Path -LiteralPath $pollScript)) {
    throw "poll_snmp.php not found under: $SiteRoot\scripts\"
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

if (-not $SkipHealthCheck) {
    Write-Host ""
    Write-Host "==> Health check (php poll_snmp.php --health, timeout ${HealthTimeoutSec}s)..." -ForegroundColor Cyan
    $outFile = [System.IO.Path]::GetTempFileName()
    $errFile = [System.IO.Path]::GetTempFileName()
    try {
        $proc = Start-Process -FilePath $PhpExe `
            -ArgumentList @('--', $pollScript, '--health') `
            -WorkingDirectory $SiteRoot `
            -NoNewWindow -PassThru `
            -RedirectStandardOutput $outFile `
            -RedirectStandardError $errFile
        $finished = $proc.WaitForExit($HealthTimeoutSec * 1000)
        if (-not $finished) {
            Write-Warning "Health check timed out after ${HealthTimeoutSec}s - killing hung php.exe and continuing."
            try { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue } catch { }
        } else {
            foreach ($line in @(Get-Content -LiteralPath $outFile -ErrorAction SilentlyContinue)) {
                if ($line) { Write-Host "    $line" }
            }
            foreach ($line in @(Get-Content -LiteralPath $errFile -ErrorAction SilentlyContinue)) {
                if ($line) { Write-Host "    [err] $line" -ForegroundColor Yellow }
            }
            $code = $proc.ExitCode
            if ($code -eq 0) {
                Write-Host "    [OK] Health check passed." -ForegroundColor Green
            } else {
                Write-Warning "Health check exit code $code. Task will still be registered."
            }
        }
    } finally {
        Remove-Item -LiteralPath $outFile, $errFile -Force -ErrorAction SilentlyContinue
    }
} else {
    Write-Host ""
    Write-Host "==> Skipping health check (-SkipHealthCheck)." -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "==> Registering scheduled task..." -ForegroundColor Cyan

$arg = '-- "' + ($pollScript -replace '"', '\"') + '"'
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
    throw "Register-ScheduledTask finished but task '$TaskName' was not found."
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  Registered: $TaskName  (State: $($task.State))" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Next:" -ForegroundColor Cyan
Write-Host "  1. ColdAisle > Settings > SNMP schedule > Enable + Save"
Write-Host "  2. Each PDU: Scheduled poll ON (site template + IP)"
Write-Host "  3. Wait 1-2 minutes; Settings should show Active"
Write-Host ""
Write-Host "Manual full poll (can take a while if devices are slow):"
Write-Host "  & '$PhpExe' -- '$pollScript'"
Write-Host ""
Write-Host "Remove later:"
Write-Host "  .\Register-ColdAisle-SnmpPollTask.ps1 -Unregister"
Write-Host ""
