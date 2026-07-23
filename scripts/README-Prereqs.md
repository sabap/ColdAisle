# ColdAisle Windows installers (PowerShell)

All scripts are plain text — download, open in Notepad, then run elevated. Nothing is hidden.

| Script | Role |
|--------|------|
| **[`Install-ColdAisle.ps1`](../Install-ColdAisle.ps1)** (repo root) | **Recommended for new servers:** download latest public release from GitHub, then install IIS/PHP/ODBC and deploy |
| **`Install-ColdAisle-Prereqs.ps1`** (this folder) | Platform stack only (or deploy from a local tree / `-FromGitHub`) |
| `Install-WinDCIM-Prereqs.ps1` | Legacy wrapper → calls `Install-ColdAisle-Prereqs.ps1` |

## Quick install (new machine, public GitHub)

**PowerShell as Administrator:**

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force
Invoke-WebRequest -Uri "https://raw.githubusercontent.com/sabap/ColdAisle/main/Install-ColdAisle.ps1" `
  -OutFile .\Install-ColdAisle.ps1
# Optional: review the script
notepad .\Install-ColdAisle.ps1
.\Install-ColdAisle.ps1 -OpenSetup
```

`-OpenSetup` opens the setup wizard in your browser after checks pass.

Then complete `setup.php` for SQL + admin account.

Does **not** install SQL Server. Use Express/Standard/Enterprise or an existing instance.

### Tested platforms

- **Windows Server 2025** (also aimed at Server 2019/2022 and Windows 10/11)
- **SQL Server 2022 Enterprise** (Express/Standard fine for typical installs)

## What `Install-ColdAisle-Prereqs.ps1` automates

| Step | Action |
|------|--------|
| IIS | Web Server + CGI/FastCGI, static content, request filtering, management console |
| VC++ | Visual C++ 2015–2022 x64 redistributable |
| PHP | Downloads **NTS x64** zip from windows.php.net, extracts, builds `php.ini` |
| IIS PHP | FastCGI app + `*.php` handler mapping + default docs |
| ODBC | ODBC Driver 18 for SQL Server (for `pdo_odbc`) |
| URL Rewrite | IIS URL Rewrite 2 (for `web.config` rules) |
| sqlsrv | Best-effort install of Microsoft PHP SQL drivers (optional; ODBC is enough) |
| Deploy | Copies ColdAisle into the site path (default `C:\inetpub\wwwroot\ColdAisle`) |
| NTFS | Grants app pool Modify on `config\` and `storage\` |

## Run prereqs only (local source tree)

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force
cd C:\path\to\ColdAisle\scripts
.\Install-ColdAisle-Prereqs.ps1
```

Or pull the app from GitHub while using the prereq script:

```powershell
.\Install-ColdAisle-Prereqs.ps1 -FromGitHub
.\Install-ColdAisle-Prereqs.ps1 -FromGitHub -Version 0.2.0
```
### Common parameters

```powershell
# Explicit production path (same as default)
.\Install-ColdAisle-Prereqs.ps1 -SitePhysicalPath 'C:\inetpub\wwwroot\ColdAisle'

# Pin PHP version (must exist on windows.php.net)
.\Install-ColdAisle-Prereqs.ps1 -PhpVersion 8.3.32

# Custom PHP folder / site name
.\Install-ColdAisle-Prereqs.ps1 `
  -PhpInstallPath C:\PHP `
  -SiteName 'Default Web Site' `
  -SitePhysicalPath 'C:\inetpub\wwwroot\ColdAisle'

# Source is elsewhere; only install stack, do not copy files
.\Install-ColdAisle-Prereqs.ps1 -SkipDeploy -SitePhysicalPath 'C:\inetpub\wwwroot\ColdAisle'

# Re-download / overwrite PHP config and refresh site files
# (existing config\config.php is preserved)
.\Install-ColdAisle-Prereqs.ps1 -Force

# Skip optional components
.\Install-ColdAisle-Prereqs.ps1 -SkipSqlsrv -SkipUrlRewrite

# Pull from GitHub and open setup when finished
.\Install-ColdAisle-Prereqs.ps1 -FromGitHub -OpenSetup
```

### Parameters that matter on a real server

| Parameter | Default | Notes |
|-----------|---------|--------|
| `-SitePhysicalPath` | `C:\inetpub\wwwroot\ColdAisle` | IIS site root. Pass `""` to leave the site path alone. |
| `-SiteName` | `Default Web Site` | Site that receives the PHP handler and physical path. |
| `-DeploySource` | Parent of `scripts\` | Where to copy app files from. |
| `-FromGitHub` | off | Download app from public GitHub instead of local tree. |
| `-OpenSetup` | off | Open `http://localhost/setup.php` after install. |
| `-RunVerification` | on | PHP/IIS/site post-checks. |
| `-SkipDeploy` | off | Use when files are already under inetpub and you only need the stack. |
| `-PhpVersion` | `8.3.32` | Change if the zip 404s on windows.php.net. |
| `-PhpInstallPath` | `C:\PHP` | FastCGI points here. |

### Installer checks (gotchas covered)

Preflight / postflight try to catch common failures:

- Not elevated (Administrator required)
- PowerShell too old / non-x64
- Low disk space, very long install paths
- No outbound HTTPS to GitHub / windows.php.net
- Missing `php.exe` / `php-cgi.exe`
- Missing PHP modules (`curl`, `mbstring`, `openssl`, PDO, SQL drivers)
- Missing `setup.php` / `config` / `storage` dirs
- IIS site physical path mismatch
- Optional HTTP smoke test to `setup.php`
- Existing `config\config.php` preserved (called out in logs)

## After the script

1. `php -m` — confirm modules (`pdo_odbc` and/or `pdo_sqlsrv`, `curl`, `mbstring`, `openssl`)  
2. Open `http://localhost/phpinfo-test.php` — then **delete** that file  
3. Open `http://localhost/setup.php` — ColdAisle web installer  
4. SQL connection (engine must already be installed):
   - Host: `.` or `localhost` (or `HOSTNAME\INSTANCE`)  
   - Auth: SQL login (e.g. `sa`) or Windows auth if configured  
   - Database name: e.g. `ColdAisle` (wizard can create it)  
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
| `pdo_sqlsrv` missing | Rely on **ODBC Driver 18** + `pdo_odbc` (ColdAisle supports both) |
| URL Rewrite failed | Install manually from [iis.net URL Rewrite](https://www.iis.net/downloads/microsoft/url-rewrite) |
| App cannot write config | Re-run with default site path, or grant `IIS AppPool\<pool>` Modify on `config` & `storage` |
| Wrong document root | Confirm Default Web Site physical path is `C:\inetpub\wwwroot\ColdAisle` (not plain `wwwroot`) |
