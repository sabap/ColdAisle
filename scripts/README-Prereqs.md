# WinDCIM prerequisite installer (PowerShell)

Script: **`Install-WinDCIM-Prereqs.ps1`**

Automates:

| Step | Action |
|------|--------|
| IIS | Web Server + CGI/FastCGI, static content, request filtering, management console |
| VC++ | Visual C++ 2015–2022 x64 redistributable |
| PHP | Downloads **NTS x64** zip from windows.php.net, extracts, builds `php.ini` |
| IIS PHP | FastCGI app + `*.php` handler mapping + default docs |
| ODBC | ODBC Driver 18 for SQL Server (for `pdo_odbc`) |
| URL Rewrite | IIS URL Rewrite 2 (for `web.config` rules) |
| sqlsrv | Best-effort install of Microsoft PHP SQL drivers (optional; ODBC is enough) |
| Deploy | Copies WinDCIM from the project folder into the site path (default `C:\inetpub\wwwroot\WinDCIM`) |
| NTFS | Grants app pool Modify on `config\` and `storage\` |

Does **not** install SQL Server. Use an existing instance (Express/Standard/etc.) and complete DB setup in the browser wizard.

## Run (elevated)

Copy the whole WinDCIM project onto the server (any temp path is fine), open **PowerShell as Administrator**:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force
cd C:\path\to\WinDCIM\scripts

# Production defaults: site -> C:\inetpub\wwwroot\WinDCIM, PHP -> C:\PHP
.\Install-WinDCIM-Prereqs.ps1
```

The script will:

1. Install IIS + PHP + ODBC + URL Rewrite  
2. Copy app files into `C:\inetpub\wwwroot\WinDCIM` (if not already there)  
3. Point **Default Web Site** at that folder  
4. Grant the app pool write access to `config\` and `storage\`

### Common parameters

```powershell
# Explicit production path (same as default)
.\Install-WinDCIM-Prereqs.ps1 -SitePhysicalPath 'C:\inetpub\wwwroot\WinDCIM'

# Pin PHP version (must exist on windows.php.net)
.\Install-WinDCIM-Prereqs.ps1 -PhpVersion 8.3.32

# Custom PHP folder / site name
.\Install-WinDCIM-Prereqs.ps1 `
  -PhpInstallPath C:\PHP `
  -SiteName 'Default Web Site' `
  -SitePhysicalPath 'C:\inetpub\wwwroot\WinDCIM'

# Source is elsewhere; only install stack, do not copy files
.\Install-WinDCIM-Prereqs.ps1 -SkipDeploy -SitePhysicalPath 'C:\inetpub\wwwroot\WinDCIM'

# Re-download / overwrite PHP config and refresh site files
# (existing config\config.php is preserved)
.\Install-WinDCIM-Prereqs.ps1 -Force

# Skip optional components
.\Install-WinDCIM-Prereqs.ps1 -SkipSqlsrv -SkipUrlRewrite
```

### Parameters that matter on a real server

| Parameter | Default | Notes |
|-----------|---------|--------|
| `-SitePhysicalPath` | `C:\inetpub\wwwroot\WinDCIM` | IIS site root. Pass `""` to leave the site path alone. |
| `-SiteName` | `Default Web Site` | Site that receives the PHP handler and physical path. |
| `-DeploySource` | Parent of `scripts\` | Where to copy app files from. |
| `-SkipDeploy` | off | Use when files are already under inetpub and you only need the stack. |
| `-PhpVersion` | `8.3.32` | Change if the zip 404s on windows.php.net. |
| `-PhpInstallPath` | `C:\PHP` | FastCGI points here. |

## After the script

1. `php -m` — confirm modules (`pdo_odbc` and/or `pdo_sqlsrv`, `curl`, `mbstring`, `openssl`)  
2. Open `http://localhost/phpinfo-test.php` — then **delete** that file  
3. Open `http://localhost/setup.php` — WinDCIM web installer  
4. SQL connection (engine must already be installed):
   - Host: `.` or `localhost` (or `HOSTNAME\INSTANCE`)  
   - Auth: SQL login (e.g. `sa`) or Windows auth if configured  
   - Database name: e.g. `WinDCIM` (wizard can create it)  
5. Create the first administrator account in the wizard (no fixed default password)

## What is *not* fully automated

| Item | Reason |
|------|--------|
| **SQL Server engine** | Large product; install Express/Standard separately or use an existing instance |
| **TLS certificate** | Org-specific (AD CS, Let’s Encrypt, commercial CA) |
| **Exact PHP patch version forever** | Default `-PhpVersion` may need updating when builds move to `/archives/` |
| **Entra app registration** | Azure portal / Graph — tenant-specific secrets |

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| PHP zip 404 | Check [windows.php.net/download](https://windows.php.net/download/) and pass `-PhpVersion 8.x.y` |
| Handler 500.0 | Confirm CGI role feature; check `C:\PHP\php_errors.log` |
| `pdo_sqlsrv` missing | Rely on **ODBC Driver 18** + `pdo_odbc` (WinDCIM supports both) |
| URL Rewrite failed | Install manually from [iis.net URL Rewrite](https://www.iis.net/downloads/microsoft/url-rewrite) |
| App cannot write config | Re-run with default site path, or grant `IIS AppPool\<pool>` Modify on `config` & `storage` |
| Wrong document root | Confirm Default Web Site physical path is `C:\inetpub\wwwroot\WinDCIM` (not plain `wwwroot`) |
