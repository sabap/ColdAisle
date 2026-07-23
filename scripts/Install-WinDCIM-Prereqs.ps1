#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs IIS + PHP (NTS/FastCGI) prerequisites for WinDCIM on Windows Server / Windows 10+.

.DESCRIPTION
    - Installs IIS role services needed for PHP FastCGI hosting
    - Downloads Visual C++ Redistributable (x64) if missing
    - Downloads PHP 8.x Non Thread Safe x64, extracts, configures php.ini
    - Registers php-cgi.exe with IIS FastCGI + *.php handler mapping
    - Optionally installs ODBC Driver 18 for SQL Server
    - Optionally installs IIS URL Rewrite module
    - Optionally installs Microsoft PHP SQL Server drivers (pdo_sqlsrv)
    - Deploys WinDCIM files to the IIS site physical path (default: C:\inetpub\wwwroot\WinDCIM)
    - Grants app-pool Modify ACLs on config\ and storage\

    Does NOT install SQL Server (install engine separately; use setup.php for DB/schema).

    Re-run safe: skips downloads that already exist unless -Force is specified.

.PARAMETER PhpVersion
    PHP version to install (default: 8.3.32). Must exist on windows.php.net releases.

.PARAMETER PhpInstallPath
    Extract path for PHP (default: C:\PHP).

.PARAMETER SiteName
    IIS site to attach the PHP handler to. Default "Default Web Site".
    Use "*" to configure at the server (global) level only.

.PARAMETER SitePhysicalPath
    IIS site root for WinDCIM. Default: C:\inetpub\wwwroot\WinDCIM.
    Pass empty string "" to skip site path changes, deploy, and NTFS grants.

.PARAMETER DeploySource
    Folder containing WinDCIM source (setup.php, index.php, ...).
    Default: parent of this script (...\scripts -> project root). Used to copy into SitePhysicalPath.

.PARAMETER SkipDeploy
    Do not copy application files (only install IIS/PHP and set the site path if present).

.PARAMETER SkipOdbc
    Do not install ODBC Driver 18 for SQL Server.

.PARAMETER SkipUrlRewrite
    Do not install IIS URL Rewrite.

.PARAMETER SkipSqlsrv
    Do not download Microsoft Drivers for PHP for SQL Server.

.PARAMETER Force
    Re-download and re-apply configuration even if components exist.
    Also re-copies application files over SitePhysicalPath (preserves config\config.php).

.EXAMPLE
    # Production server - Default Web Site -> C:\inetpub\wwwroot\WinDCIM
    .\Install-WinDCIM-Prereqs.ps1

.EXAMPLE
    .\Install-WinDCIM-Prereqs.ps1 -SitePhysicalPath 'C:\inetpub\wwwroot\WinDCIM'

.EXAMPLE
    .\Install-WinDCIM-Prereqs.ps1 -PhpVersion 8.2.28 -PhpInstallPath C:\PHP82 -Force
#>
[CmdletBinding()]
param(
    [string]$PhpVersion = '8.3.32',
    [string]$PhpInstallPath = 'C:\PHP',
    [string]$SiteName = 'Default Web Site',
    [string]$SitePhysicalPath = 'C:\inetpub\wwwroot\WinDCIM',
    [string]$DeploySource = '',
    [switch]$SkipDeploy,
    [switch]$SkipOdbc,
    [switch]$SkipUrlRewrite,
    [switch]$SkipSqlsrv,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue' # faster Invoke-WebRequest on older PS

function Write-Step([string]$Message) {
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Write-Ok([string]$Message) {
    Write-Host "    [OK] $Message" -ForegroundColor Green
}

function Write-Warn([string]$Message) {
    Write-Host "    [WARN] $Message" -ForegroundColor Yellow
}

# Native exes often write info to stderr. With $ErrorActionPreference=Stop, PowerShell
# turns those into terminating errors - wrap calls so install can continue.
function Invoke-NativeOutput {
    param(
        [Parameter(Mandatory)]
        [scriptblock]$Command
    )
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Command 2>&1
        $lines = @(
            $output | ForEach-Object {
                if ($_ -is [System.Management.Automation.ErrorRecord]) {
                    $_.ToString()
                } else {
                    "$_"
                }
            }
        )
        return $lines
    } finally {
        $ErrorActionPreference = $prev
    }
}

function Assert-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = [Security.Principal.WindowsPrincipal]::new($id)
    if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'This script must run as Administrator (elevated PowerShell).'
    }
}

function Test-CommandExists([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Ensure-Tls12 {
    try {
        [Net.ServicePointManager]::SecurityProtocol = `
            [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    } catch {
        # older .NET may not have enum; ignore
    }
}

function Download-File([string]$Uri, [string]$OutFile) {
    Ensure-Tls12
    Write-Host "    Downloading: $Uri"
    Write-Host "             to: $OutFile"
    if ((Test-Path $OutFile) -and -not $Force) {
        Write-Ok "Already present: $OutFile"
        return
    }
    $dir = Split-Path -Parent $OutFile
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    # Prefer BITS / curl on modern systems; fall back to IWR
    if (Test-CommandExists 'curl.exe') {
        & curl.exe -fsSL -L --retry 3 -o $OutFile $Uri
        if ($LASTEXITCODE -ne 0) { throw "curl download failed: $Uri" }
    } else {
        Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing
    }
    if (-not (Test-Path $OutFile) -or ((Get-Item $OutFile).Length -lt 1024)) {
        throw "Download failed or file too small: $OutFile"
    }
}

function Install-IisFeatures {
    Write-Step 'Installing IIS role services'

    $features = @(
        'Web-Server',
        'Web-Common-Http',
        'Web-Default-Doc',
        'Web-Dir-Browsing',
        'Web-Http-Errors',
        'Web-Static-Content',
        'Web-Http-Logging',
        'Web-Filtering',
        'Web-Stat-Compression',
        'Web-CGI',              # FastCGI
        'Web-Mgmt-Console'
    )

    if (Test-CommandExists 'Install-WindowsFeature') {
        # Windows Server
        $result = Install-WindowsFeature -Name $features -IncludeManagementTools
        if ($result.RestartNeeded -eq 'Yes') {
            Write-Warn 'A reboot may be required after feature install.'
        }
        Write-Ok 'IIS features installed (Server)'
    } elseif (Test-CommandExists 'Enable-WindowsOptionalFeature') {
        # Windows 10/11 client
        $clientMap = @{
            'IIS-WebServerRole'              = $true
            'IIS-WebServer'                  = $true
            'IIS-CommonHttpFeatures'         = $true
            'IIS-DefaultDocument'            = $true
            'IIS-DirectoryBrowsing'          = $true
            'IIS-HttpErrors'                 = $true
            'IIS-StaticContent'              = $true
            'IIS-HttpLogging'                = $true
            'IIS-RequestFiltering'           = $true
            'IIS-HttpCompressionStatic'      = $true
            'IIS-CGI'                        = $true
            'IIS-ManagementConsole'          = $true
        }
        foreach ($f in $clientMap.Keys) {
            Enable-WindowsOptionalFeature -Online -FeatureName $f -All -NoRestart | Out-Null
        }
        Write-Ok 'IIS features enabled (Client OS)'
    } else {
        throw 'Cannot install IIS features (Install-WindowsFeature / Enable-WindowsOptionalFeature not found).'
    }

    Import-Module WebAdministration -ErrorAction Stop
    Write-Ok 'WebAdministration module loaded'
}

function Install-VcRedist {
    Write-Step 'Visual C++ Redistributable (x64 2015-2022)'

    $regPaths = @(
        'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
    )
    $installed = $false
    foreach ($rp in $regPaths) {
        if (Test-Path $rp) {
            $maj = (Get-ItemProperty $rp -ErrorAction SilentlyContinue).Major
            if ($maj -ge 14) { $installed = $true; break }
        }
    }
    # Also check Apps & Features style uninstall keys
    if (-not $installed) {
        $u = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*' -ErrorAction SilentlyContinue |
            Where-Object { $_.DisplayName -match 'Visual C\+\+.*Redistributable.*x64' }
        if ($u) { $installed = $true }
    }

    if ($installed -and -not $Force) {
        Write-Ok 'VC++ Redistributable already installed'
        return
    }

    $vcUrl = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'
    $vcFile = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Download-File -Uri $vcUrl -OutFile $vcFile
    Write-Host '    Installing VC++ Redistributable (quiet)...'
    $p = Start-Process -FilePath $vcFile -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    # 0 = success, 1638 = already installed, 3010 = success reboot required
    if ($p.ExitCode -notin 0, 1638, 3010) {
        Write-Warn "VC++ installer exit code $($p.ExitCode) - PHP may still work if runtime present."
    } else {
        Write-Ok "VC++ Redistributable installed (exit $($p.ExitCode))"
    }
}

function Get-PhpDownloadUrl([string]$Version) {
    # Official release layout:
    # https://windows.php.net/downloads/releases/php-8.3.14-nts-Win32-vs16-x64.zip
    # Also try vs17 builds if vs16 missing.
    $candidates = @(
        "https://windows.php.net/downloads/releases/php-$Version-nts-Win32-vs16-x64.zip",
        "https://windows.php.net/downloads/releases/php-$Version-nts-Win32-vs17-x64.zip",
        "https://windows.php.net/downloads/releases/archives/php-$Version-nts-Win32-vs16-x64.zip",
        "https://windows.php.net/downloads/releases/archives/php-$Version-nts-Win32-vs17-x64.zip"
    )
    Ensure-Tls12
    foreach ($url in $candidates) {
        try {
            $resp = Invoke-WebRequest -Uri $url -Method Head -UseBasicParsing -TimeoutSec 20
            if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 400) {
                return $url
            }
        } catch {
            # try next
        }
    }
    throw @"
Could not find PHP $Version NTS x64 on windows.php.net.
Check https://windows.php.net/download/ for a valid version and pass -PhpVersion.
"@
}

function Install-Php {
    Write-Step "Installing PHP $PhpVersion (Non Thread Safe x64)"

    $phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
    if ((Test-Path $phpCgi) -and -not $Force) {
        Write-Ok "PHP already present at $PhpInstallPath"
    } else {
        $url = Get-PhpDownloadUrl -Version $PhpVersion
        $zip = Join-Path $env:TEMP "php-$PhpVersion-nts-x64.zip"
        Download-File -Uri $url -OutFile $zip

        if (Test-Path $PhpInstallPath) {
            if ($Force) {
                Write-Warn "Removing existing $PhpInstallPath"
                Remove-Item -Path $PhpInstallPath -Recurse -Force
            }
        }
        New-Item -ItemType Directory -Path $PhpInstallPath -Force | Out-Null
        Write-Host "    Extracting to $PhpInstallPath ..."
        Expand-Archive -Path $zip -DestinationPath $PhpInstallPath -Force
        Write-Ok "PHP extracted to $PhpInstallPath"
    }

    if (-not (Test-Path $phpCgi)) {
        throw "php-cgi.exe not found under $PhpInstallPath after extract."
    }

    # php.ini
    $iniPath = Join-Path $PhpInstallPath 'php.ini'
    $prodIni = Join-Path $PhpInstallPath 'php.ini-production'
    if (-not (Test-Path $iniPath) -or $Force) {
        if (-not (Test-Path $prodIni)) {
            throw "php.ini-production missing in $PhpInstallPath"
        }
        Copy-Item $prodIni $iniPath -Force
        Write-Ok 'Created php.ini from php.ini-production'
    }

    Configure-PhpIni -IniPath $iniPath
    Write-Ok 'php.ini configured for IIS / WinDCIM'

    # Smoke test (php may print non-fatal "Created directory: ..." on stderr)
    $phpExe = Join-Path $PhpInstallPath 'php.exe'
    $phpOut = Invoke-NativeOutput { & $phpExe -v }
    $ver = $phpOut | Where-Object { $_ -match 'PHP\s+\d' } | Select-Object -First 1
    if (-not $ver) { $ver = $phpOut | Select-Object -First 1 }
    if (-not $ver) {
        throw "php.exe -v produced no version output from $phpExe"
    }
    Write-Ok "php.exe reports: $ver"
    $noise = $phpOut | Where-Object { $_ -and $_ -ne $ver -and $_ -notmatch '^\s*$' }
    foreach ($n in $noise) {
        Write-Warn "php.exe: $n"
    }

    # PATH (machine) optional convenience
    $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    if ($machinePath -notlike "*$PhpInstallPath*") {
        [Environment]::SetEnvironmentVariable('Path', "$machinePath;$PhpInstallPath", 'Machine')
        $env:Path = "$env:Path;$PhpInstallPath"
        Write-Ok "Added $PhpInstallPath to system PATH"
    }
}

function Set-IniValue {
    param(
        [string]$Content,
        [string]$Name,
        [string]$Value,
        [switch]$IsExtension
    )
    if ($IsExtension) {
        # Uncomment extension=name or add it
        $pattern = "(?m)^\s*;?\s*extension\s*=\s*(`"?)$([regex]::Escape($Name))(`"?)\s*$"
        if ($Content -match $pattern) {
            return [regex]::Replace($Content, $pattern, "extension=$Name", 1)
        }
        return $Content.TrimEnd() + "`r`nextension=$Name`r`n"
    }

    $pattern = "(?m)^\s*;?\s*$([regex]::Escape($Name))\s*=.*$"
    if ($Content -match $pattern) {
        return [regex]::Replace($Content, $pattern, "$Name = $Value", 1)
    }
    return $Content.TrimEnd() + "`r`n$Name = $Value`r`n"
}

function Configure-PhpIni([string]$IniPath) {
    $c = Get-Content -Path $IniPath -Raw

    # Core IIS / FastCGI settings
    $c = Set-IniValue $c 'extension_dir' "`"$PhpInstallPath\ext`""
    $c = Set-IniValue $c 'cgi.fix_redirect' '0'
    $c = Set-IniValue $c 'cgi.fix_path_info' '1'
    $c = Set-IniValue $c 'fastcgi.impersonate' '1'
    $c = Set-IniValue $c 'cgi.rfc2616_headers' '0'
    $c = Set-IniValue $c 'date.timezone' 'UTC'
    $c = Set-IniValue $c 'expose_php' 'Off'
    $c = Set-IniValue $c 'display_errors' 'Off'
    $c = Set-IniValue $c 'log_errors' 'On'
    $c = Set-IniValue $c 'error_log' "`"$PhpInstallPath\php_errors.log`""
    $c = Set-IniValue $c 'upload_max_filesize' '64M'
    $c = Set-IniValue $c 'post_max_size' '64M'
    $c = Set-IniValue $c 'memory_limit' '256M'
    $c = Set-IniValue $c 'max_execution_time' '120'
    # Keep Windows temp paths explicit so PHP does not invent Unix-style dirs (e.g. c:/usr)
    $winTemp = Join-Path $env:SystemRoot 'Temp'
    $c = Set-IniValue $c 'session.save_path' "`"$winTemp`""
    $c = Set-IniValue $c 'sys_temp_dir' "`"$winTemp`""
    $c = Set-IniValue $c 'upload_tmp_dir' "`"$winTemp`""

    # Built-in extensions commonly needed
    foreach ($ext in @('curl', 'mbstring', 'openssl', 'fileinfo', 'gd', 'ldap', 'pdo_odbc')) {
        $c = Set-IniValue $c $ext -IsExtension
    }

    # snmp: DLL is often present, but Net-SNMP on Windows looks for Unix MIB paths
    # (c:/usr/share/snmp/mibs) and floods stderr. Leave disabled by default; WinDCIM
    # treats SNMP as optional. Enable later for scripts/poll_snmp.php if needed:
    #   extension=snmp
    # Optionally create C:\PHP\snmp\mibs and set snmp.mib_directory.
    $snmpDll = Join-Path $PhpInstallPath 'ext\php_snmp.dll'
    if (Test-Path $snmpDll) {
        # Ensure any prior enable is turned off for a clean IIS/CLI startup
        $c = [regex]::Replace($c, '(?m)^\s*extension\s*=\s*"?snmp"?\s*$', ';extension=snmp')
    }

    # sqlsrv if present
    foreach ($ext in @('pdo_sqlsrv', 'sqlsrv')) {
        $dll = Join-Path $PhpInstallPath "ext\php_$ext.dll"
        if (Test-Path $dll) {
            $c = Set-IniValue $c $ext -IsExtension
        }
    }

    Set-Content -Path $IniPath -Value $c -Encoding ASCII
}

function Install-IisPhpHandler {
    Write-Step 'Configuring IIS FastCGI + PHP handler'

    Import-Module WebAdministration -ErrorAction Stop

    $phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
    if (-not (Test-Path $phpCgi)) {
        throw "Missing $phpCgi"
    }

    # Full path with quotes for handler executable field
    $phpCgiQuoted = "`"$phpCgi`""

    # FastCGI application
    $fcgiPath = 'IIS:\Sites' # not used directly
    $existing = Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' `
        -Filter 'system.webServer/fastCgi/application' -ErrorAction SilentlyContinue |
        Where-Object { $_.fullPath -ieq $phpCgi }

    if (-not $existing) {
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi' -Value @{
            fullPath = $phpCgi
        }
        Write-Ok "Registered FastCGI application: $phpCgi"
    } else {
        Write-Ok 'FastCGI application already registered'
    }

    # Tune FastCGI settings
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' `
        -Filter "system.webServer/fastCgi/application[@fullPath='$phpCgi']" `
        -Name 'instanceMaxRequests' -Value 10000 -ErrorAction SilentlyContinue
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' `
        -Filter "system.webServer/fastCgi/application[@fullPath='$phpCgi']" `
        -Name 'activityTimeout' -Value 120 -ErrorAction SilentlyContinue
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' `
        -Filter "system.webServer/fastCgi/application[@fullPath='$phpCgi']" `
        -Name 'requestTimeout' -Value 120 -ErrorAction SilentlyContinue
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' `
        -Filter "system.webServer/fastCgi/application[@fullPath='$phpCgi']" `
        -Name 'monitorChangesTo' -Value (Join-Path $PhpInstallPath 'php.ini') -ErrorAction SilentlyContinue

    # Environment vars for FastCGI process
    $envFilter = "system.webServer/fastCgi/application[@fullPath='$phpCgi']/environmentVariables"
    $phpIniEnv = Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter $envFilter -ErrorAction SilentlyContinue |
        Where-Object { $_.name -eq 'PHPRC' }
    if (-not $phpIniEnv) {
        try {
            Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter $envFilter -Value @{
                name  = 'PHPRC'
                value = $PhpInstallPath
            }
            Write-Ok 'Set FastCGI PHPRC environment variable'
        } catch {
            Write-Warn "Could not set PHPRC env: $($_.Exception.Message)"
        }
    }

    # handlers / defaultDocument are locked by default (overrideModeDefault=Deny).
    # Unlock so site-level mappings and web.config work; also register globally.
    $appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
    function Unlock-IisSection([string]$Section) {
        # Prefer appcmd - reliable on Server for overrideModeDefault=Deny sections
        if (Test-Path $appcmd) {
            $out = Invoke-NativeOutput { & $appcmd unlock config /section:$Section }
            $joined = ($out -join ' ')
            if ($joined -match 'ERROR|failed|not found') {
                Write-Warn "appcmd unlock $Section : $joined"
            } else {
                Write-Ok "Unlocked IIS section: $Section"
                return
            }
        }
        try {
            Set-WebConfiguration -Filter $Section -PSPath 'MACHINE/WEBROOT/APPHOST' `
                -Metadata overrideMode -Value Allow -ErrorAction Stop
            Write-Ok "Unlocked IIS section (PowerShell): $Section"
        } catch {
            Write-Warn "Could not unlock $Section : $($_.Exception.Message)"
        }
    }
    Unlock-IisSection 'system.webServer/handlers'
    Unlock-IisSection 'system.webServer/defaultDocument'

    $handlerName = 'PHP_via_FastCGI'
    # Always ensure a server-level *.php mapping (reliable; not blocked by site locks).
    # Optionally also map at the named site when requested.
    $handlerTargets = @('MACHINE/WEBROOT/APPHOST')
    if ($SiteName -ne '*') {
        if (Test-Path "IIS:\Sites\$SiteName") {
            $handlerTargets += "IIS:\Sites\$SiteName"
        } else {
            Write-Warn "Site '$SiteName' not found - using global handler only."
        }
    }

    function Remove-PhpHandlers([string]$PsPath) {
        try {
            $handlers = Get-WebHandler -PSPath $PsPath -ErrorAction SilentlyContinue
            $old = $handlers | Where-Object {
                $_.Name -eq $handlerName -or
                ($_.Path -eq '*.php' -and ($_.ScriptProcessor -like '*php-cgi.exe*' -or $_.Name -like '*PHP*'))
            }
            foreach ($h in @($old)) {
                if ($h) {
                    Remove-WebHandler -Name $h.Name -PSPath $PsPath -ErrorAction SilentlyContinue
                }
            }
        } catch {
            Write-Warn "Handler cleanup ($PsPath): $($_.Exception.Message)"
        }
    }

    function Add-PhpHandler([string]$PsPath) {
        Remove-PhpHandlers -PsPath $PsPath
        try {
            New-WebHandler -Name $handlerName `
                -Path '*.php' `
                -Verb '*' `
                -Modules 'FastCgiModule' `
                -ScriptProcessor $phpCgi `
                -ResourceType 'Either' `
                -PSPath $PsPath | Out-Null
            Write-Ok "Handler mapping '$handlerName' -> $phpCgi ($PsPath)"
            return $true
        } catch {
            Write-Warn "New-WebHandler failed at $PsPath : $($_.Exception.Message)"
            return $false
        }
    }

    $handlerOk = $false
    foreach ($target in $handlerTargets) {
        if (Add-PhpHandler -PsPath $target) {
            $handlerOk = $true
        }
    }

    # Last-resort: appcmd global handler registration
    if (-not $handlerOk -and (Test-Path $appcmd)) {
        Write-Warn 'Falling back to appcmd for global PHP handler...'
        $null = Invoke-NativeOutput {
            & $appcmd set config /section:handlers "/-[name='$handlerName']"
        }
        $addOut = Invoke-NativeOutput {
            & $appcmd set config /section:handlers "/+[name='$handlerName',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='$phpCgi',resourceType='Either',requireAccess='Script']"
        }
        $verify = Get-WebHandler -PSPath 'MACHINE/WEBROOT/APPHOST' -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -eq $handlerName -or ($_.Path -eq '*.php' -and $_.ScriptProcessor -like '*php-cgi.exe*') }
        if ($verify) {
            $handlerOk = $true
            Write-Ok "Handler mapping '$handlerName' registered via appcmd"
        } else {
            Write-Warn ($addOut -join ' ')
        }
    }

    if (-not $handlerOk) {
        throw @"
Failed to register IIS PHP handler mapping for $phpCgi.
Unlock handlers manually, then re-run:
  %windir%\system32\inetsrv\appcmd.exe unlock config /section:system.webServer/handlers
Or add a Handler Mapping in IIS Manager: *.php -> FastCgiModule -> $phpCgi
"@
    }

    # Default document
    try {
        if ($SiteName -ne '*' -and (Test-Path "IIS:\Sites\$SiteName")) {
            $defs = Get-WebConfigurationProperty -PSPath "IIS:\Sites\$SiteName" `
                -Filter 'system.webServer/defaultDocument/files' -Name '.' |
                Select-Object -ExpandProperty Collection
            $names = @($defs | ForEach-Object { $_.value })
            foreach ($doc in @('index.php', 'setup.php')) {
                if ($names -notcontains $doc) {
                    Add-WebConfigurationProperty -PSPath "IIS:\Sites\$SiteName" `
                        -Filter 'system.webServer/defaultDocument/files' -Name '.' -Value @{ value = $doc }
                }
            }
            Write-Ok 'Default documents include index.php / setup.php'
        }
    } catch {
        Write-Warn "Default document config: $($_.Exception.Message)"
        if (Test-Path $appcmd) {
            foreach ($doc in @('index.php', 'setup.php')) {
                $null = Invoke-NativeOutput {
                    & $appcmd set config "$SiteName" /section:defaultDocument "/+files.[value='$doc']"
                }
            }
            Write-Ok 'Default documents set via appcmd'
        }
    }

    # Site physical path (create if missing - deploy may fill it)
    if ($SitePhysicalPath) {
        if (-not (Test-Path $SitePhysicalPath)) {
            New-Item -ItemType Directory -Path $SitePhysicalPath -Force | Out-Null
            Write-Ok "Created site directory: $SitePhysicalPath"
        }
        if ($SiteName -ne '*' -and (Test-Path "IIS:\Sites\$SiteName")) {
            Set-ItemProperty "IIS:\Sites\$SiteName" -Name physicalPath -Value $SitePhysicalPath
            Write-Ok "Site '$SiteName' physical path -> $SitePhysicalPath"
        }
    }

    # NTFS permissions for identities that may run PHP.
    # With fastcgi.impersonate=1 (common), PHP runs as the anonymous user (IUSR),
    # not the app pool. Grant Modify to app pool + IUSR + IIS_IUSRS.
    try {
        if ($SitePhysicalPath) {
            $identities = @('NT AUTHORITY\IUSR', 'IIS_IUSRS')
            if ($SiteName -ne '*' -and (Test-Path "IIS:\Sites\$SiteName")) {
                $pool = (Get-ItemProperty "IIS:\Sites\$SiteName").applicationPool
                if (-not $pool) { $pool = 'DefaultAppPool' }
                $identities = @("IIS AppPool\$pool") + $identities
            } else {
                $identities = @('IIS AppPool\DefaultAppPool') + $identities
            }
            foreach ($sub in @('config', 'storage', 'storage\logs', 'storage\uploads')) {
                $p = Join-Path $SitePhysicalPath $sub
                if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
                foreach ($identity in $identities) {
                    icacls $p /grant "${identity}:(OI)(CI)M" /T /Q | Out-Null
                }
            }
            Write-Ok "Granted Modify on config/ and storage/ to: $($identities -join ', ')"
        }
    } catch {
        Write-Warn "NTFS ACL grant failed: $($_.Exception.Message)"
    }

    # Restart IIS (iisreset often writes to stderr; ignore non-zero noise)
    $null = Invoke-NativeOutput { & iisreset.exe /noforce }
    Write-Ok 'IIS reset complete'
}

function Install-OdbcDriver18 {
    if ($SkipOdbc) {
        Write-Step 'Skipping ODBC Driver 18 (-SkipOdbc)'
        return
    }
    Write-Step 'ODBC Driver 18 for SQL Server'

    $existing = Get-OdbcDriver -Name 'ODBC Driver 18 for SQL Server' -ErrorAction SilentlyContinue
    if ($existing -and -not $Force) {
        Write-Ok 'ODBC Driver 18 already installed'
        return
    }

    # Direct MSI (x64) - Microsoft download CDN (stable aka link may change; pin msodbcsql)
    # Using the known aka.ms short link for latest x64.
    $msiUrl = 'https://go.microsoft.com/fwlink/?linkid=2249006'
    $msi = Join-Path $env:TEMP 'msodbcsql18.msi'
    try {
        Download-File -Uri $msiUrl -OutFile $msi
    } catch {
        Write-Warn "Could not download ODBC Driver 18 automatically: $($_.Exception.Message)"
        Write-Warn 'Install manually: https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server'
        return
    }

    Write-Host '    Installing ODBC Driver 18 (quiet)...'
    $args = "/i `"$msi`" /qn IACCEPTMSODBCSQLLICENSETERMS=YES /norestart"
    $p = Start-Process -FilePath 'msiexec.exe' -ArgumentList $args -Wait -PassThru
    if ($p.ExitCode -notin 0, 1638, 3010) {
        Write-Warn "ODBC installer exit code $($p.ExitCode)"
    } else {
        Write-Ok "ODBC Driver 18 installed (exit $($p.ExitCode))"
    }
}

function Install-UrlRewrite {
    if ($SkipUrlRewrite) {
        Write-Step 'Skipping URL Rewrite (-SkipUrlRewrite)'
        return
    }
    Write-Step 'IIS URL Rewrite Module 2'

    $installed = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite' -ErrorAction SilentlyContinue
    if (-not $installed) {
        $installed = Test-Path 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite\*'
    }
    # Also detect via globalModules
    $mod = Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue
    if ($mod -and -not $Force) {
        Write-Ok 'URL Rewrite already installed'
        return
    }

    # Microsoft download - rewrite_amd64_en-US.msi
    $url = 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi'
    $msi = Join-Path $env:TEMP 'rewrite_amd64_en-US.msi'
    try {
        Download-File -Uri $url -OutFile $msi
        $p = Start-Process msiexec.exe -ArgumentList "/i `"$msi`" /qn /norestart" -Wait -PassThru
        if ($p.ExitCode -notin 0, 1638, 3010) {
            Write-Warn "URL Rewrite exit code $($p.ExitCode) - try manual install from iis.net"
        } else {
            Write-Ok "URL Rewrite installed (exit $($p.ExitCode))"
        }
    } catch {
        Write-Warn "URL Rewrite download/install failed: $($_.Exception.Message)"
        Write-Warn 'Manual: https://www.iis.net/downloads/microsoft/url-rewrite'
    }
}

function Install-PhpSqlsrvDrivers {
    if ($SkipSqlsrv) {
        Write-Step 'Skipping PHP sqlsrv drivers (-SkipSqlsrv)'
        return
    }
    Write-Step 'Microsoft Drivers for PHP for SQL Server (optional)'

    # Map major.minor for Windows DLLs (NTS x64)
    # Releases: https://github.com/Microsoft/msphpsql/releases
    # We download the Windows zip for the closest supported PHP version.
    $majorMinor = ($PhpVersion -split '\.')[0..1] -join '.'
    # Known asset naming: Windows-8.3.zip contains php_pdo_sqlsrv_83_nts_x64.dll etc.
    $tagCandidates = @(
        'v5.12.0',
        'v5.11.1',
        'v5.11.0'
    )

    $extDir = Join-Path $PhpInstallPath 'ext'
    $already = Test-Path (Join-Path $extDir 'php_pdo_sqlsrv.dll')
    if ($already -and -not $Force) {
        Write-Ok 'php_pdo_sqlsrv.dll already in ext\'
        Configure-PhpIni -IniPath (Join-Path $PhpInstallPath 'php.ini')
        return
    }

    $downloaded = $false
    foreach ($tag in $tagCandidates) {
        # GitHub release asset pattern varies; try Windows-<mm>.zip
        $assetNames = @(
            "Windows-$majorMinor.zip",
            "Windows-$($majorMinor.Replace('.', '')).zip"
        )
        foreach ($asset in $assetNames) {
            $uri = "https://github.com/microsoft/msphpsql/releases/download/$tag/$asset"
            $zip = Join-Path $env:TEMP "msphpsql-$tag-$asset"
            try {
                Download-File -Uri $uri -OutFile $zip
                $extract = Join-Path $env:TEMP "msphpsql-extract-$tag"
                if (Test-Path $extract) { Remove-Item $extract -Recurse -Force }
                Expand-Archive -Path $zip -DestinationPath $extract -Force

                # Find NTS x64 DLLs matching PHP version
                $verToken = $majorMinor.Replace('.', '') # 83
                $pdo = Get-ChildItem -Path $extract -Recurse -Filter "php_pdo_sqlsrv_*nts*x64.dll" -ErrorAction SilentlyContinue |
                    Where-Object { $_.Name -match $verToken -or $_.Name -match $majorMinor } |
                    Select-Object -First 1
                if (-not $pdo) {
                    $pdo = Get-ChildItem -Path $extract -Recurse -Filter 'php_pdo_sqlsrv_*nts*x64.dll' |
                        Select-Object -First 1
                }
                $sql = Get-ChildItem -Path $extract -Recurse -Filter "php_sqlsrv_*nts*x64.dll" -ErrorAction SilentlyContinue |
                    Where-Object { $_.Name -match $verToken } | Select-Object -First 1
                if (-not $sql) {
                    $sql = Get-ChildItem -Path $extract -Recurse -Filter 'php_sqlsrv_*nts*x64.dll' |
                        Select-Object -First 1
                }

                if ($pdo) {
                    Copy-Item $pdo.FullName (Join-Path $extDir 'php_pdo_sqlsrv.dll') -Force
                    Write-Ok "Installed $($pdo.Name) as php_pdo_sqlsrv.dll"
                    $downloaded = $true
                }
                if ($sql) {
                    Copy-Item $sql.FullName (Join-Path $extDir 'php_sqlsrv.dll') -Force
                    Write-Ok "Installed $($sql.Name) as php_sqlsrv.dll"
                }
                if ($downloaded) { break }
            } catch {
                # try next asset/tag
            }
        }
        if ($downloaded) { break }
    }

    if (-not $downloaded) {
        Write-Warn 'Could not auto-install pdo_sqlsrv. ODBC (pdo_odbc) is enough for WinDCIM.'
        Write-Warn 'Manual: https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server'
    } else {
        Configure-PhpIni -IniPath (Join-Path $PhpInstallPath 'php.ini')
        Write-Ok 'php.ini updated with sqlsrv extensions'
    }
}

function Get-WinDcimDeploySource {
    if ($DeploySource) {
        return (Resolve-Path -LiteralPath $DeploySource).Path
    }
    # This script lives in <project>\scripts\
    $candidate = Split-Path -Parent $PSScriptRoot
    if (-not $candidate) {
        $candidate = (Get-Location).Path
    }
    return $candidate
}

function Test-WinDcimRoot([string]$Path) {
    if (-not $Path -or -not (Test-Path $Path)) { return $false }
    return (Test-Path (Join-Path $Path 'setup.php')) -and (Test-Path (Join-Path $Path 'index.php'))
}

function Deploy-WinDcimApp {
    if (-not $SitePhysicalPath) {
        Write-Step 'Skipping deploy (no SitePhysicalPath)'
        return
    }
    if ($SkipDeploy) {
        Write-Step 'Skipping application deploy (-SkipDeploy)'
        if (-not (Test-WinDcimRoot $SitePhysicalPath)) {
            Write-Warn "Site path has no setup.php/index.php yet: $SitePhysicalPath"
            Write-Warn 'Copy WinDCIM files there, or re-run without -SkipDeploy.'
        }
        return
    }

    Write-Step "Deploying WinDCIM to $SitePhysicalPath"

    $source = Get-WinDcimDeploySource
    if (-not (Test-WinDcimRoot $source)) {
        throw @"
Deploy source does not look like a WinDCIM root (missing setup.php / index.php):
  $source
Pass -DeploySource path\to\WinDCIM or run this script from the project scripts folder.
"@
    }

    # Avoid copying the tree onto itself
    $srcFull = [System.IO.Path]::GetFullPath($source).TrimEnd('\')
    $dstFull = [System.IO.Path]::GetFullPath($SitePhysicalPath).TrimEnd('\')
    if ($srcFull -ieq $dstFull) {
        Write-Ok "Source and site path are the same ($dstFull) - no copy needed"
        return
    }

    if (-not (Test-Path $SitePhysicalPath)) {
        New-Item -ItemType Directory -Path $SitePhysicalPath -Force | Out-Null
    }

    $alreadyDeployed = Test-WinDcimRoot $SitePhysicalPath
    if ($alreadyDeployed -and -not $Force) {
        Write-Ok "WinDCIM already present at $SitePhysicalPath (use -Force to refresh files)"
        return
    }

    # Preserve generated config across re-deploy
    $configFile = Join-Path $SitePhysicalPath 'config\config.php'
    $configBackup = $null
    if (Test-Path $configFile) {
        $configBackup = Join-Path $env:TEMP ("windcim-config-backup-{0}.php" -f [guid]::NewGuid().ToString('N'))
        Copy-Item $configFile $configBackup -Force
        Write-Ok 'Backed up existing config\config.php'
    }

    # Exclude VCS, local secrets, and runtime storage contents (dirs recreated later)
    $excludeDirs = @('.git', '.vs', '.idea', 'node_modules')
    $excludeFiles = @('phpinfo-test.php')

    if (Test-CommandExists 'robocopy.exe') {
        $xd = @()
        foreach ($d in $excludeDirs) { $xd += @('/XD', $d) }
        $xf = @()
        foreach ($f in $excludeFiles) { $xf += @('/XF', $f) }
        # /E copy subdirs incl empty; /NFL /NDL quiet-ish; /R:2 /W:2 retries
        # Exit codes 0-7 are success for robocopy
        $rcArgs = @($srcFull, $dstFull, '/E', '/R:2', '/W:2', '/NFL', '/NDL', '/NJH', '/NJS') + $xd + $xf
        & robocopy.exe @rcArgs | Out-Null
        $code = $LASTEXITCODE
        if ($code -ge 8) {
            throw "robocopy failed with exit code $code (source=$srcFull dest=$dstFull)"
        }
        Write-Ok "Copied application files (robocopy exit $code)"
    } else {
        Get-ChildItem -Path $srcFull -Force | ForEach-Object {
            if ($excludeDirs -contains $_.Name) { return }
            if ($excludeFiles -contains $_.Name) { return }
            $destItem = Join-Path $dstFull $_.Name
            if ($_.PSIsContainer) {
                Copy-Item -Path $_.FullName -Destination $destItem -Recurse -Force
            } else {
                Copy-Item -Path $_.FullName -Destination $destItem -Force
            }
        }
        Write-Ok 'Copied application files (Copy-Item)'
    }

    if ($configBackup -and (Test-Path $configBackup)) {
        $configDir = Join-Path $SitePhysicalPath 'config'
        if (-not (Test-Path $configDir)) {
            New-Item -ItemType Directory -Path $configDir -Force | Out-Null
        }
        Copy-Item $configBackup $configFile -Force
        Remove-Item $configBackup -Force -ErrorAction SilentlyContinue
        Write-Ok 'Restored config\config.php'
    }

    if (-not (Test-WinDcimRoot $SitePhysicalPath)) {
        throw "Deploy finished but $SitePhysicalPath still missing setup.php / index.php"
    }
    Write-Ok "WinDCIM ready at $SitePhysicalPath"
}

function Write-PhpInfoTest {
    if (-not $SitePhysicalPath) { return }
    $info = Join-Path $SitePhysicalPath 'phpinfo-test.php'
    if (-not (Test-Path $info) -or $Force) {
        Set-Content -Path $info -Value '<?php phpinfo();' -Encoding ASCII
        Write-Ok "Wrote $info - delete after verifying PHP works"
    }
}

function Show-Summary {
    Write-Step 'Summary'
    $phpCgi = Join-Path $PhpInstallPath 'php-cgi.exe'
    $phpExe = Join-Path $PhpInstallPath 'php.exe'
    $siteLine = if ($SitePhysicalPath) { $SitePhysicalPath } else { '(not set - site path unchanged)' }
    $setupOk = if ($SitePhysicalPath -and (Test-Path (Join-Path $SitePhysicalPath 'setup.php'))) { 'yes' } else { 'no' }
    Write-Host @"

  PHP path:        $PhpInstallPath
  php-cgi.exe:     $(Test-Path $phpCgi)
  php.ini:         $(Test-Path (Join-Path $PhpInstallPath 'php.ini'))
  Site path:       $siteLine
  setup.php:       $setupOk
  IIS site:        $SiteName

  Verify:
    1.  & '$phpExe' -m
        (should list curl, mbstring, openssl, pdo_odbc; optional ldap, pdo_sqlsrv)
    2.  Browse http://localhost/phpinfo-test.php
    3.  Browse http://localhost/setup.php         (WinDCIM web installer)
    4.  Delete phpinfo-test.php when done

  SQL Server:
    - Not installed by this script (you already have the engine).
    - In setup.php use host . or localhost (or HOST\INSTANCE),
      SQL auth with sa (or a dedicated login), and a new DB name e.g. WinDCIM.

  Notes:
    - Use HTTPS + certificate before enabling Entra SSO.
    - Firewall: allow 80/443 inbound; SQL 1433 if remote; LDAPS 636 outbound.
    - If PHP version download 404s, set -PhpVersion to a build listed on
      https://windows.php.net/download/
    - SNMP poll later:
        Program:  $PhpInstallPath\php.exe
        Args:     $siteLine\scripts\poll_snmp.php

"@
}

# -------------------- main --------------------
Assert-Admin
Write-Host 'WinDCIM prerequisite installer' -ForegroundColor White
Write-Host "PHP $PhpVersion NTS x64 -> $PhpInstallPath" -ForegroundColor DarkGray
if ($SitePhysicalPath) {
    Write-Host "Site path: $SitePhysicalPath (IIS: $SiteName)" -ForegroundColor DarkGray
}

Install-IisFeatures
Install-VcRedist
Install-Php
Install-OdbcDriver18
Install-UrlRewrite
Install-PhpSqlsrvDrivers
Deploy-WinDcimApp
Install-IisPhpHandler
Write-PhpInfoTest
Show-Summary

Write-Host 'Done.' -ForegroundColor Green

