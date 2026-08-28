<#
.SYNOPSIS
  Export or import ColdAisle raceway centerlines (cable_paths) as JSON.

.EXAMPLE
  # On the lab IIS box (source with intact floorplan):
  .\scripts\Raceway-Pack.ps1 export -Root C:\inetpub\wwwroot\WinDCIM -File C:\temp\raceway_pack.json

  # Dry-run on production:
  .\scripts\Raceway-Pack.ps1 import -Root C:\inetpub\wwwroot\WinDCIM -File C:\temp\raceway_pack.json -DryRun

  # Apply on production (restores path_id so cable hops can reconnect):
  .\scripts\Raceway-Pack.ps1 import -Root C:\inetpub\wwwroot\WinDCIM -File C:\temp\raceway_pack.json
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [ValidateSet('export', 'import')]
    [string]$Action,

    [string]$Root = '',
    [string]$File = 'C:\temp\raceway_pack.json',
    [switch]$DryRun,
    [int]$RoomId = 0,
    [switch]$SkipCodes,
    [ValidateSet('id', 'code')]
    [string]$Match = 'id'
)

$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
if (-not $here) { $here = Split-Path -Parent $MyInvocation.MyCommand.Path }
$repoRoot = (Resolve-Path (Join-Path $here '..')).Path
if (-not $Root) { $Root = $repoRoot }

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    foreach ($c in @('C:\PHP\php.exe', 'C:\php\php.exe')) {
        if (Test-Path $c) { $php = $c; break }
    }
}
if (-not $php) { throw 'php.exe not found on PATH.' }
$phpExe = if ($php -is [string]) { $php } else { $php.Source }

$script = Join-Path $Root 'scripts\raceway_pack.php'
if (-not (Test-Path $script)) {
    $script = Join-Path $repoRoot 'scripts\raceway_pack.php'
}
if (-not (Test-Path $script)) { throw "raceway_pack.php not found (tried $script)." }

$args = @($script, $Action, '--root', $Root, '--file', $File, '--match', $Match)
if ($DryRun) { $args += '--dry-run' }
if ($SkipCodes) { $args += '--skip-codes' }
if ($RoomId -gt 0) { $args += '--room-id'; $args += [string]$RoomId }

Write-Host "php $($args -join ' ')" -ForegroundColor DarkGray
& $phpExe @args
exit $LASTEXITCODE
