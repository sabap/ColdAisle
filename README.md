# ColdAisle

**Data Center Infrastructure Management** — free & open source.  
Primary platform: **IIS + PHP + Microsoft SQL Server** on Windows (with a clean path toward other stacks later).

Formerly known as **WinDCIM**. Built as a modern replacement path for environments that outgrew or cannot maintain Linux-based [openDCIM](https://github.com/opendcim/openDCIM), with first-class support for local accounts, **LDAPS**, and **Microsoft Entra ID (Azure AD) SSO**.

**Current version:** see [`VERSION`](VERSION).

## Support / donate

ColdAisle is free to use. If it helps your datacenter and you want to support development, optional donations via PayPal are welcome (no paywall, no accounts):

**[Donate with PayPal](https://paypal.me/)** ← replace with your PayPal.me link in the repo About / this README once you create one.

In a running install: **Settings → Support ColdAisle** (configure your PayPal URL under **General**).

## Updates (GitHub)

Admins can check for new versions and apply them from **Settings → Updates** against this public repo — **no GitHub token required**.

1. **Check for updates** · when a newer tag exists, **Update to vX.Y.Z** backs up to `storage/backups/`, downloads the release zipball, preserves config & storage runtime data, and runs schema ensure.
2. Optional PAT only if you hit API rate limits or point updates at a private fork.

Dashboard shows a banner when an update is available (if auto-check is enabled).

## Features

| Area | Capabilities |
|------|----------------|
| **Auth** | Local passwords, LDAPS (Active Directory), Microsoft Entra OIDC SSO |
| **Setup** | Browser wizard creates DB, schema, and admin account |
| **Dashboard** | Capacity metrics + interactive **3D** rack floor view (Three.js) |
| **Floor planner** | Drag-and-drop cabinets onto room canvas; 2D plan + 3D toggle |
| **Cabinets** | Rectangular models by width/depth (mm) and U-height; rack elevation |
| **Devices** | Manual entry, U-slot assignment, conflict checks, interface labels |
| **Power** | Power zones, panels, row/rack PDUs, outlet inventory, SNMPv3 fields |
| **Cabling** | Port-to-port cables, media types, cable tray/underfloor routes |
| **SNMP** | SNMPv3 targets, OID maps, poll worker script, PDU readings |
| **Lifecycle** | Disposal workflow with notifications |
| **Audits** | Physical audit jobs + system audit trail |
| **Reports** | Inventory, utilization, power, warranty, cables, orphans, audits |
| **RBAC** | Administrator, Operator, Auditor, Viewer roles |

## Requirements

- **Windows Server** (or Windows 10/11) with **IIS**
- **PHP 8.0+** (FastCGI) with extensions: `pdo`, `json`, `mbstring`
  - Database: `pdo_sqlsrv` **or** `pdo_odbc` + **ODBC Driver 17/18 for SQL Server**
  - Optional: `ldap` (LDAPS), `snmp` (polling), `curl` (Entra SSO)
- **Microsoft SQL Server** 2016+ (Express is fine)

### Suggested PHP (Windows) packages

1. Install [PHP for Windows](https://windows.php.net/download/)
2. Install [Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server) (`php_pdo_sqlsrv.dll`)
3. Or install [ODBC Driver 18 for SQL Server](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server) and enable `pdo_odbc`

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
- For HTTPS (required for Entra SSO in production), bind a certificate on the site.

## Authentication

### Local

Always available after install. Managed under **Users**.

### LDAPS

1. Enable PHP `ldap` extension.
2. **Settings → LDAPS**: host, port `636`, base DN, bind account, user filter.
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
3. **Cabinets → Rack View** — click empty U-slots to add devices; color-coded by type.
4. **Devices** — full inventory fields; auto-create data/power port labels.
5. **Power** — zones (A/B feeds), panels, rack/row PDUs with SNMPv3 credentials.
6. **Cabling** — connect ports; define overhead/underfloor routes.
7. **SNMP** — targets + schedule `scripts/poll_snmp.php`.
8. **Disposals / Audits / Reports** — lifecycle and compliance.

## SNMP poll schedule (Task Scheduler)

```text
Program:  C:\php\php.exe
Arguments: C:\inetpub\wwwroot\ColdAisle\scripts\poll_snmp.php
Trigger:  Every 5 minutes
```

Run whether user is logged on; use an account that can read `config\config.php`.

## Directory layout

```text
ColdAisle/
├── setup.php              Web installer
├── index.php              Dashboard
├── login.php / logout.php
├── login_entra.php        Entra OIDC callback
├── web.config             IIS hardening
├── api/                   JSON APIs (cabinets, devices, floorplan, power, …)
├── assets/css|js          UI, 3D, floor planner
├── config/                config.php (generated), sample
├── includes/layout.php
├── pages/                 App modules
├── scripts/poll_snmp.php
├── sql/schema.sql         Full SQL Server schema
├── src/                   PHP core (Auth, DB, services)
└── storage/logs
```

## Security checklist

- [ ] Use HTTPS in production  
- [ ] Restrict SQL login to least privilege (db_owner on ColdAisle DB is enough for setup; later `db_datareader`/`db_datawriter` + execute if you tighten)  
- [ ] Protect `config/config.php` (not web-accessible; contains secrets)  
- [ ] Rotate the admin password after first login  
- [ ] Prefer Entra/LDAPS over shared local accounts  

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
