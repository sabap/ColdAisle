#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Install ColdAisle on Windows (IIS + PHP + ODBC) and deploy the latest release from GitHub.

.DESCRIPTION
    Transparent, inspectable installer for the public ColdAisle project:
      https://github.com/sabap/ColdAisle

    What this script does (in order):
      1. Confirms it is running elevated (Administrator)
      2. Resolves the latest version tag from GitHub (or uses -Version)
      3. Downloads that release as a ZIP from GitHub (public; no token)
      4. Extracts the ZIP to a temp folder
      5. Runs scripts\Install-ColdAisle-Prereqs.ps1 from the extracted tree to:
           - Install IIS role features needed for PHP FastCGI
           - Install Visual C++ Redistributable (if needed)
           - Download/configure PHP NTS x64 + php.ini
           - Register PHP with IIS
           - Install ODBC Driver 18 for SQL Server (for pdo_odbc)
           - Optionally URL Rewrite + pdo_sqlsrv
           - Copy application files into the IIS site path
           - Grant the app pool Modify on config\ and storage\
      6. Prints next steps (browse setup.php)

    What this script does NOT do:
      - Install SQL Server (install Express/Standard separately, or use an existing instance)
      - Create the database or admin user (use the web wizard: setup.php)
      - Install TLS certificates

    Recommended usage (download, review, then run - do not pipe to iex if you want to audit first):

      Invoke-WebRequest -Uri "https://raw.githubusercontent.com/sabap/ColdAisle/main/Install-ColdAisle.ps1" `
        -OutFile .\Install-ColdAisle.ps1
      notepad .\Install-ColdAisle.ps1   # optional: review
      Set-ExecutionPolicy Bypass -Scope Process -Force
      .\Install-ColdAisle.ps1

.PARAMETER Version
    Release tag without or with leading v (e.g. 0.2.2 or v0.2.2), or branch name main.
    Default: latest GitHub Release, else highest version tag; falls back to main if tag is too old.

.PARAMETER SitePhysicalPath
    IIS site physical path for ColdAisle. Default: C:\inetpub\wwwroot\ColdAisle

.PARAMETER SiteName
    IIS site name. Default: Default Web Site

.PARAMETER PhpVersion
    PHP NTS build version for windows.php.net. Default: 8.3.32

.PARAMETER PhpInstallPath
    Where to install PHP. Default: C:\PHP

.PARAMETER GitHubOwner
    GitHub org/user. Default: sabap

.PARAMETER GitHubRepo
    Repository name. Default: ColdAisle

.PARAMETER SkipOdbc
    Skip ODBC Driver 18 install.

.PARAMETER SkipUrlRewrite
    Skip IIS URL Rewrite install.

.PARAMETER SkipSqlsrv
    Skip Microsoft PHP SQL drivers (pdo_odbc is enough).

.PARAMETER Force
    Re-download PHP/components and re-copy app files (preserves config\config.php).

.PARAMETER KeepDownload
    Do not delete the temp download/extract folder after install.

.PARAMETER OpenSetup
    After a successful install, open the setup wizard in the default browser
    (http://localhost/setup.php or the site binding if detectable).

.EXAMPLE
    .\Install-ColdAisle.ps1

.EXAMPLE
    .\Install-ColdAisle.ps1 -Version 0.2.0 -SitePhysicalPath 'C:\inetpub\wwwroot\ColdAisle'

.EXAMPLE
    .\Install-ColdAisle.ps1 -Force -OpenSetup
#>
[CmdletBinding()]
param(
    [string]$Version = '',
    [string]$SitePhysicalPath = 'C:\inetpub\wwwroot\ColdAisle',
    [string]$SiteName = 'Default Web Site',
    [string]$PhpVersion = '8.3.32',
    [string]$PhpInstallPath = 'C:\PHP',
    [string]$GitHubOwner = 'sabap',
    [string]$GitHubRepo = 'ColdAisle',
    [switch]$SkipOdbc,
    [switch]$SkipUrlRewrite,
    [switch]$SkipSqlsrv,
    [switch]$Force,
    [switch]$KeepDownload,
    [switch]$OpenSetup
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$Message) {
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}
function Write-Ok([string]$Message) {
    Write-Host "    [OK] $Message" -ForegroundColor Green
}
function Write-Warn([string]$Message) {
    Write-Host "    [WARN] $Message" -ForegroundColor Yellow
}

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = New-Object Security.Principal.WindowsPrincipal($id)
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'This installer must run as Administrator (elevated PowerShell). Right-click PowerShell -> Run as administrator.'
    }
}

function Ensure-Tls12 {
    try {
        [Net.ServicePointManager]::SecurityProtocol = `
            [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    } catch { }
}

function Invoke-PreflightChecks {
    Write-Step 'Preflight checks'

    $psVer = $PSVersionTable.PSVersion
    Write-Ok "PowerShell $psVer"
    if ($psVer.Major -lt 5) {
        throw 'PowerShell 5.1 or later is required (Windows PowerShell or PowerShell 7+).'
    }

    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
    if ($os) {
        Write-Ok ("OS: {0} (build {1})" -f $os.Caption, $os.BuildNumber)
        # Client vs Server: both OK; warn on very old builds
        if ($os.BuildNumber -and [int]$os.BuildNumber -lt 10240) {
            Write-Warn 'OS build looks older than Windows 10 / Server 2016 - not a tested platform.'
        }
    }

    $arch = $env:PROCESSOR_ARCHITECTURE
    if ($arch -ne 'AMD64') {
        Write-Warn "Architecture is $arch - installer targets 64-bit (AMD64). 32-bit is not supported."
    } else {
        Write-Ok 'Architecture: AMD64 (x64)'
    }

    # Disk space on system and site drives (need room for PHP zip + app + IIS)
    $minGb = 2
    $driveLetters = New-Object 'System.Collections.Generic.HashSet[string]'
    foreach ($path in @("$($env:SystemDrive)\", $SitePhysicalPath, $PhpInstallPath, $env:TEMP)) {
        try {
            $root = [System.IO.Path]::GetPathRoot($path)
            if ($root -and $root.Length -ge 1) {
                [void]$driveLetters.Add($root.Substring(0, 1).ToUpperInvariant())
            }
        } catch { }
    }
    foreach ($letter in $driveLetters) {
        try {
            $drive = Get-PSDrive -Name $letter -ErrorAction SilentlyContinue
            if ($drive -and $null -ne $drive.Free) {
                $freeGb = [math]::Round([double]$drive.Free / 1GB, 2)
                if ($freeGb -lt $minGb) {
                    Write-Warn "Low free space on ${letter}: ${freeGb} GB (recommend >= $minGb GB)"
                } else {
                    Write-Ok "Free space on ${letter}: ${freeGb} GB"
                }
            }
        } catch { }
    }

    # Path length gotcha
    if ($SitePhysicalPath.Length -gt 180) {
        Write-Warn "Site path is very long ($($SitePhysicalPath.Length) chars). Windows MAX_PATH can break PHP/IIS deploys - prefer a shorter path."
    }

    # Network: GitHub
    Ensure-Tls12
    try {
        $r = Invoke-WebRequest -Uri 'https://github.com' -Method Head -UseBasicParsing -TimeoutSec 20
        Write-Ok "GitHub HTTPS reachable (HTTP $($r.StatusCode))"
    } catch {
        try {
            $r2 = Invoke-WebRequest -Uri 'https://api.github.com' -UseBasicParsing -TimeoutSec 20
            Write-Ok 'GitHub API HTTPS reachable'
        } catch {
            throw "Cannot reach GitHub over HTTPS. Outbound 443 is required to download ColdAisle and PHP. Error: $($_.Exception.Message)"
        }
    }

    # windows.php.net (PHP download)
    try {
        $null = Invoke-WebRequest -Uri 'https://windows.php.net' -Method Head -UseBasicParsing -TimeoutSec 20
        Write-Ok 'windows.php.net reachable (PHP packages)'
    } catch {
        Write-Warn "windows.php.net not reachable - PHP download may fail: $($_.Exception.Message)"
    }

    # Execution policy note (Bypass -Scope Process is enough)
    $ep = Get-ExecutionPolicy -Scope Process -ErrorAction SilentlyContinue
    Write-Ok "Process execution policy: $ep (use Bypass -Scope Process if scripts are blocked)"

    # Port 80 in use (informational)
    try {
        $listeners = Get-NetTCPConnection -LocalPort 80 -State Listen -ErrorAction SilentlyContinue
        if ($listeners) {
            Write-Ok 'Port 80 is in use (expected if IIS already installed)'
        } else {
            Write-Warn 'Nothing listening on port 80 yet - IIS will own this after install'
        }
    } catch { }

    # Existing install
    if ((Test-Path (Join-Path $SitePhysicalPath 'config\config.php')) -and -not $Force) {
        Write-Warn "Existing config found at $SitePhysicalPath\config\config.php - re-deploy preserves it (use -Force to refresh app files)."
    }
    if ((Test-Path (Join-Path $SitePhysicalPath 'setup.php')) -and -not $Force) {
        Write-Ok "Site path already has setup.php (will refresh only with -Force or empty path)"
    }
}

function Invoke-PostInstallChecks {
    param([string]$SitePath, [string]$PhpPath)

    Write-Step 'Post-install verification'
    $issues = @()

    $phpExe = Join-Path $PhpPath 'php.exe'
    $phpCgi = Join-Path $PhpPath 'php-cgi.exe'
    if (-not (Test-Path $phpExe)) { $issues += "Missing $phpExe" } else { Write-Ok "php.exe present" }
    if (-not (Test-Path $phpCgi)) { $issues += "Missing $phpCgi (IIS FastCGI needs this)" } else { Write-Ok "php-cgi.exe present" }

    if (Test-Path $phpExe) {
        $modOut = & $phpExe -m 2>&1 | Out-String
        foreach ($need in @('curl', 'mbstring', 'openssl', 'PDO')) {
            if ($modOut -match [regex]::Escape($need)) {
                Write-Ok "PHP module: $need"
            } else {
                $issues += "PHP module missing or not loaded: $need"
            }
        }
        if ($modOut -match 'pdo_odbc' -or $modOut -match 'pdo_sqlsrv') {
            Write-Ok 'PHP SQL PDO driver present (pdo_odbc and/or pdo_sqlsrv)'
        } else {
            $issues += 'No pdo_odbc or pdo_sqlsrv - SQL connections will fail until ODBC/sqlsrv is fixed'
        }
        if ($modOut -match 'ldap') { Write-Ok 'PHP module: ldap (LDAPS ready)' }
        else { Write-Warn 'PHP ldap not loaded - enable extension=ldap in php.ini for Active Directory' }
        if ($modOut -match 'snmp') { Write-Ok 'PHP module: snmp (polling ready)' }
        else { Write-Warn 'PHP snmp not loaded - enable for SNMP poll worker (optional at install)' }
    }

    if (-not (Test-Path (Join-Path $SitePath 'setup.php'))) {
        $issues += "setup.php missing under $SitePath"
    } else {
        Write-Ok 'setup.php present'
    }
    if (-not (Test-Path (Join-Path $SitePath 'index.php'))) {
        $issues += "index.php missing under $SitePath"
    } else {
        Write-Ok 'index.php present'
    }

    foreach ($dir in @('config', 'storage\logs', 'storage\uploads', 'storage\backups')) {
        $p = Join-Path $SitePath $dir
        if (-not (Test-Path $p)) {
            $issues += "Missing directory: $p"
        } else {
            Write-Ok "Directory OK: $dir"
        }
    }

    # IIS site path
    try {
        Import-Module WebAdministration -ErrorAction SilentlyContinue
        if (Get-Command Get-Website -ErrorAction SilentlyContinue) {
            $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
            if ($site) {
                $phys = $site.physicalPath
                Write-Ok "IIS site '$SiteName' physicalPath=$phys"
                if ($phys -and $SitePath -and (([IO.Path]::GetFullPath($phys)).TrimEnd('\') -ne ([IO.Path]::GetFullPath($SitePath)).TrimEnd('\'))) {
                    Write-Warn "IIS physical path differs from -SitePhysicalPath. Confirm the site points at ColdAisle."
                }
            } else {
                Write-Warn "IIS site '$SiteName' not found by name - confirm bindings manually"
            }
        }
    } catch {
        Write-Warn "Could not query IIS: $($_.Exception.Message)"
    }

    # HTTP smoke (best-effort)
    $setupUrl = 'http://localhost/setup.php'
    try {
        $resp = Invoke-WebRequest -Uri $setupUrl -UseBasicParsing -TimeoutSec 15 -MaximumRedirection 5
        if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 400) {
            Write-Ok "HTTP smoke OK: $setupUrl (HTTP $($resp.StatusCode))"
        } else {
            Write-Warn "HTTP smoke unexpected status $($resp.StatusCode) for $setupUrl"
        }
    } catch {
        Write-Warn "Could not HTTP-fetch $setupUrl yet: $($_.Exception.Message)"
        Write-Warn 'Start the Default Web Site / W3SVC if needed: Start-Service W3SVC'
    }

    if ($issues.Count -gt 0) {
        Write-Host ''
        Write-Warn 'Verification found issues:'
        foreach ($i in $issues) { Write-Host "      - $i" -ForegroundColor Yellow }
        return $false
    }
    Write-Ok 'Post-install checks passed'
    return $true
}

function Open-SetupWizard {
    param([string]$SitePath)

    $url = 'http://localhost/setup.php'
    # Prefer binding on Default Web Site if we can
    try {
        Import-Module WebAdministration -ErrorAction SilentlyContinue
        $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
        if ($site) {
            $bind = $site.bindings.Collection | Where-Object { $_.protocol -eq 'http' } | Select-Object -First 1
            if ($bind) {
                $info = $bind.bindingInformation  # *:80: or 10.0.0.1:80:host
                $parts = $info -split ':'
                $port = if ($parts.Count -ge 2 -and $parts[1]) { $parts[1] } else { '80' }
                $hostHeader = if ($parts.Count -ge 3) { $parts[2] } else { '' }
                if ($hostHeader) {
                    $url = "http://${hostHeader}/setup.php"
                } elseif ($port -and $port -ne '80') {
                    $url = "http://localhost:${port}/setup.php"
                }
            }
        }
    } catch { }

    Write-Step "Opening setup wizard: $url"
    try {
        Start-Process $url
        Write-Ok 'Browser launched'
    } catch {
        Write-Warn "Could not open browser: $($_.Exception.Message). Navigate manually to $url"
    }
}

function Get-GitHubJson([string]$Url) {
    $headers = @{
        'Accept'     = 'application/vnd.github+json'
        'User-Agent' = 'ColdAisle-Installer'
    }
    return Invoke-RestMethod -Uri $Url -Headers $headers -UseBasicParsing
}

function Resolve-LatestVersion {
    param([string]$Owner, [string]$Repo, [string]$Requested)

    if ($Requested) {
        $r = $Requested.Trim()
        if ($r -match '^(?i)(main|master)$') {
            return $r.ToLower()
        }
        return ($r -replace '^[vV]', '')
    }

    Write-Step "Resolving latest version from GitHub ($Owner/$Repo)"
    # Prefer formal releases; fall back to tags
    try {
        $rel = Get-GitHubJson "https://api.github.com/repos/$Owner/$Repo/releases/latest"
        if ($rel.tag_name) {
            $v = [string]$rel.tag_name
            Write-Ok "Latest release tag: $v"
            return ($v -replace '^[vV]', '')
        }
    } catch {
        Write-Warn "No formal GitHub Release (will use tags): $($_.Exception.Message)"
    }

    $tags = Get-GitHubJson "https://api.github.com/repos/$Owner/$Repo/tags?per_page=30"
    $best = $null
    foreach ($t in $tags) {
        $name = [string]$t.name
        $tv = $name -replace '^[vV]', ''
        if ($tv -notmatch '^\d+\.\d+') { continue }
        if ($null -eq $best) {
            $best = $tv
        } else {
            # Simple version compare via [version] when possible
            try {
                if ([version]$tv -gt [version]$best) { $best = $tv }
            } catch {
                if ($tv -gt $best) { $best = $tv }
            }
        }
    }
    if (-not $best) {
        throw "No version tags found on $Owner/$Repo. Push a tag like v0.2.0 first."
    }
    Write-Ok "Latest version tag: v$best"
    return $best
}

function Find-AppRoot([string]$ExtractDir) {
    if ((Test-Path (Join-Path $ExtractDir 'setup.php')) -and (Test-Path (Join-Path $ExtractDir 'index.php'))) {
        return $ExtractDir
    }
    $dirs = Get-ChildItem -Path $ExtractDir -Directory -ErrorAction SilentlyContinue
    foreach ($d in $dirs) {
        if ((Test-Path (Join-Path $d.FullName 'setup.php')) -and (Test-Path (Join-Path $d.FullName 'index.php'))) {
            return $d.FullName
        }
        if ((Test-Path (Join-Path $d.FullName 'scripts\Install-ColdAisle-Prereqs.ps1'))) {
            return $d.FullName
        }
    }
    return $null
}

function Download-ColdAisleRelease {
    param(
        [string]$Owner,
        [string]$Repo,
        [string]$Version,
        [string]$WorkRoot
    )

    # Version may be "0.2.2", "v0.2.2", "main", or "master"
    $ref = $Version.Trim()
    if ($ref -match '^(?i)(main|master)$') {
        $zipUrl = "https://github.com/$Owner/$Repo/archive/refs/heads/$($ref.ToLower()).zip"
        $label = $ref.ToLower()
    } else {
        $ref = $ref -replace '^[vV]', ''
        $tag = "v$ref"
        $zipUrl = "https://github.com/$Owner/$Repo/archive/refs/tags/$tag.zip"
        $label = $tag
    }

    $safeName = ($label -replace '[^\w\.\-]+', '_')
    $zipPath = Join-Path $WorkRoot "ColdAisle-$safeName.zip"
    $extractRoot = Join-Path $WorkRoot 'extract'

    Write-Step "Downloading $Owner/$Repo $label"
    Write-Host "    URL: $zipUrl" -ForegroundColor DarkGray
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath -UseBasicParsing
    if (-not (Test-Path $zipPath) -or ((Get-Item $zipPath).Length -lt 1000)) {
        throw "Download failed or file too small: $zipPath"
    }
    Write-Ok ("Downloaded {0:N0} bytes" -f (Get-Item $zipPath).Length)

    Write-Step 'Extracting archive'
    if (Test-Path $extractRoot) {
        Remove-Item $extractRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path $extractRoot -Force | Out-Null
    Expand-Archive -LiteralPath $zipPath -DestinationPath $extractRoot -Force

    $appRoot = Find-AppRoot $extractRoot
    if (-not $appRoot) {
        throw "Could not find ColdAisle root (setup.php / index.php) inside $extractRoot"
    }
    Write-Ok "Application root: $appRoot"
    return $appRoot
}

function Resolve-PrereqScript([string]$AppRoot) {
    $candidates = @(
        (Join-Path $AppRoot 'scripts\Install-ColdAisle-Prereqs.ps1'),
        (Join-Path $AppRoot 'scripts\Install-WinDCIM-Prereqs.ps1')
    )
    foreach ($p in $candidates) {
        if (Test-Path -LiteralPath $p) { return $p }
    }
    return $null
}

# -------------------- main --------------------
Assert-Admin
Ensure-Tls12

Write-Host ''
Write-Host '  ColdAisle installer (public GitHub release)' -ForegroundColor White
Write-Host '  https://github.com/sabap/ColdAisle' -ForegroundColor DarkGray
Write-Host '  Tested: Windows Server 2025 - SQL Server 2022 Enterprise (and typical Express/Standard)' -ForegroundColor DarkGray
Write-Host ''

Invoke-PreflightChecks

$work = Join-Path $env:TEMP ("ColdAisle-install-{0}" -f [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work -Force | Out-Null
Write-Ok "Work directory: $work"

try {
    $ver = Resolve-LatestVersion -Owner $GitHubOwner -Repo $GitHubRepo -Requested $Version
    $appRoot = Download-ColdAisleRelease -Owner $GitHubOwner -Repo $GitHubRepo -Version $ver -WorkRoot $work

    $prereq = Resolve-PrereqScript $appRoot
    # Old tags (e.g. v0.2.0) predate Install-ColdAisle-Prereqs.ps1 - fall back to main
    if (-not $prereq -and $ver -notmatch '^(?i)(main|master)$') {
        Write-Warn "Release v$ver is missing the platform installer script. Falling back to branch main."
        $ver = 'main'
        $appRoot = Download-ColdAisleRelease -Owner $GitHubOwner -Repo $GitHubRepo -Version $ver -WorkRoot $work
        $prereq = Resolve-PrereqScript $appRoot
    }
    if (-not $prereq) {
        throw "Missing prereq script under $appRoot\scripts (expected Install-ColdAisle-Prereqs.ps1)."
    }

    Write-Step 'Running platform + deploy script (IIS, PHP, ODBC, copy files)'
    Write-Host "    $prereq" -ForegroundColor DarkGray

    $prereqArgs = @{
        PhpVersion        = $PhpVersion
        PhpInstallPath    = $PhpInstallPath
        SiteName          = $SiteName
        SitePhysicalPath  = $SitePhysicalPath
        DeploySource      = $appRoot
    }
    if ($SkipOdbc) { $prereqArgs.SkipOdbc = $true }
    if ($SkipUrlRewrite) { $prereqArgs.SkipUrlRewrite = $true }
    if ($SkipSqlsrv) { $prereqArgs.SkipSqlsrv = $true }
    if ($Force) { $prereqArgs.Force = $true }
    # Always run prereq verification; OpenSetup only from this outer script to avoid double-open
    $prereqArgs.RunVerification = $true

    & $prereq @prereqArgs
    if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) {
        throw "Install-ColdAisle-Prereqs.ps1 exited with code $LASTEXITCODE"
    }

    $ok = Invoke-PostInstallChecks -SitePath $SitePhysicalPath -PhpPath $PhpInstallPath

    Write-Host ''
    Write-Host '================================================================' -ForegroundColor Green
    Write-Host '  ColdAisle platform install finished' -ForegroundColor Green
    Write-Host '================================================================' -ForegroundColor Green
    Write-Host @"

  Version deployed:  v$ver
  Site path:         $SitePhysicalPath
  PHP:               $PhpInstallPath

  Next steps:
    1. Ensure SQL Server is installed and reachable
       (tested with SQL Server 2022 Enterprise; Express/Standard also fine).
    2. Open the web wizard:
         http://localhost/setup.php
       (or http://YOUR-SERVER/setup.php)
    3. Enter SQL connection details and create the first admin account.
    4. Delete phpinfo-test.php from the site root if present.
    5. Optional later: Settings -> Updates for one-click upgrades.
       Donate: https://paypal.me/mattelsberry

  Gotchas:
    - SQL engine is NOT installed by this script.
    - Use SQL auth or Windows auth that IIS/PHP can use (app pool identity for Windows auth).
    - For LDAPS later: open outbound TCP 636 and enable PHP ldap.
    - For SNMP polling: Task Scheduler -> php.exe scripts\poll_snmp.php
    - Re-run with -Force to refresh app files (config\config.php is preserved).

"@

    if ($OpenSetup) {
        if ($ok) {
            Open-SetupWizard -SitePath $SitePhysicalPath
        } else {
            Write-Warn 'Skipping -OpenSetup because verification reported issues. Fix warnings above, then browse setup.php manually.'
        }
    } else {
        Write-Host '  Tip: re-run with -OpenSetup to launch the browser automatically.' -ForegroundColor DarkGray
    }
}
finally {
    if (-not $KeepDownload -and (Test-Path $work)) {
        try {
            Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
            Write-Ok 'Cleaned temp download folder'
        } catch {
            Write-Warn "Could not remove temp folder: $work"
        }
    } elseif ($KeepDownload) {
        Write-Warn "Keeping download folder (-KeepDownload): $work"
    }
}

Write-Host 'Done.' -ForegroundColor Green
