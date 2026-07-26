#Requires -RunAsAdministrator
# Register (or update) the Windows Scheduled Task for ColdAisle SNMP polling.
# Download from Settings -> SNMP schedule and run elevated on the app server.
#
# Prefer a 1-minute OS tick. Enable/disable and poll interval live in ColdAisle Settings.
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
    [ValidateRange(5, 120)]
    [int]$HealthTimeoutSec = 20,
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

function Stop-ProcessTree([int]$ProcessId) {
    try {
        Get-CimInstance Win32_Process -Filter "ParentProcessId=$ProcessId" -ErrorAction SilentlyContinue |
            ForEach-Object { Stop-ProcessTree -ProcessId $_.ProcessId }
    } catch { }
    try { Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue } catch { }
}

function Stop-HungPhpNear([string]$PhpExePath) {
    Get-Process php -ErrorAction SilentlyContinue | ForEach-Object {
        $pathOk = $false
        try { $pathOk = ($_.Path -and ($_.Path -ieq $PhpExePath)) } catch { $pathOk = $true }
        if ($pathOk) {
            Write-Host "    Stopping hung php.exe PID $($_.Id)..." -ForegroundColor Yellow
            try { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue } catch { }
        }
    }
}

Assert-Admin

if ($Unregister) {
    try {
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    } catch { }
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
$runCmd = Join-Path $SiteRoot 'scripts\run_poll_snmp.cmd'
if (-not (Test-Path -LiteralPath $pollScript)) {
    $altRoot = [System.IO.Path]::GetFullPath((Join-Path $SiteRoot '..'))
    $altScript = Join-Path $altRoot 'scripts\poll_snmp.php'
    $altCmd = Join-Path $altRoot 'scripts\run_poll_snmp.cmd'
    if (Test-Path -LiteralPath $altScript) {
        $SiteRoot = $altRoot
        $pollScript = $altScript
        $runCmd = $altCmd
    }
}
if (-not (Test-Path -LiteralPath $pollScript)) {
    throw "poll_snmp.php not found under: $SiteRoot\scripts\"
}
if (-not (Test-Path -LiteralPath $runCmd)) {
    throw "run_poll_snmp.cmd not found under: $SiteRoot\scripts\ (deploy ColdAisle 0.2.55+)"
}
# Local empty MIB dir so Net-SNMP does not scan c:/usr/share/snmp/mibs
$mibDir = Join-Path $SiteRoot 'storage\snmp\mibs'
if (-not (Test-Path -LiteralPath $mibDir)) {
    New-Item -ItemType Directory -Path $mibDir -Force | Out-Null
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
Write-Host "Launcher:  $runCmd" -ForegroundColor Cyan
Write-Host "Task:      $TaskName" -ForegroundColor Cyan
Write-Host "Tick:      every $TickMinutes minute(s)" -ForegroundColor Cyan
Write-Host "App interval hint (Settings): __COLDAISLE_INTERVAL_HINT__ seconds" -ForegroundColor DarkGray

# Clear stuck state that causes 0x800710E0 (request refused)
Write-Host ""
Write-Host "==> Clearing stuck task / hung PHP (if any)..." -ForegroundColor Cyan
try { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue } catch { }
Stop-HungPhpNear -PhpExePath $PhpExe
# Drop lock file from a killed worker
$lockPath = Join-Path $SiteRoot 'storage\tmp\snmp_poll.lock'
if (Test-Path -LiteralPath $lockPath) {
    Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
    Write-Host "    Removed leftover snmp_poll.lock" -ForegroundColor DarkGray
}

if (-not $SkipHealthCheck) {
    Write-Host ""
    Write-Host "==> Quick PHP check..." -ForegroundColor Cyan
    $outFile = [System.IO.Path]::GetTempFileName()
    $errFile = [System.IO.Path]::GetTempFileName()
    try {
        $p1 = Start-Process -FilePath $PhpExe `
            -ArgumentList @('-r', 'echo "php-ok";') `
            -NoNewWindow -PassThru `
            -RedirectStandardOutput $outFile `
            -RedirectStandardError $errFile
        if (-not $p1.WaitForExit(10000)) {
            Stop-ProcessTree -ProcessId $p1.Id
            Write-Warning "php.exe -r timed out; continuing to register task."
        } else {
            $line = (Get-Content -LiteralPath $outFile -Raw -ErrorAction SilentlyContinue)
            if ($line -match 'php-ok') {
                Write-Host "    [OK] php.exe runs." -ForegroundColor Green
            } else {
                Write-Warning "php.exe returned unexpected output; continuing."
            }
        }

        Write-Host "==> ColdAisle health (poll_snmp.php --health, timeout ${HealthTimeoutSec}s)..." -ForegroundColor Cyan
        Clear-Content -LiteralPath $outFile -ErrorAction SilentlyContinue
        Clear-Content -LiteralPath $errFile -ErrorAction SilentlyContinue
        # Use .cmd launcher so MIBS=/snmp.mib_directory are set before PHP loads snmp.dll
        $p2 = Start-Process -FilePath $runCmd `
            -ArgumentList @('--health') `
            -WorkingDirectory $SiteRoot `
            -NoNewWindow -PassThru `
            -RedirectStandardOutput $outFile `
            -RedirectStandardError $errFile
        $finished = $p2.WaitForExit($HealthTimeoutSec * 1000)
        if (-not $finished) {
            Write-Warning "Health check timed out after ${HealthTimeoutSec}s. Killing PHP and continuing."
            Stop-ProcessTree -ProcessId $p2.Id
        } else {
            foreach ($line in @(Get-Content -LiteralPath $outFile -ErrorAction SilentlyContinue)) {
                if ($line) { Write-Host "    $line" }
            }
            foreach ($line in @(Get-Content -LiteralPath $errFile -ErrorAction SilentlyContinue)) {
                if ($line) { Write-Host "    [err] $line" -ForegroundColor Yellow }
            }
            if ($p2.ExitCode -eq 0) {
                Write-Host "    [OK] ColdAisle health passed." -ForegroundColor Green
            } else {
                Write-Warning "Health exit code $($p2.ExitCode). Task will still be registered."
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
Write-Host "==> Ensuring SYSTEM can write storage logs..." -ForegroundColor Cyan
$storagePaths = @(
    (Join-Path $SiteRoot 'storage'),
    (Join-Path $SiteRoot 'storage\logs'),
    (Join-Path $SiteRoot 'storage\tmp')
)
foreach ($sp in $storagePaths) {
    if (-not (Test-Path -LiteralPath $sp)) {
        New-Item -ItemType Directory -Path $sp -Force | Out-Null
    }
    # Grant SYSTEM modify (task runs as SYSTEM)
    & icacls.exe $sp /grant '*S-1-5-18:(OI)(CI)M' /T /C /Q 2>$null | Out-Null
}
Write-Host "    Granted SYSTEM modify on storage\, storage\logs\, storage\tmp\" -ForegroundColor DarkGray

Write-Host ""
Write-Host "==> Registering scheduled task..." -ForegroundColor Cyan

# Remove existing definition so we do not keep a half-broken task
try {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
} catch { }
try {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
} catch { }

# Point the task at run_poll_snmp.cmd (sets MIBS empty before php.exe starts).
# Calling php.exe directly loads snmp.dll and scans missing MIB paths -> hang/spam.
$tr = '"{0}"' -f $runCmd
Write-Host "    Action: $tr" -ForegroundColor DarkGray
$createArgs = @(
    '/Create',
    '/TN', $TaskName,
    '/TR', $tr,
    '/SC', 'MINUTE',
    '/MO', "$TickMinutes",
    '/RU', 'SYSTEM',
    '/RL', 'HIGHEST',
    '/F'
)

$createOut = & schtasks.exe @createArgs 2>&1
$createOut | ForEach-Object { Write-Host "    $_" }
if ($LASTEXITCODE -ne 0) {
    Write-Warning "schtasks /Create failed (exit $LASTEXITCODE). Trying PowerShell Register-ScheduledTask..."

    $action = New-ScheduledTaskAction -Execute $runCmd -WorkingDirectory $SiteRoot
    # Valid duration (not TimeSpan.MaxValue — that yields 0x80041318)
    $trigger = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) `
        -RepetitionInterval (New-TimeSpan -Minutes $TickMinutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    # Avoid power/idle/network conditions that produce 0x800710E0 on servers/VMs
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
        -MultipleInstances IgnoreNew `
        -Compatibility Win8
    # Explicitly clear restrictive conditions when the API allows
    try { $settings.DisallowStartIfOnBatteries = $false } catch { }
    try { $settings.StopIfGoingOnBatteries = $false } catch { }
    try { $settings.RunOnlyIfIdle = $false } catch { }
    try { $settings.IdleSettings.StopOnIdleEnd = $false } catch { }
    try { $settings.RunOnlyIfNetworkAvailable = $false } catch { }
    try { $settings.DisallowDemandStart = $false } catch { }
    try { $settings.Enabled = $true } catch { }

    Register-ScheduledTask -TaskName $TaskName `
        -Action $action `
        -Trigger $trigger `
        -Principal $principal `
        -Settings $settings `
        -Description 'ColdAisle SNMP poll worker (scripts/poll_snmp.php). Policy in ColdAisle Settings.' `
        -Force | Out-Null
}

# Ensure enabled (disabled tasks often return 0x800710E0)
try { Enable-ScheduledTask -TaskName $TaskName -ErrorAction Stop | Out-Null } catch {
    & schtasks.exe /Change /TN $TaskName /ENABLE | Out-Null
}

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    throw "Task '$TaskName' was not created."
}

Write-Host "    Task State: $($task.State)" -ForegroundColor Cyan

# Demand-start once so Last Run Result is not left stale
Write-Host "==> Starting task once (demand start)..." -ForegroundColor Cyan
try {
    Start-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    Start-Sleep -Seconds 3
    $info = Get-ScheduledTaskInfo -TaskName $TaskName
    $code = $info.LastTaskResult
    $hex = ('0x{0:X8}' -f [uint32]$code)
    Write-Host "    LastTaskResult: $code ($hex)" -ForegroundColor $(if ($code -eq 0 -or $code -eq 267009) { 'Green' } else { 'Yellow' })
    if ($code -eq 0x800710E0 -or $code -eq -2147020576) {
        Write-Warning @"
Last result is still 0x800710E0 (request refused). Common fixes:
  1. Task Scheduler -> task Properties -> Conditions: uncheck ALL power/idle/network boxes
  2. General tab: Run whether user is logged on or not; Run with highest privileges; user SYSTEM
  3. End any stuck instance; kill hung php.exe; delete storage\tmp\snmp_poll.lock
  4. Re-run this script with -SkipHealthCheck
"@
    }
} catch {
    Write-Warning "Start-ScheduledTask: $($_.Exception.Message)"
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
Write-Host "  4. If still refused: open task Properties > Conditions and clear power/idle checks"
Write-Host ""
Write-Host "Logs (check these if Settings still says Waiting):"
Write-Host "  $SiteRoot\storage\logs\snmp_poll_cli.log"
Write-Host "  $SiteRoot\storage\logs\snmp_scheduler_heartbeat.txt"
Write-Host "  $env:TEMP\coldaisle_snmp_poll.log"
Write-Host "  $env:TEMP\coldaisle_snmp_heartbeat.txt"
Write-Host "  C:\Windows\Temp\coldaisle_snmp_poll.log   (SYSTEM often uses this TEMP)"
Write-Host ""
Write-Host "Manual run (use the .cmd launcher - avoids MIB spam/hang):"
Write-Host "  cmd /c `"$runCmd`" --health"
Write-Host "  cmd /c `"$runCmd`""
Write-Host ""
Write-Host "Remove later:"
Write-Host "  .\Register-ColdAisle-SnmpPollTask.ps1 -Unregister"
Write-Host ""
