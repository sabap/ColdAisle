<#
.SYNOPSIS
  Restore cable_paths (raceway names + centerlines) from a coldaisle-site_*.zip
  or a cable_paths.json extract. SQL Server login - fill in the block below.

.DESCRIPTION
  Upserts dbo.cable_paths by path_id (IDENTITY_INSERT on new rows) so labels
  like RS-B / ORC-AB.1 / F-RS-C and waypoints come back together.
  Does not restore cabinets, devices, or other tables.

  Default is DRY-RUN. Set $Apply = $true (or pass -Apply) after a VM snapshot
  and a dry-run that lists the expected INSERT/UPDATE rows.

.EXAMPLE
  # 1) Edit the CONFIG block.  2) Snapshot SQL.  3) Dry-run:
  .\scripts\Import-RacewaysFromSiteBackup.ps1

  # 4) Apply:
  .\scripts\Import-RacewaysFromSiteBackup.ps1 -Apply
#>
[CmdletBinding()]
param(
    [switch]$Apply,
    [string]$ZipPath,
    [string]$JsonPath,
    [string]$SqlServer,
    [string]$Database,
    [string]$SqlUser,
    [string]$SqlPassword,
    [int]$ForceRoomId = 0,
    [switch]$RemoveExtraInRoom,
    [switch]$TrustServerCertificate = $true,
    [switch]$Encrypt
)

$ErrorActionPreference = 'Stop'
Write-Host 'Import-RacewaysFromSiteBackup.ps1  rev.5 ASCII' -ForegroundColor DarkGray

# =============================================================================
# CONFIG - edit these for production, then run once as dry-run, then -Apply
# =============================================================================
if (-not $SqlServer)   { $SqlServer   = 'CHANGE_ME' }          # e.g. PROD-SQL\INSTANCE
if (-not $Database)    { $Database    = 'WinDCIM' }
if (-not $SqlUser)     { $SqlUser     = 'CHANGE_ME' }
if (-not $SqlPassword) { $SqlPassword = '' }                   # leave blank to prompt
if (-not $ZipPath -and -not $JsonPath) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $ZipPath = Join-Path $scriptDir 'coldaisle-site_20260814_154021_v0.3.148.zip'
}
# $ForceRoomId = 1   # uncomment if dest hall is not room_id 1
# $RemoveExtraInRoom = $true  # also delete dest paths in that room not in the pack
# =============================================================================

if ($Apply) { $script:DoApply = $true } else { $script:DoApply = $false }

function Get-ZipEntryBytes {
    param([string]$ZipFile, [string[]]$Names)
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipFile)
    try {
        foreach ($want in $Names) {
            $e = $zip.Entries | Where-Object {
                ($_.FullName -replace '/', '\') -eq ($want -replace '/', '\')
            } | Select-Object -First 1
            if ($e) {
                $ms = New-Object System.IO.MemoryStream
                $s = $e.Open()
                try { $s.CopyTo($ms) } finally { $s.Dispose() }
                return [System.Text.Encoding]::UTF8.GetString($ms.ToArray())
            }
        }
    } finally {
        $zip.Dispose()
    }
    throw "Zip does not contain cable_paths.json: $ZipFile"
}

function Convert-PathRow {
    param($raw)
    $id = [int]$raw.path_id
    $room = [int]$raw.room_id
    $w = 0.0; $e = 0.0
    [void][double]::TryParse([string]$raw.width_m, [ref]$w)
    [void][double]::TryParse([string]$raw.elevation_m, [ref]$e)
    $active = 1
    if ($null -ne $raw.is_active -and [string]$raw.is_active -in @('0', 'false', 'False')) { $active = 0 }
    return [pscustomobject]@{
        path_id        = $id
        room_id        = $room
        name           = [string]$raw.name
        path_code      = [string]$raw.path_code
        path_type      = [string]$raw.path_type
        path_kind      = [string]$raw.path_kind
        feed_to        = [string]$raw.feed_to
        media_class    = [string]$raw.media_class
        segment_class  = $(if ($raw.segment_class) { [string]$raw.segment_class } else { $null })
        color_hex      = [string]$raw.color_hex
        notes          = $(if ($null -eq $raw.notes) { $null } else { [string]$raw.notes })
        waypoints      = [string]$raw.waypoints
        width_m        = $w
        elevation_m    = $e
        is_active      = $active
    }
}

function New-SqlConnection {
    param($Server, $Db, $User, $Pass, [bool]$Enc, [bool]$Trust)
    $b = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $b['Data Source'] = $Server
    $b['Initial Catalog'] = $Db
    $b['User ID'] = $User
    $b['Password'] = $Pass
    $b['Connect Timeout'] = 30
    try { $b['Encrypt'] = [bool]$Enc } catch { }
    try { $b['TrustServerCertificate'] = [bool]$Trust } catch { }
    $c = New-Object System.Data.SqlClient.SqlConnection ($b.ConnectionString)
    $c.Open()
    return $c
}

function Invoke-Cmd {
    param($Connection, $Transaction, [string]$Text, $Parameters)
    $cmd = $Connection.CreateCommand()
    $cmd.CommandText = $Text
    $cmd.CommandTimeout = 120
    if ($Transaction) { $cmd.Transaction = $Transaction }
    if ($Parameters) {
        foreach ($k in $Parameters.Keys) {
            $p = $cmd.Parameters.Add((New-Object System.Data.SqlClient.SqlParameter))
            $p.ParameterName = $k
            $val = $Parameters[$k]
            if ($null -eq $val) {
                $p.Value = [DBNull]::Value
            } else {
                $p.Value = $val
            }
        }
    }
    return $cmd
}

function Get-Rows {
    param($Cmd)
    $dt = New-Object System.Data.DataTable
    $da = New-Object System.Data.SqlClient.SqlDataAdapter
    $da.SelectCommand = $Cmd
    [void]$da.Fill($dt)
    $Cmd.Dispose()
    # Leading comma: PowerShell must not unroll a 1-row DataTable into a DataRow.
    return ,$dt
}

function Get-DataTableRows {
    param($Table)
    $out = @()
    if ($null -eq $Table) { return $out }
    if ($Table -is [System.Data.DataTable]) {
        for ($i = 0; $i -lt $Table.Rows.Count; $i++) {
            $out += $Table.Rows[$i]
        }
        return $out
    }
    if ($Table -is [System.Data.DataRow]) {
        return @($Table)
    }
    foreach ($x in @($Table)) {
        if ($x -is [System.Data.DataRow]) { $out += $x }
        elseif ($x -is [System.Data.DataTable]) {
            for ($i = 0; $i -lt $x.Rows.Count; $i++) { $out += $x.Rows[$i] }
        }
    }
    return $out
}

# --- load pack ---
if ($JsonPath) {
    if (-not (Test-Path $JsonPath)) { throw "JSON not found: $JsonPath" }
    $text = [System.IO.File]::ReadAllText($JsonPath)
} elseif ($ZipPath) {
    if (-not (Test-Path $ZipPath)) { throw "Zip not found: $ZipPath" }
    $text = Get-ZipEntryBytes -ZipFile $ZipPath -Names @(
        'data/cable_paths.json',
        'data\cable_paths.json'
    )
} else {
    throw 'Set $ZipPath or $JsonPath.'
}

$parsed = $text | ConvertFrom-Json
if ($parsed.PSObject.Properties.Name -contains 'paths') {
    $rawPaths = @($parsed.paths)
} elseif ($parsed -is [System.Array]) {
    $rawPaths = @($parsed)
} else {
    throw 'JSON is not a cable_paths array or raceway pack.'
}

$paths = foreach ($p in $rawPaths) { Convert-PathRow $p }
if ($paths.Count -lt 1) { throw 'No raceways in the pack.' }

Write-Host ("Pack: {0} raceway(s) from {1}" -f $paths.Count, $(if ($JsonPath) { $JsonPath } else { $ZipPath })) -ForegroundColor Cyan
$paths | Sort-Object path_id | ForEach-Object {
    $n = if ($_.waypoints) { ($_.waypoints.ToCharArray() | Where-Object { $_ -eq '{' }).Count } else { 0 }
    Write-Host ("  id={0,-4} room={1} {2,-14} {3,-16} pts~{4}  elev={5}" -f $_.path_id, $_.room_id, $_.path_code, $_.path_kind, $n, $_.elevation_m)
}

if ($SqlServer -match 'CHANGE_ME' -or $SqlUser -match 'CHANGE_ME') {
    throw 'Edit $SqlServer and $SqlUser in the CONFIG block (or pass -SqlServer / -SqlUser).'
}
if ([string]::IsNullOrEmpty($SqlPassword)) {
    $sec = Read-Host "SQL password for $SqlUser@$SqlServer" -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
    try { $SqlPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

$conn = New-SqlConnection -Server $SqlServer -Db $Database -User $SqlUser -Pass $SqlPassword -Enc ([bool]$Encrypt) -Trust ([bool]$TrustServerCertificate)
try {
    $who = @(Get-DataTableRows (Get-Rows (Invoke-Cmd $conn $null "SELECT DB_NAME() AS db_name, SUSER_SNAME() AS login_name, (SELECT COUNT(*) FROM dbo.rooms) AS room_count" @{})))
    if ($who.Count -ge 1) {
        $w = $who[0]
        Write-Host ("Connected: database={0}  login={1}  dbo.rooms count={2}" -f $w['db_name'], $w['login_name'], $w['room_count']) -ForegroundColor Cyan
    }
    $rooms = Get-Rows (Invoke-Cmd $conn $null 'SELECT room_id, name, width_m, depth_m FROM dbo.rooms ORDER BY room_id' @{})
    $roomRows = @(Get-DataTableRows $rooms)
    Write-Host ("Destination rooms: {0}" -f $roomRows.Count) -ForegroundColor Cyan
    foreach ($r in $roomRows) {
        Write-Host ("  room_id={0}  {1}  {2} x {3} m" -f $r['room_id'], $r['name'], $r['width_m'], $r['depth_m'])
    }

    if ($ForceRoomId -le 0 -and $roomRows.Count -eq 1) {
        $ForceRoomId = [int]$roomRows[0]['room_id']
        Write-Host ("One hall on this database - using room_id={0} ({1})" -f $ForceRoomId, $roomRows[0]['name']) -ForegroundColor Yellow
    }

    $dest = Get-Rows (Invoke-Cmd $conn $null 'SELECT path_id, room_id, path_code, name, path_kind FROM dbo.cable_paths' @{})
    $destRows = @(Get-DataTableRows $dest)
    $byId = @{}
    foreach ($row in $destRows) { $byId[[int]$row['path_id']] = $row }

    $inserts = @(); $updates = @(); $errors = @()
    $knownRooms = @{}
    foreach ($r in $roomRows) { $knownRooms[[int]$r['room_id']] = $true }

    foreach ($p in $paths) {
        $room = if ($ForceRoomId -gt 0) { $ForceRoomId } else { $p.room_id }
        $p | Add-Member -NotePropertyName dest_room_id -NotePropertyValue $room -Force
        if (-not $knownRooms.ContainsKey($room)) {
            $errors += "path_id $($p.path_id) ($($p.path_code)): room_id $room does not exist. Set `$ForceRoomId."
            continue
        }
        if ($byId.ContainsKey($p.path_id)) { $updates += $p } else { $inserts += $p }
    }

    $extras = @()
    if ($RemoveExtraInRoom) {
        $keep = @{}
        foreach ($p in $paths) { $keep[$p.path_id] = $true }
        $roomTarget = if ($ForceRoomId -gt 0) { $ForceRoomId } else { ($paths | Select-Object -First 1).room_id }
        foreach ($row in $destRows) {
            if ([int]$row['room_id'] -eq $roomTarget -and -not $keep.ContainsKey([int]$row['path_id'])) {
                $extras += [int]$row['path_id']
            }
        }
    }

    Write-Host ''
    Write-Host ("Plan: UPDATE {0}, INSERT {1}, extra-in-room delete {2}" -f $updates.Count, $inserts.Count, $extras.Count) -ForegroundColor Yellow
    foreach ($p in $updates) { Write-Host ("  UPDATE id={0} {1}" -f $p.path_id, $p.path_code) }
    foreach ($p in $inserts) { Write-Host ("  INSERT id={0} {1}" -f $p.path_id, $p.path_code) }
    foreach ($id in $extras) { Write-Host ("  DELETE extra id={0}" -f $id) }

    if ($errors.Count -gt 0) {
        $errors | ForEach-Object { Write-Host "ERROR: $_" -ForegroundColor Red }
        throw 'Fix room_id / $ForceRoomId and re-run.'
    }

    if (-not $script:DoApply) {
        Write-Host ''
        Write-Host 'DRY-RUN only. Database not changed. After the SQL VM snapshot, run again with -Apply (or set $Apply = $true).' -ForegroundColor Green
        return
    }

    $stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
    $safety = Join-Path $env:TEMP "cable_paths_before_import_$stamp.json"
    $safetyRows = Get-Rows (Invoke-Cmd $conn $null 'SELECT * FROM cable_paths' @{})
    $safetyRows | ConvertTo-Json -Depth 6 | Set-Content -Path $safety -Encoding UTF8
    Write-Host "Wrote current cable_paths snapshot: $safety" -ForegroundColor DarkGray

    $tx = $conn.BeginTransaction()
    try {
        $setSql = @'
room_id = @room_id,
name = @name,
path_code = @path_code,
path_type = @path_type,
path_kind = @path_kind,
feed_to = @feed_to,
media_class = @media_class,
segment_class = @segment_class,
color_hex = @color_hex,
notes = @notes,
waypoints = @waypoints,
width_m = @width_m,
elevation_m = @elevation_m,
is_active = @is_active
'@
        foreach ($p in $updates) {
            $prm = @{
                '@path_id'       = $p.path_id
                '@room_id'       = $p.dest_room_id
                '@name'          = $p.name
                '@path_code'     = $p.path_code
                '@path_type'     = $p.path_type
                '@path_kind'     = $p.path_kind
                '@feed_to'       = $p.feed_to
                '@media_class'   = $p.media_class
                '@segment_class' = $p.segment_class
                '@color_hex'     = $p.color_hex
                '@notes'         = $p.notes
                '@waypoints'     = $p.waypoints
                '@width_m'       = [decimal]$p.width_m
                '@elevation_m'   = [decimal]$p.elevation_m
                '@is_active'     = $p.is_active
            }
            $cmd = Invoke-Cmd $conn $tx "UPDATE cable_paths SET $setSql WHERE path_id = @path_id" $prm
            [void]$cmd.ExecuteNonQuery()
            $cmd.Dispose()
        }

        if ($inserts.Count -gt 0) {
            $on = Invoke-Cmd $conn $tx 'SET IDENTITY_INSERT cable_paths ON' @{}
            [void]$on.ExecuteNonQuery(); $on.Dispose()
            foreach ($p in $inserts) {
                $prm = @{
                    '@path_id'       = $p.path_id
                    '@room_id'       = $p.dest_room_id
                    '@name'          = $p.name
                    '@path_code'     = $p.path_code
                    '@path_type'     = $p.path_type
                    '@path_kind'     = $p.path_kind
                    '@feed_to'       = $p.feed_to
                    '@media_class'   = $p.media_class
                    '@segment_class' = $p.segment_class
                    '@color_hex'     = $p.color_hex
                    '@notes'         = $p.notes
                    '@waypoints'     = $p.waypoints
                    '@width_m'       = [decimal]$p.width_m
                    '@elevation_m'   = [decimal]$p.elevation_m
                    '@is_active'     = $p.is_active
                }
                $ins = @'
INSERT INTO cable_paths (
  path_id, room_id, name, path_code, path_type, path_kind, feed_to, media_class,
  segment_class, color_hex, notes, waypoints, width_m, elevation_m, is_active
) VALUES (
  @path_id, @room_id, @name, @path_code, @path_type, @path_kind, @feed_to, @media_class,
  @segment_class, @color_hex, @notes, @waypoints, @width_m, @elevation_m, @is_active
)
'@
                $cmd = Invoke-Cmd $conn $tx $ins $prm
                [void]$cmd.ExecuteNonQuery()
                $cmd.Dispose()
            }
            $off = Invoke-Cmd $conn $tx 'SET IDENTITY_INSERT cable_paths OFF' @{}
            [void]$off.ExecuteNonQuery(); $off.Dispose()
        }

        foreach ($id in $extras) {
            $cmd = Invoke-Cmd $conn $tx 'DELETE FROM cable_paths WHERE path_id = @id' @{ '@id' = $id }
            [void]$cmd.ExecuteNonQuery()
            $cmd.Dispose()
        }

        $tx.Commit()
        Write-Host ("Applied. Updated {0}, inserted {1}, deleted extras {2}." -f $updates.Count, $inserts.Count, $extras.Count) -ForegroundColor Green
        Write-Host 'Reload Floor planner on production. Prior cable_paths JSON: ' $safety
    } catch {
        try { $tx.Rollback() } catch { }
        try {
            $off = Invoke-Cmd $conn $null 'SET IDENTITY_INSERT cable_paths OFF' @{}
            [void]$off.ExecuteNonQuery(); $off.Dispose()
        } catch { }
        throw
    }
} finally {
    $conn.Close()
    $conn.Dispose()
}
