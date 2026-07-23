#Requires -Version 5.1
<#
.SYNOPSIS
    Probe an openDCIM REST API from a machine that can reach the production server.
    Saves sample JSON so you can confirm templates include FrontPictureFile / RearPictureFile
    and devices include TemplateID / Cabinet.

.DESCRIPTION
    Run this on a jump box / server that has network access to openDCIM.
    This machine does not need ColdAisle installed.

    What it does:
      1. TLS 1.2 enable (common Windows gotcha)
      2. GET several common openDCIM API paths (configurable base URL)
      3. Prints HTTP status and a short summary
      4. Writes raw JSON under .\opendcim-api-probe\<timestamp>\

    Auth (try what your openDCIM install uses):
      - Basic:  -Username / -Password  (often the openDCIM web login)
      - Header: -ApiKey  (if your build uses an API key header)
      - None:   public/read-only lab only

    Typical base URL (no trailing slash):
      https://dcim.example.com
      https://dcim.example.com/dcim

    Paths tried by default (relative to /api/v1 unless -ApiRoot overrides):
      /devicetemplate   /devicetemplate   /template
      /device
      /cabinet
      /datacenter
      /manufacturer

.PARAMETER BaseUrl
    openDCIM site root, e.g. https://dcim.sgmc.org

.PARAMETER ApiRoot
    API prefix. Default: /api/v1

.PARAMETER Username
    Basic auth username (optional).

.PARAMETER Password
    Basic auth password (optional). Prefer -Credential if possible.

.PARAMETER Credential
    PSCredential for basic auth (optional).

.PARAMETER ApiKey
    If set, sent as:  Authorization: <value>
    Also tries:       X-API-Key: <value>
    Use whatever your docs specify; see -ApiKeyHeader.

.PARAMETER ApiKeyHeader
    Header name for API key. Default empty = try Authorization Bearer and X-API-Key.

.PARAMETER SkipCertificateCheck
    Skip TLS cert validation (lab only; requires PS 6+ or a workaround on Windows PowerShell 5.1).

.PARAMETER OutDir
    Where to write JSON samples. Default: .\opendcim-api-probe\<timestamp>

.EXAMPLE
    # Basic auth (most common for openDCIM)
    .\Test-OpenDcimApi.ps1 -BaseUrl 'https://dcim.example.com' -Username 'readonly' -Password '***'

.EXAMPLE
    .\Test-OpenDcimApi.ps1 -BaseUrl 'https://dcim.example.com' -Credential (Get-Credential)

.EXAMPLE
    # Custom API root if docs show something else
    .\Test-OpenDcimApi.ps1 -BaseUrl 'https://dcim.example.com' -ApiRoot '/api/v1' -Username 'admin' -Password '***'
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$BaseUrl,

    [string]$ApiRoot = '/api/v1',

    [string]$Username = '',
    [string]$Password = '',
    [System.Management.Automation.PSCredential]$Credential = $null,

    [string]$ApiKey = '',
    [string]$ApiKeyHeader = '',

    [switch]$SkipCertificateCheck,

    [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Write-Ok([string]$m) { Write-Host "    [OK] $m" -ForegroundColor Green }
function Write-Warn([string]$m) { Write-Host "    [WARN] $m" -ForegroundColor Yellow }
function Write-Err([string]$m) { Write-Host "    [ERR] $m" -ForegroundColor Red }

function Ensure-Tls12 {
    try {
        [Net.ServicePointManager]::SecurityProtocol = `
            [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    } catch { }
}

function Get-BasicAuthHeader {
    param([string]$User, [string]$Pass)
    $pair = '{0}:{1}' -f $User, $Pass
    $bytes = [Text.Encoding]::ASCII.GetBytes($pair)
    return 'Basic ' + [Convert]::ToBase64String($bytes)
}

function Invoke-OpenDcimGet {
    param(
        [string]$Url,
        [hashtable]$Headers
    )

    $params = @{
        Uri             = $Url
        Method          = 'GET'
        Headers         = $Headers
        UseBasicParsing = $true
        TimeoutSec      = 60
    }

    # PS 6+
    if ($SkipCertificateCheck -and (Get-Command Invoke-WebRequest).Parameters.ContainsKey('SkipCertificateCheck')) {
        $params.SkipCertificateCheck = $true
    }

    try {
        $resp = Invoke-WebRequest @params
        return [pscustomobject]@{
            Ok         = $true
            StatusCode = [int]$resp.StatusCode
            Content    = [string]$resp.Content
            Error      = $null
        }
    } catch {
        $status = $null
        $body = $null
        if ($_.Exception.Response) {
            try {
                $status = [int]$_.Exception.Response.StatusCode
                $stream = $_.Exception.Response.GetResponseStream()
                if ($stream) {
                    $reader = New-Object System.IO.StreamReader($stream)
                    $body = $reader.ReadToEnd()
                }
            } catch { }
        }
        return [pscustomobject]@{
            Ok         = $false
            StatusCode = $status
            Content    = $body
            Error      = $_.Exception.Message
        }
    }
}

# -------------------- main --------------------
Ensure-Tls12

$BaseUrl = $BaseUrl.TrimEnd('/')
$ApiRoot = '/' + ($ApiRoot.Trim().Trim('/'))
if (-not $OutDir) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $OutDir = Join-Path (Get-Location) "opendcim-api-probe\$stamp"
}
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

Write-Host ''
Write-Host '  openDCIM API probe' -ForegroundColor White
Write-Host "  Base: $BaseUrl" -ForegroundColor DarkGray
Write-Host "  API:  $BaseUrl$ApiRoot" -ForegroundColor DarkGray
Write-Host "  Out:  $OutDir" -ForegroundColor DarkGray
Write-Host ''

# Build headers
$headers = @{
    'Accept'     = 'application/json'
    'User-Agent' = 'ColdAisle-OpenDcimProbe/1.0'
}

if ($Credential) {
    $Username = $Credential.UserName
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Credential.Password)
    try {
        $Password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}

if ($Username -and $Password) {
    $headers['Authorization'] = Get-BasicAuthHeader -User $Username -Pass $Password
    Write-Ok "Using HTTP Basic auth as user '$Username'"
} elseif ($ApiKey) {
    if ($ApiKeyHeader) {
        $headers[$ApiKeyHeader] = $ApiKey
        Write-Ok "Using API key header: $ApiKeyHeader"
    } else {
        # Try common patterns; first request may need adjustment per your docs
        $headers['Authorization'] = "Bearer $ApiKey"
        Write-Ok 'Using Authorization: Bearer <ApiKey> (set -ApiKeyHeader if your docs differ)'
    }
} else {
    Write-Warn 'No credentials supplied — only works if the API allows anonymous read'
}

# Windows PowerShell 5.1 skip-cert workaround (lab only)
if ($SkipCertificateCheck -and $PSVersionTable.PSVersion.Major -lt 6) {
    Write-Warn 'SkipCertificateCheck on Windows PowerShell 5.1: enabling insecure cert callback (lab only)'
    add-type @"
using System.Net;
using System.Security.Cryptography.X509Certificates;
public class TrustAllCertsPolicy : ICertificatePolicy {
    public bool CheckValidationResult(ServicePoint s, X509Certificate c, WebRequest r, int p) { return true; }
}
"@
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy
}

# Paths to try (openDCIM versions differ slightly on naming)
$paths = @(
    '/device',
    '/cabinet',
    '/datacenter',
    '/manufacturer',
    '/devicetemplate',
    '/device_template',
    '/template',
    '/department',
    '/people'
)

$summary = @()

Write-Step 'Probing endpoints'
foreach ($rel in $paths) {
    $url = "$BaseUrl$ApiRoot$rel"
    Write-Host "    GET $url" -ForegroundColor DarkGray
    $r = Invoke-OpenDcimGet -Url $url -Headers $headers

    $safeName = ($rel.Trim('/') -replace '[^\w\-]+', '_')
    if (-not $safeName) { $safeName = 'root' }
    $outFile = Join-Path $OutDir "$safeName.json"

    $count = $null
    $sampleKeys = $null
    $notes = @()

    if ($r.Ok -and $r.Content) {
        Set-Content -Path $outFile -Value $r.Content -Encoding UTF8
        try {
            $json = $r.Content | ConvertFrom-Json
            if ($json -is [System.Array]) {
                $count = $json.Count
                if ($count -gt 0) {
                    $sampleKeys = @($json[0].PSObject.Properties.Name) -join ', '
                    # Highlight fields we care about for ColdAisle import
                    $props = @($json[0].PSObject.Properties.Name)
                    foreach ($want in @('TemplateID', 'FrontPictureFile', 'RearPictureFile', 'Cabinet', 'Label', 'CabinetID', 'DataCenterID', 'Location', 'ManufacturerID', 'Model')) {
                        if ($props -contains $want) { $notes += "has:$want" }
                    }
                }
            } elseif ($json) {
                $count = 1
                $sampleKeys = @($json.PSObject.Properties.Name) -join ', '
            }
            Write-Ok "HTTP $($r.StatusCode)  -> $outFile  (items≈$count)"
            if ($notes.Count) {
                Write-Host ("           " + ($notes -join '  ')) -ForegroundColor Green
            }
        } catch {
            Write-Ok "HTTP $($r.StatusCode)  -> $outFile  (saved; JSON parse note: $($_.Exception.Message))"
        }
    } else {
        $msg = if ($r.StatusCode) { "HTTP $($r.StatusCode)" } else { 'no status' }
        Write-Warn "$msg  $rel  — $($r.Error)"
        if ($r.Content) {
            Set-Content -Path $outFile -Value $r.Content -Encoding UTF8
        }
    }

    $summary += [pscustomobject]@{
        Path       = $rel
        Url        = $url
        Ok         = [bool]$r.Ok
        StatusCode = $r.StatusCode
        ItemCount  = $count
        SampleKeys = $sampleKeys
        File       = $outFile
        Notes      = ($notes -join '; ')
        Error      = $r.Error
    }
}

# Write summary CSV
$sumFile = Join-Path $OutDir '_summary.csv'
$summary | Export-Csv -Path $sumFile -NoTypeInformation -Encoding UTF8
Write-Ok "Summary: $sumFile"

# Quick import readiness report
Write-Step 'ColdAisle import readiness (from successful GETs)'
$tmpl = $summary | Where-Object { $_.Ok -and ($_.Path -match 'template') -and $_.ItemCount -gt 0 } | Select-Object -First 1
$dev  = $summary | Where-Object { $_.Ok -and $_.Path -eq '/device' -and $_.ItemCount -gt 0 } | Select-Object -First 1
$cab  = $summary | Where-Object { $_.Ok -and $_.Path -eq '/cabinet' -and $_.ItemCount -gt 0 } | Select-Object -First 1

if ($tmpl) {
    Write-Ok "Templates endpoint works: $($tmpl.Path) ($($tmpl.ItemCount) items)"
    if ($tmpl.Notes -match 'FrontPictureFile') { Write-Ok 'Templates expose FrontPictureFile' }
    else { Write-Warn 'FrontPictureFile not seen on first template object — open the JSON and confirm field names' }
    if ($tmpl.Notes -match 'RearPictureFile') { Write-Ok 'Templates expose RearPictureFile' }
} else {
    Write-Warn 'No working template list endpoint yet — check ApiRoot path names in your /api/docs/'
}

if ($dev) {
    Write-Ok "Devices endpoint works ($($dev.ItemCount) items)"
    if ($dev.Notes -match 'TemplateID') { Write-Ok 'Devices expose TemplateID (links to template images)' }
    else { Write-Warn 'TemplateID not seen on first device — open device.json' }
    if ($dev.Notes -match 'Cabinet') { Write-Ok 'Devices expose Cabinet' }
} else {
    Write-Warn 'Devices endpoint failed or empty'
}

if ($cab) {
    Write-Ok "Cabinets endpoint works ($($cab.ItemCount) items)"
    if ($cab.Notes -match 'DataCenterID') { Write-Ok 'Cabinets expose DataCenterID' }
    if ($cab.Notes -match 'Location') { Write-Ok 'Cabinets expose Location' }
} else {
    Write-Warn 'Cabinets endpoint failed or empty'
}

Write-Host @"

================================================================
  Done.

  If auth failed (401/403):
    - Confirm BaseUrl (include virtual directory if any)
    - Try -Username/-Password (openDCIM web user with API rights)
    - Or -ApiKey / -ApiKeyHeader from your API docs

  If paths 404:
    - Open $BaseUrl/api/docs/ and note exact paths
    - Re-run with -ApiRoot matching the docs (often /api/v1)

  Zip the folder and bring it offline if useful:
    $OutDir

================================================================
"@
