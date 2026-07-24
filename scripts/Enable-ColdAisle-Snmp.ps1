#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Enable the PHP SNMP extension for ColdAisle (Windows IIS + PHP FastCGI).

.DESCRIPTION
    Transparent helper used when the ColdAisle SNMP page shows "Enable SNMP".

    This script:
      1. Locates php.ini (default C:\PHP\php.ini, or -PhpInstallPath)
      2. Confirms ext\php_snmp.dll exists
      3. Enables extension=snmp (uncomments or appends)
      4. Optionally sets snmp.mib_directory to a local folder to reduce Net-SNMP path noise
      5. Recycles the IIS application pool (or restarts W3SVC) so php-cgi reloads
      6. Verifies with: php -m | findstr snmp

    Review this file before running. It only changes PHP SNMP-related settings and
    recycles IIS/PHP - it does not open firewall ports or install Net-SNMP.

.PARAMETER PhpInstallPath
    PHP root folder (default: C:\PHP).

.PARAMETER SiteName
    IIS site whose application pool should be recycled (default: Default Web Site).

.PARAMETER SkipIisRecycle
    Do not recycle the app pool / W3SVC (you must recycle manually).

.EXAMPLE
    # Elevated PowerShell
    .\Enable-ColdAisle-Snmp.ps1

.EXAMPLE
    .\Enable-ColdAisle-Snmp.ps1 -PhpInstallPath C:\PHP
#>
[CmdletBinding()]
param(
    [string]$PhpInstallPath = 'C:\PHP',
    [string]$SiteName = 'Default Web Site',
    [switch]$SkipIisRecycle
)

$ErrorActionPreference = 'Stop'

function Write-Step([string]$m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Write-Ok([string]$m) { Write-Host "    [OK] $m" -ForegroundColor Green }
function Write-Warn([string]$m) { Write-Host "    [WARN] $m" -ForegroundColor Yellow }

# PHP Net-SNMP often writes "Created directory: c:/usr" to stderr; with
# $ErrorActionPreference=Stop that becomes a terminating error. Capture output safely.
function Invoke-PhpQuiet {
    param(
        [Parameter(Mandatory)][string]$PhpExe,
        [Parameter(Mandatory)][string[]]$Arguments
    )
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $PhpExe @Arguments 2>&1
        # Flatten ErrorRecords from native stderr into plain strings
        $lines = foreach ($line in @($out)) {
            if ($line -is [System.Management.Automation.ErrorRecord]) {
                [string]$line.Exception.Message
            } else {
                [string]$line
            }
        }
        return ($lines -join "`n")
    } finally {
        $ErrorActionPreference = $prevEap
    }
}

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = [Security.Principal.WindowsPrincipal]::new($id)
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this script in an elevated PowerShell (Run as administrator).'
    }
}

Assert-Admin
Write-Host 'ColdAisle - enable PHP SNMP extension' -ForegroundColor White

$phpIni = Join-Path $PhpInstallPath 'php.ini'
$phpExe = Join-Path $PhpInstallPath 'php.exe'
$snmpDll = Join-Path $PhpInstallPath 'ext\php_snmp.dll'

Write-Step 'Locating PHP'
if (-not (Test-Path $phpExe)) {
    throw "php.exe not found at $phpExe. Pass -PhpInstallPath to your PHP folder."
}
if (-not (Test-Path $phpIni)) {
    throw "php.ini not found at $phpIni"
}
Write-Ok "php.ini: $phpIni"

if (-not (Test-Path $snmpDll)) {
    throw @"
php_snmp.dll not found at:
  $snmpDll
Install a full Windows PHP NTS package that includes ext\php_snmp.dll
(or copy the DLL from the same PHP version build).
"@
}
Write-Ok "DLL present: $snmpDll"

Write-Step 'Updating php.ini'
$raw = Get-Content -Path $phpIni -Raw -ErrorAction Stop

# Enable extension=snmp (handle commented and quoted forms)
$enabled = $false
if ($raw -match '(?m)^\s*extension\s*=\s*"?snmp"?\s*$') {
    Write-Ok 'extension=snmp already enabled'
    $enabled = $true
} elseif ($raw -match '(?m)^\s*;\s*extension\s*=\s*"?snmp"?\s*$') {
    $raw = [regex]::Replace($raw, '(?m)^\s*;\s*extension\s*=\s*"?snmp"?\s*$', 'extension=snmp', 1)
    Write-Ok 'Uncommented extension=snmp'
    $enabled = $true
} else {
    $raw = $raw.TrimEnd() + "`r`n`r`n; ColdAisle - PHP SNMP`r`nextension=snmp`r`n"
    Write-Ok 'Appended extension=snmp'
    $enabled = $true
}

# MIB directory: reduce Unix-path noise (Net-SNMP looks for c:/usr/share/snmp/mibs)
# Create both our preferred folder and the path Net-SNMP often hard-codes on Windows builds.
$mibDir = Join-Path $PhpInstallPath 'snmp\mibs'
$legacyMibRoots = @(
    'C:\usr\share\snmp\mibs',
    'C:\usr\share\snmp'
)
foreach ($d in (@($mibDir) + $legacyMibRoots)) {
    if (-not (Test-Path $d)) {
        New-Item -ItemType Directory -Path $d -Force | Out-Null
        Write-Ok "Created folder: $d (empty is OK — silences Net-SNMP path noise)"
    }
}
# Forward-slash form is what many php_snmp builds expect
$mibDirIni = ($mibDir -replace '\\', '/')
if ($raw -match '(?m)^\s*;?\s*snmp\.mib_directory\s*=') {
    $raw = [regex]::Replace(
        $raw,
        '(?m)^\s*;?\s*snmp\.mib_directory\s*=.*$',
        "snmp.mib_directory = `"$mibDirIni`"",
        1
    )
    Write-Ok "Set snmp.mib_directory = $mibDirIni"
} else {
    $raw = $raw.TrimEnd() + "`r`n`r`n; ColdAisle - SNMP MIBs (empty dir is fine)`r`nsnmp.mib_directory = `"$mibDirIni`"`r`n"
    Write-Ok "Appended snmp.mib_directory"
}

# Backup then write
$bak = "$phpIni.coldaisle-snmp-bak-$(Get-Date -Format 'yyyyMMddHHmmss')"
Copy-Item $phpIni $bak -Force
Write-Ok "Backup: $bak"
Set-Content -Path $phpIni -Value $raw -Encoding ASCII
Write-Ok 'php.ini saved'

if (-not $SkipIisRecycle) {
    Write-Step 'Recycling IIS / PHP (so FastCGI reloads php.ini)'
    try {
        Import-Module WebAdministration -ErrorAction Stop
        $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
        if ($site) {
            $pool = $site.applicationPool
            if ($pool) {
                Restart-WebAppPool -Name $pool
                Write-Ok "Recycled application pool: $pool"
            } else {
                Write-Warn 'No application pool on site; restarting W3SVC'
                Restart-Service W3SVC -Force -ErrorAction Stop
                Write-Ok 'W3SVC restarted'
            }
        } else {
            Write-Warn "Site '$SiteName' not found; restarting W3SVC"
            Restart-Service W3SVC -Force -ErrorAction Stop
            Write-Ok 'W3SVC restarted'
        }
    } catch {
        Write-Warn "IIS recycle failed: $($_.Exception.Message)"
        Write-Warn 'Manually recycle the app pool or run: iisreset'
    }
} else {
    Write-Warn 'Skipped IIS recycle (-SkipIisRecycle). Recycle the app pool before testing in the browser.'
}

Write-Step 'Verifying CLI PHP modules'
# Do not use 2>&1 with $ErrorActionPreference=Stop — PHP writes harmless Net-SNMP
# messages like "Created directory: c:/usr" to stderr and PowerShell would abort.
$modOut = Invoke-PhpQuiet -PhpExe $phpExe -Arguments @('-m')
$iniOut = Invoke-PhpQuiet -PhpExe $phpExe -Arguments @('--ini')
if ($modOut -match '(?im)^\s*snmp\s*$') {
    Write-Ok 'php -m lists snmp — extension is loaded for CLI'
} else {
    Write-Warn 'snmp not listed in php -m yet. Check that CLI and IIS use the same php.ini:'
    Write-Host $iniOut
    Write-Host ($modOut | Select-String -Pattern 'snmp|Warning|Error|Created directory' | Out-String)
}

# Quick functional probe (does not need a remote agent)
$probe = Invoke-PhpQuiet -PhpExe $phpExe -Arguments @(
    '-r', 'echo extension_loaded("snmp") ? "snmp_loaded=1" : "snmp_loaded=0";'
)
if ($probe -match 'snmp_loaded=1') {
    Write-Ok 'extension_loaded("snmp") is true'
} else {
    Write-Warn 'extension_loaded("snmp") is false for this php.exe — verify php.ini path.'
}

Write-Host @"

================================================================
  Done. Next in ColdAisle:
    1. Hard-refresh the SNMP page (Ctrl+F5) — the warning should clear.
       If it remains: recycle the app pool again or run iisreset.
    2. Configure SNMPv3 profiles / Discover OIDs / Poll now.
    3. For scheduled polling, Task Scheduler:
         Program:  $phpExe
         Args:     <site>\scripts\poll_snmp.php

  Note: "Created directory: c:/usr" messages from php.exe are normal
  Net-SNMP noise on Windows and can be ignored.
================================================================

"@
Write-Host 'Finished.' -ForegroundColor Green
