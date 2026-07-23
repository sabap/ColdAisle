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

    Recommended usage (download, review, then run — do not pipe to iex if you want to audit first):

      Invoke-WebRequest -Uri "https://raw.githubusercontent.com/sabap/ColdAisle/main/Install-ColdAisle.ps1" `
        -OutFile .\Install-ColdAisle.ps1
      notepad .\Install-ColdAisle.ps1   # optional: review
      Set-ExecutionPolicy Bypass -Scope Process -Force
      .\Install-ColdAisle.ps1

.PARAMETER Version
    Release tag without or with leading v (e.g. 0.2.0 or v0.2.0).
    Default: latest version tag from GitHub.

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

.EXAMPLE
    .\Install-ColdAisle.ps1

.EXAMPLE
    .\Install-ColdAisle.ps1 -Version 0.2.0 -SitePhysicalPath 'C:\inetpub\wwwroot\ColdAisle'

.EXAMPLE
    .\Install-ColdAisle.ps1 -Force
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
    [switch]$KeepDownload
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
        throw 'This installer must run as Administrator (elevated PowerShell).'
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
        return ($Requested.Trim() -replace '^[vV]', '')
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

    $tag = "v$Version"
    # Public zipball (no auth)
    $zipUrl = "https://github.com/$Owner/$Repo/archive/refs/tags/$tag.zip"
    $zipPath = Join-Path $WorkRoot "ColdAisle-$tag.zip"
    $extractRoot = Join-Path $WorkRoot 'extract'

    Write-Step "Downloading $Owner/$Repo $tag"
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

# -------------------- main --------------------
Assert-Admin

Write-Host ''
Write-Host '  ColdAisle installer (public GitHub release)' -ForegroundColor White
Write-Host '  https://github.com/sabap/ColdAisle' -ForegroundColor DarkGray
Write-Host ''

$work = Join-Path $env:TEMP ("ColdAisle-install-{0}" -f [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work -Force | Out-Null
Write-Ok "Work directory: $work"

try {
    $ver = Resolve-LatestVersion -Owner $GitHubOwner -Repo $GitHubRepo -Requested $Version
    $appRoot = Download-ColdAisleRelease -Owner $GitHubOwner -Repo $GitHubRepo -Version $ver -WorkRoot $work

    $prereq = Join-Path $appRoot 'scripts\Install-ColdAisle-Prereqs.ps1'
    if (-not (Test-Path $prereq)) {
        throw "Missing prereq script in release: $prereq"
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

    & $prereq @prereqArgs
    if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) {
        throw "Install-ColdAisle-Prereqs.ps1 exited with code $LASTEXITCODE"
    }

    Write-Host ''
    Write-Host '================================================================' -ForegroundColor Green
    Write-Host '  ColdAisle platform install finished' -ForegroundColor Green
    Write-Host '================================================================' -ForegroundColor Green
    Write-Host @"

  Version deployed:  v$ver
  Site path:         $SitePhysicalPath
  PHP:               $PhpInstallPath

  Next steps:
    1. Ensure SQL Server is installed and reachable (Express is fine).
    2. Open a browser:
         http://localhost/setup.php
       (or http://YOUR-SERVER/setup.php)
    3. Enter SQL connection details and create the first admin account.
    4. Delete phpinfo-test.php from the site root if present.
    5. Optional later: Settings → Updates for one-click upgrades.
       Donate: https://paypal.me/mattelsberry

  Re-run this script with -Force to refresh application files from GitHub
  (config\config.php is preserved by the prereq deployer).

"@
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
