#Requires -Version 5.1
<#
.SYNOPSIS
    Cut a ColdAisle release: CHANGELOG → VERSION → tag → push → GitHub Release notes.

.DESCRIPTION
    Reads categorized bullets under ## [Unreleased] in CHANGELOG.md
    (New features / Enhancements / Bug fixes), promotes them to a versioned
    section, bumps VERSION + App::VERSION, commits, tags vX.Y.Z, pushes, and
    creates a GitHub Release when the GitHub CLI (gh) is available.

.PARAMETER Version
    Semver without leading v (e.g. 0.2.76). Required.

.PARAMETER Date
    Release date YYYY-MM-DD. Default: today (local).

.PARAMETER Summary
    Short commit/tag subject after "Release x.y.z: ". Default: derived from first changelog bullet.

.PARAMETER AllowEmpty
    Allow shipping with no Unreleased bullets (not recommended).

.PARAMETER SkipPush
    Commit and tag locally only.

.PARAMETER SkipGitHubRelease
    Do not call gh release create (still pushes tag if not -SkipPush).

.PARAMETER DryRun
    Show planned changelog body and version files; no writes/commits.

.EXAMPLE
    .\scripts\Release-ColdAisle.ps1 -Version 0.2.76

.EXAMPLE
    .\scripts\Release-ColdAisle.ps1 -Version 0.2.76 -Summary "PDU edit template apply" -DryRun
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+')]
    [string]$Version,

    [string]$Date = (Get-Date -Format 'yyyy-MM-dd'),

    [string]$Summary = '',

    [switch]$AllowEmpty,

    [switch]$SkipPush,

    [switch]$SkipGitHubRelease,

    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$Version = $Version.TrimStart('v', 'V')
$tag = "v$Version"

function Find-Git {
    $cmd = Get-Command git -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($c in @(
            'C:\Program Files\Git\cmd\git.exe',
            'C:\Program Files (x86)\Git\cmd\git.exe'
        )) {
        if (Test-Path $c) { return $c }
    }
    throw 'git.exe not found.'
}

function Find-Gh {
    $cmd = Get-Command gh -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($c in @(
            'C:\Program Files\GitHub CLI\gh.exe',
            "$env:LOCALAPPDATA\Programs\GitHub CLI\gh.exe"
        )) {
        if (Test-Path $c) { return $c }
    }
    return $null
}

function Get-RepoRoot {
    $here = $PSScriptRoot
    if (-not $here) { $here = Split-Path -Parent $MyInvocation.MyCommand.Path }
    return (Resolve-Path (Join-Path $here '..')).Path
}

function Parse-Unreleased([string]$text) {
    # Capture from ## [Unreleased] until next ## [version] or ## Earlier
    if ($text -notmatch '(?s)## \[Unreleased\]\s*\r?\n(.*?)(?=\r?\n---\s*\r?\n\r?\n## \[|\r?\n## \[\d|\r?\n## Earlier)') {
        throw 'Could not find ## [Unreleased] section in CHANGELOG.md'
    }
    $body = $Matches[1].TrimEnd()
    $cats = [ordered]@{
        'New features' = @()
        'Enhancements' = @()
        'Bug fixes'    = @()
    }
    $current = $null
    foreach ($line in ($body -split '\r?\n')) {
        if ($line -match '^###\s+(.+?)\s*$') {
            $name = $Matches[1].Trim()
            if ($cats.Contains($name)) {
                $current = $name
            } else {
                $current = $null
            }
            continue
        }
        if ($null -eq $current) { continue }
        if ($line -match '^\s*-\s+(.+)$') {
            $cats[$current] += $Matches[1].Trim()
        }
    }
    return $cats
}

function Format-CategoryBody($cats, [bool]$forChangelog) {
    $sb = New-Object System.Text.StringBuilder
    $any = $false
    foreach ($name in @('New features', 'Enhancements', 'Bug fixes')) {
        $items = @($cats[$name])
        if ($items.Count -eq 0) { continue }
        $any = $true
        [void]$sb.AppendLine("### $name")
        [void]$sb.AppendLine()
        foreach ($item in $items) {
            [void]$sb.AppendLine("- $item")
        }
        [void]$sb.AppendLine()
    }
    if (-not $any) {
        return ''
    }
    return $sb.ToString().TrimEnd() + "`n"
}

function Build-FirstSummary($cats) {
    foreach ($name in @('New features', 'Enhancements', 'Bug fixes')) {
        $items = @($cats[$name])
        if ($items.Count -gt 0) {
            $t = $items[0]
            if ($t.Length -gt 72) { $t = $t.Substring(0, 69) + '...' }
            return $t
        }
    }
    return 'maintenance release'
}

$root = Get-RepoRoot
Set-Location $root
$git = Find-Git
$changelogPath = Join-Path $root 'CHANGELOG.md'
$versionPath = Join-Path $root 'VERSION'
$appPath = Join-Path $root 'src\App.php'

if (-not (Test-Path $changelogPath)) { throw "Missing $changelogPath" }
if (-not (Test-Path $versionPath)) { throw "Missing $versionPath" }
if (-not (Test-Path $appPath)) { throw "Missing $appPath" }

$cl = Get-Content -Path $changelogPath -Raw -Encoding UTF8
$cats = Parse-Unreleased $cl
$total = 0
foreach ($k in $cats.Keys) { $total += @($cats[$k]).Count }

if ($total -eq 0 -and -not $AllowEmpty) {
    throw @"
No bullets under CHANGELOG.md [Unreleased].
Add items under ### New features / ### Enhancements / ### Bug fixes,
or pass -AllowEmpty for an empty notes release.
"@
}

$versionBody = Format-CategoryBody $cats $true
if ($versionBody -eq '') {
    $versionBody = "### Enhancements`n`n- Maintenance release.`n"
}

if ($Summary -eq '') {
    $Summary = Build-FirstSummary $cats
}

$releaseNotes = @"
## ColdAisle $Version

$versionBody
**Full changelog:** https://github.com/sabap/ColdAisle/blob/main/CHANGELOG.md
"@

Write-Host "==> Release $Version ($tag)  date=$Date" -ForegroundColor Cyan
Write-Host "    Summary: $Summary" -ForegroundColor DarkGray
Write-Host ""
Write-Host "---- Release notes ----" -ForegroundColor Cyan
Write-Host $releaseNotes
Write-Host "-----------------------" -ForegroundColor Cyan

if ($DryRun) {
    Write-Host "[DryRun] No files changed." -ForegroundColor Yellow
    exit 0
}

# Ensure tag does not already exist
$existing = & $git tag -l $tag
if ($existing) {
    throw "Tag $tag already exists."
}

$curVer = (Get-Content $versionPath -Raw).Trim()
if ($curVer -eq $Version) {
    Write-Host "    VERSION already $Version (will rewrite changelog + retag only if needed)" -ForegroundColor DarkGray
}

# Inject version section after Unreleased block
$unreleasedStub = @"
## [Unreleased]

### New features

### Enhancements

### Bug fixes

"@

$versionSection = @"
## [$Version] - $Date

$versionBody
"@

# Replace Unreleased section content with empty stub + new version
$pattern = '(?s)(## \[Unreleased\]\s*\r?\n)(.*?)(\r?\n---\s*\r?\n)'
if ($cl -notmatch $pattern) {
    throw 'CHANGELOG.md Unreleased block format not recognized (expected ## [Unreleased] ... ---).'
}

$newCl = [regex]::Replace(
    $cl,
    $pattern,
    {
        param($m)
        return $unreleasedStub + "`n---`n`n" + $versionSection + "`n---`n"
    },
    1
)

# If regex replace failed to change, fall back to simpler approach
if ($newCl -eq $cl) {
    throw 'Failed to rewrite CHANGELOG.md Unreleased section.'
}

Set-Content -Path $changelogPath -Value $newCl -Encoding UTF8 -NoNewline
# Ensure trailing newline
Add-Content -Path $changelogPath -Value '' -Encoding UTF8

Set-Content -Path $versionPath -Value "$Version`n" -Encoding ascii -NoNewline

$app = Get-Content -Path $appPath -Raw -Encoding UTF8
if ($app -notmatch "public const VERSION = '[^']+'") {
    throw 'Could not find App::VERSION constant.'
}
$app2 = [regex]::Replace($app, "public const VERSION = '[^']+'", "public const VERSION = '$Version'")
Set-Content -Path $appPath -Value $app2 -Encoding UTF8 -NoNewline
if (-not $app2.EndsWith("`n")) {
    Add-Content -Path $appPath -Value '' -Encoding UTF8
}

Write-Host "==> Files updated: CHANGELOG.md, VERSION, src/App.php" -ForegroundColor Green

& $git add -- CHANGELOG.md VERSION src/App.php
$status = & $git status --porcelain
if (-not $status) {
    Write-Host "    Nothing to commit (working tree clean after write?)." -ForegroundColor Yellow
} else {
    $msg = "Release ${Version}: $Summary"
    & $git commit -m $msg
    Write-Host "==> Committed: $msg" -ForegroundColor Green
}

& $git tag -a $tag -m "Release ${Version}: $Summary"
Write-Host "==> Tagged $tag" -ForegroundColor Green

if (-not $SkipPush) {
    & $git push origin main
    & $git push origin $tag
    Write-Host "==> Pushed main and $tag" -ForegroundColor Green
} else {
    Write-Host "==> SkipPush: tag is local only" -ForegroundColor Yellow
}

$gh = Find-Gh
if (-not $SkipGitHubRelease -and -not $SkipPush -and $gh) {
    $notesFile = Join-Path $env:TEMP ("coldaisle-release-{0}.md" -f $Version)
    Set-Content -Path $notesFile -Value $releaseNotes.TrimEnd() -Encoding UTF8
    try {
        & $gh release create $tag --title "ColdAisle $Version" --notes-file $notesFile
        Write-Host "==> GitHub Release created for $tag" -ForegroundColor Green
    } catch {
        Write-Host "==> gh release create failed: $($_.Exception.Message)" -ForegroundColor Yellow
        Write-Host "    Create manually and paste notes from above." -ForegroundColor Yellow
    } finally {
        Remove-Item $notesFile -Force -ErrorAction SilentlyContinue
    }
} elseif (-not $SkipGitHubRelease) {
    Write-Host "==> GitHub CLI (gh) not found or push skipped - create the Release on GitHub and paste the notes above." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Done. Version ${Version} shipped with categorized changelog." -ForegroundColor Green
