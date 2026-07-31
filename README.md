# ColdAisle

**Data Center Infrastructure Management** — free & open source.  
Primary platform: **IIS + PHP + Microsoft SQL Server** on Windows (with a clean path toward other stacks later).

Formerly known as **WinDCIM**. Built as a modern replacement path for environments that outgrew or cannot maintain Linux-based [openDCIM](https://github.com/opendcim/openDCIM), with first-class support for local accounts, **LDAPS**, and **Microsoft Entra ID (Azure AD) SSO**.

**Current version:** see [`VERSION`](VERSION).  
**Release notes:** [`CHANGELOG.md`](CHANGELOG.md) (New features · Enhancements · Bug fixes).

## Support / donate

ColdAisle is free to use. If it helps your datacenter and you want to support development, optional donations via PayPal are welcome (no paywall, no accounts):

**[Donate with PayPal](https://paypal.me/mattelsberry)**

In a running install: **Settings → Support ColdAisle**, or the footer **Donate** link (always available).

## Updates (GitHub)

Admins can check for new versions and apply them from **Settings → Updates** against this public repo — **no GitHub token required**.

1. **Check for updates** · when a newer tag exists, **Update to vX.Y.Z** creates a pre-update recovery zip under `storage/backups/`, downloads the release zipball, preserves `config` & `storage` runtime data, and runs schema ensure.
2. Optional PAT only if you hit API rate limits or point updates at a private fork.
3. **Storage housekeeping** (Settings) prunes old pre-update / site-export zips, temp work dirs, and oversized logs (keep-last-N + max age).

Dashboard shows a banner when an update is available (if auto-check is enabled).

Each tagged release should have categorized notes in [`CHANGELOG.md`](CHANGELOG.md) and on [GitHub Releases](https://github.com/sabap/ColdAisle/releases). Maintainers: add bullets under **[Unreleased]** while coding, then run [`scripts/Release-ColdAisle.ps1`](scripts/Release-ColdAisle.ps1) (see [docs/RELEASING.md](docs/RELEASING.md)).

## Features

| Area | Capabilities |
|------|----------------|
| **Auth** | Local passwords, LDAPS (Active Directory, nested groups), Microsoft Entra OIDC SSO |
| **Setup** | Browser wizard creates DB, schema, and admin account; optional **restore from site backup** |
| **Dashboard** | Capacity metrics + interactive **3D** rack floor view (Three.js) |
| **Floor planner** | Drag-and-drop cabinets (and row PDUs) onto room canvas; 2D plan + 3D toggle |
| **Cabinets** | Rectangular models by width/depth (mm) and U-height; rack elevation with U-mounted PDUs |
| **Devices** | Manual entry, U-slot assignment, conflict checks, interfaces, power-supply lines → outlets |
| **Power** | Power zones (A/B), panels, row/rack PDUs, multi-phase (1/2/3), outlet inventory, PDU templates |
| **Cabling** | Port-to-port cables, media types, cable tray/underfloor routes |
| **SNMP** | SNMPv2c/v3, encrypted secrets at rest, MIB upload, Discover + site OID templates, phase & outlet telemetry, scheduled poll worker |
| **Power ops** | Near/overload alerts with batched email digests, short-term power history + charts, CSV/reports |
| **Updates & backup** | One-click GitHub update with pre-update zip, site export/restore packages, storage housekeeping |
| **Lifecycle** | Disposal workflow with notifications |
| **Audits** | Physical audit jobs + system audit trail |
| **Reports** | Inventory, utilization, power, warranty, cables, orphans, audits |
| **RBAC** | Administrator, Operator, Auditor, Viewer roles |

## Requirements

- **Windows Server** (or Windows 10/11) with **IIS**
- **PHP 8.0+** (FastCGI) with extensions: `pdo`, `json`, `mbstring`
  - Database: `pdo_sqlsrv` **or** `pdo_odbc` + **ODBC Driver 17/18 for SQL Server**
  - Optional: `ldap` (LDAPS), `snmp` (web Discover / in-app SNMP), `curl` (Entra SSO + updates), `zip` (backups/updates)
- **Microsoft SQL Server** 2016+ (Express is fine)

The **scheduled poll worker** does not require `extension=snmp` in `php.ini` (it launches via `scripts/run_poll_snmp.cmd` with `php -n` and loads `php_snmp.dll` explicitly to avoid Windows Net-SNMP MIB hangs). The DLL must still be present under `C:\PHP\ext\`.

### Tested platforms (as of this writing)

| Component | Tested |
|-----------|--------|
| OS | **Windows Server 2025** (also intended for Server 2019/2022 and Windows 10/11) |
| Database | **SQL Server 2022 Enterprise** (Express / Standard expected to work the same for app use) |
| Web | IIS + PHP 8.x NTS (FastCGI) via the public installer scripts |

## Install (Windows — recommended)

Scripts are plain PowerShell so you can read them before running. **Administrator** PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force
Invoke-WebRequest -Uri "https://raw.githubusercontent.com/sabap/ColdAisle/main/Install-ColdAisle.ps1" `
  -OutFile .\Install-ColdAisle.ps1
# Optional: review
notepad .\Install-ColdAisle.ps1
.\Install-ColdAisle.ps1 -OpenSetup
```

Useful switches:

| Switch | Purpose |
|--------|---------|
| `-OpenSetup` | Open `setup.php` after post-install checks |
| `-EnableSnmp` | Enable PHP `extension=snmp` for **web** Discover (optional; see SNMP notes) |
| `-RegisterSnmpTask` | Register the Windows Task Scheduler poll job (elevated; uses `run_poll_snmp.cmd`) |
| `-Force` | Refresh app files / PHP components (preserves `config\config.php`) |
| `-Version 0.2.x` | Pin a release tag instead of “latest” |

This downloads the **latest release tag** from this public repo, installs IIS/PHP/ODBC as needed, deploys to  
`C:\inetpub\wwwroot\ColdAisle` (default), then you finish in the browser:

1. Install/configure **SQL Server** if you do not already have an instance  
2. Complete **`setup.php`** — SQL connection + first admin  
3. Delete `phpinfo-test.php` if present  
4. Optional: **Settings → SNMP schedule** (or re-run installer with `-RegisterSnmpTask`) for background polling  

Details and parameters: [`scripts/README-Prereqs.md`](scripts/README-Prereqs.md).

### Suggested PHP (Windows) packages (manual)

1. Install [PHP for Windows](https://windows.php.net/download/)
2. Install [Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server) (`php_pdo_sqlsrv.dll`)
3. Or install [ODBC Driver 18 for SQL Server](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server) and enable `pdo_odbc`

## Site backup / restore (migration)

On a **working** site (admin):

1. **Settings → Site backup & migration → Download site backup**
2. Keep the ZIP private (users, password hashes, encrypted SNMP secrets, and `app_key`)

On a **new** install:

1. Run the platform installer as usual, open **setup.php**
2. Choose **Restore from backup**
3. Enter the **new** SQL connection details and upload the ZIP
4. Sign in with an account from the backup (not a new setup admin)

The package includes database rows, `storage/uploads`, config overlay (auth/security), and `app_key`. It does **not** include the old SQL password.

## Quick install

1. On the IIS/SQL server, open elevated PowerShell in this repo’s `scripts\` folder and run:
   ```powershell
   .\Install-ColdAisle-Prereqs.ps1
   ```
   Defaults: deploys to `C:\inetpub\wwwroot\ColdAisle`, points **Default Web Site** there, installs PHP/IIS/ODBC, and grants app-pool write on `config\` / `storage\`.
2. (Manual alternative) Copy this folder to `C:\inetpub\wwwroot\ColdAisle`, install PHP FastCGI yourself, grant **IIS AppPool identity** Modify on `config\` and `storage\`.
3. Browse to `http://your-host/setup.php`
4. Complete the wizard:
   - SQL host (e.g. `.` / `localhost`), credentials (`sa` or dedicated login), database name
   - Organization / site / DC names
   - **Administrator account** (you choose username/password)
5. Sign in at `login.php`
6. Delete `phpinfo-test.php` if the prereq script created it.

> There is **no fixed default password**. The admin account is created only during web setup with credentials you provide (suggested username: `admin`).

## IIS notes

- `web.config` blocks direct web access to `config/`, `src/`, `sql/`, `storage/`, `scripts/`.
- Install the [IIS URL Rewrite](https://www.iis.net/downloads/microsoft/url-rewrite) module for those rewrite rules (optional but recommended).
- For HTTPS (required for Entra SSO in production): obtain a certificate (org PKI / public CA), add an **IIS site binding** for `https` on 443 with that cert, open the site with `https://…` in a browser, then enable **Settings → Security → Force HTTPS** (and optionally HSTS).
- **Public site URL** in setup/Settings is optional. Prefer leaving it blank until HTTPS works. If you set `https://hostname` before the cert binding exists, the UI can break; ColdAisle will fall back to the live HTTP origin for links until HTTPS is actually used.
- `web.config` includes a disabled HTTP→HTTPS rewrite rule if you prefer enforcing TLS at IIS instead of in the app.

## Authentication

### Local

Always available after install. Managed under **Users**.

### LDAPS

1. Enable PHP `ldap` extension.
2. **Settings → LDAPS**: host, port `636`, **organization-wide** base DN (e.g. `DC=contoso,DC=com`), bind account, user filter.
3. **Users & Depts → Security group → role mapping**: map AD groups to roles. First-time logins create accounts only when the user is in any mapped group (recommended; keep **Require security group mapping** enabled).
3. Users authenticating via AD are auto-provisioned with the default role (Viewer unless changed).

### Microsoft Entra ID

1. Entra admin center → **App registrations** → New registration.
2. Add redirect URI: `https://your-host/login_entra.php` (Web).
3. Create a client secret.
4. API permissions: Microsoft Graph delegated `openid`, `profile`, `email` (or use default OIDC scopes).
5. **Settings → Microsoft Entra ID**: tenant ID, client ID, secret, redirect URI → Enable.
6. Login page shows **Sign in with Microsoft Entra ID**.

## Using the platform

1. **Data Centers** — sites → data centers → rooms (dimensions in meters).
2. **Floor Planner** — select room, drag a cabinet template onto the canvas, set U-height / mm size / rotation.
3. **Cabinets → Rack View** — click empty U-slots to add devices; color-coded by type; attach rack PDUs.
4. **Devices** — full inventory fields; auto-create data/power port labels; map power supplies to PDU outlets.
5. **Power** — zones (A/B feeds), panels, rack/row PDUs (phase layout, SNMPv3, outlet inventory, templates).
6. **Cabling** — connect ports; define overhead/underfloor routes.
7. **SNMP** — Discover / OID templates, enable scheduled poll per PDU, register the Windows task (below).
8. **Disposals / Audits / Reports** — lifecycle and compliance.

## SNMP polling & schedule

ColdAisle **never elevates** and **does not** create or edit Windows Task Scheduler jobs from the web UI.

### Package (ships with the app)

| File | Role |
|------|------|
| `scripts/poll_snmp.php` | Worker: due targets, digests, optional housekeeping |
| `scripts/run_poll_snmp.cmd` | **Task launcher** — `php -n` + explicit extensions, MIB env, health mode |
| `scripts/health_cli.php` | Fast CLI health (no snmp.dll) for task registration checks |
| `scripts/Register-ColdAisle-SnmpPollTask.ps1` | Elevated register/update/unregister of the OS task |
| `scripts/Enable-ColdAisle-Snmp.ps1` | Optional: enable `extension=snmp` for **IIS/web** Discover |
| `scripts/Fix-ColdAisle-SnmpTask.ps1` | Small repair helper if a task was registered incorrectly |
| `config/snmp_oid_templates.json` | Built-in vendor OID template seeds |

### Recommended setup

1. **Settings → SNMP schedule** — enable polling and set the app interval (e.g. 5 minutes).  
2. Download **Register-ColdAisle-SnmpPollTask.ps1** from that page (paths filled in for this site) and run it **elevated** on the app server (one-time).  
   - Or: `.\Install-ColdAisle.ps1 -RegisterSnmpTask` /  
     `.\scripts\Register-ColdAisle-SnmpPollTask.ps1 -SiteRoot 'C:\inetpub\wwwroot\ColdAisle'`.  
3. Prefer a **1-minute** OS tick; the worker **skips** targets that are not due yet.  
4. Per PDU/device: turn **Scheduled poll** on (needs site OID template + IP + credentials).  
5. Optional for **in-browser Discover**: run `scripts\Enable-ColdAisle-Snmp.ps1` (elevated) so IIS PHP loads `snmp`.

### What the scheduled task actually runs

```text
Program:   C:\Windows\System32\cmd.exe
Arguments: /c "C:\inetpub\wwwroot\ColdAisle\scripts\run_poll_snmp.cmd"
Trigger:   Every 1 minute (recommended)
User:      SYSTEM (needs Modify on storage\ for logs/locks)
```

Do **not** point Task Scheduler at bare `php.exe …\poll_snmp.php` with a full `php.ini` that loads `snmp` at process start — on Windows that often hangs during Net-SNMP MIB init. The `.cmd` launcher avoids that.

Manual one-shot:

```powershell
C:\inetpub\wwwroot\ColdAisle\scripts\run_poll_snmp.cmd
C:\inetpub\wwwroot\ColdAisle\scripts\run_poll_snmp.cmd --health
```

## Directory layout

```text
ColdAisle/
├── Install-ColdAisle.ps1  One-shot public installer (GitHub release → IIS/PHP/deploy)
├── setup.php              Web installer / restore
├── index.php              Dashboard
├── login.php / logout.php
├── login_entra.php        Entra OIDC callback
├── web.config             IIS hardening
├── VERSION                Semver (synced with App::VERSION)
├── BACKLOG.md             Deferred work (do not implement until requested)
├── api/                   JSON APIs (cabinets, devices, floorplan, power, SNMP, …)
├── assets/css|js          UI, 3D, floor planner, power charts
├── config/                config.php (generated), sample, snmp_oid_templates.json
├── includes/              layout, power helpers
├── pages/                 App modules
├── scripts/
│   ├── Install-ColdAisle-Prereqs.ps1
│   ├── poll_snmp.php
│   ├── run_poll_snmp.cmd
│   ├── health_cli.php
│   ├── Register-ColdAisle-SnmpPollTask.ps1
│   ├── Enable-ColdAisle-Snmp.ps1
│   └── README-Prereqs.md
├── sql/schema.sql         Full SQL Server schema
├── src/                   PHP core (Auth, DB, services)
└── storage/               logs, uploads, backups, snmp/mibs, tmp (runtime)
```

## Security checklist

- [ ] Use HTTPS in production; enable **Settings → Security → Force HTTPS** after the cert is bound  
- [ ] Optionally enable HSTS once HTTPS is stable  
- [ ] Confirm session cookie is HttpOnly + Secure (when on HTTPS); idle/absolute timeouts under Settings → Security  
- [ ] Keep `app_key` in `config/config.php` backed up (encrypts SNMP secrets at rest)  
- [ ] Restrict SQL login to least privilege (db_owner on ColdAisle DB is enough for setup; later `db_datareader`/`db_datawriter` + execute if you tighten)  
- [ ] Protect `config/config.php` (not web-accessible; contains secrets)  
- [ ] Rotate the admin password after first login  
- [ ] Prefer Entra/LDAPS over shared local accounts  
- [ ] Keep `storage/backups/` private (site packages and pre-update zips)  

## Migrating from openDCIM

ColdAisle is a **new** codebase optimized for IIS/SQL Server rather than a line-by-line port. Typical migration approach:

1. Export openDCIM inventory (devices, cabinets, departments) via SQL or CSV.
2. Recreate site → DC → room hierarchy in ColdAisle.
3. Place cabinets on the floor plan (or insert via API `api/cabinets.php`).
4. Import devices with cabinet + `position_u` + port counts.
5. Rebuild power and cable maps as needed.

A dedicated bulk import UI can be added later; the REST-style APIs under `api/` support automation today.

## License

Internal / organizational use. Align with your policy when redistributing.
